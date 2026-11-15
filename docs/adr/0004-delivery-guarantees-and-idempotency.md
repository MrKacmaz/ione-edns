# ADR 0004: Delivery guarantees and idempotency in three layers

- **Status:** Accepted
- **Date:** 2026-11-14

The database is the source of truth: a notification is stored as `waiting` before its job is pushed, and a sweep repairs lost pushes. Three idempotency layers suppress duplicates at the request, the notification and the delivery.

## Context

A notification must be sent once, but:

- API clients retry requests after timeouts. They can also send a notification again that they already submitted.
- The database and the queue are two systems. A job can be pushed for a row that was never committed. A row can be committed without its job (Redis down, process killed).
- A worker can stop during a delivery. A job can run twice.

Exactly-once delivery across a network is not possible. The realistic goal is at-least-once delivery to the provider, with duplicates suppressed where we can detect them.

## Decision

### The database as the source of truth, with `waiting` as the outbox state

- `CreateNotificationsAction` inserts the notifications in one transaction, with status `waiting` (or `scheduled`).
- It pushes the jobs in `DB::afterCommit()`. Thus a worker never looks for a row that is not visible yet.
- If the push fails, the error is reported and the sweep below repairs it. The request still answers `202`, because the notifications are stored.
- `DispatchNotificationsAction` pushes the jobs first, in one Redis pipeline per queue. Then it marks the notifications `queued`. If the push fails, the notifications stay `waiting`.

### Recovery

`notifications:redispatch-stale` runs every minute:

| Status       | Stale after                | Action                                                                                                     |
| ------------ | -------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `waiting`    | 2 minutes                  | Push the job again.                                                                                        |
| `processing` | 5 minutes without a change | Set the notification back to `waiting` and push the job again.                                             |
| `queued`     | 25 hours                   | Push the job again. This is after the one-day retry window of the job, so it covers a job lost from Redis. |

- Each push of a `processing` notification leads to a new claim, and each claim uses one attempt. A `processing` notification without attempts left fails. Its reason says that the provider may have received it.
- Thus a notification whose outcome can never be recorded is sent at most `max_attempts` times, not once per sweep forever. An example is a database that rejects the provider's answer. The provider's answer is then logged as `notification.delivery.unrecorded`.
- A job can give up for good (timeout, one day of retries). Then `SendNotificationJob::failed()` fails its notification if it is still `queued` or `processing`. Thus no notification stays without a job.
- A database or Redis outage fails no notification. The job has no limit on exceptions. Laravel runs it again after 10, 30, 60 and then every 120 seconds, until its one-day lifetime ends.
- An outage can still cause a second send. If the outage starts between the provider's answer and its record, the notification stays `processing`. The sweep then sends it again (see Consequences).
- An API request during such an outage gets `503` with `Retry-After`, not `500`.

### Three idempotency layers

1. **Request level: the `Idempotency-Key` header**, as in the IETF draft.
    - The first response is stored for 24 hours. A replay carries `Idempotent-Replayed: true`.
    - The key is bound to a fingerprint of the payload. Reordered JSON keys give the same fingerprint.
    - The same key with a different payload answers `422`. The same key while the first request still runs answers `409`. An empty or too long key answers `400`.
    - Keys are scoped per user.
    - A short lock gives the `409` quickly, but the guarantee comes from the database. One transaction writes the request's work and its stored response, under the unique `(user, key)` index.
    - A request that outlived the lock and lost the race to a retry is rolled back. It answers with the winner's response.
    - A process that stops after the commit cannot leave notifications without the key that replays them.
2. **Notification level: an optional `idempotency_key` per notification**, unique per user.
    - A repeated key with the same notification returns the existing notification (`200` instead of `202`). Nothing is queued.
    - A repeated key with a different channel, recipient, content, priority or schedule answers `422` at that notification's position. Thus a new message is never dropped silently.
    - Concurrent inserts use `insert … on conflict do nothing`, followed by a lookup.
3. **Delivery level: a conditional claim.**
    - A worker claims a notification with one conditional `UPDATE` (`waiting`, `scheduled` or `queued` → `processing`).
    - The claim succeeds only when the notification is due: not before its `next_attempt_at` after a failed attempt, and not before its `scheduled_at`.
    - A second job for the same notification, or a job for a cancelled notification, claims nothing and is skipped.
    - A job that comes early is released until the notification is due. Examples: a duplicate job during the backoff, or a delayed job that wakes a fraction of a second early.
    - One transaction writes the attempt record and the status change after the provider call.
    - The provider receives `Idempotency-Key: {notification id}`. A provider that honours it drops a resend after a worker stopped during the request.

## Considered options

1. **Push the job inside the transaction.** A rollback leaves a job for a row that does not exist. A worker can also run before the commit.
2. **A separate outbox table and relay process.** This is the textbook pattern. Here the notification row already carries the state, so a status value does the same work without a second table.
3. **Only one idempotency layer.** Each layer catches a different duplicate: client retries of a request, resubmissions of a notification, and duplicate jobs.

## Consequences

**Positive**

- No notification is lost between the database and Redis. The sweep repairs a missing push within a few minutes.
- Duplicates are suppressed at the API, at notification creation and at delivery.
- Every status change is one conditional statement. Thus cancellation and delivery cannot both win.

**Negative**

- Delivery to the provider is at-least-once. A worker can stop, or the database can go away, after the provider accepted a message but before the record. Then the message is sent again. Only a provider that honours the idempotency key can drop it. Each resend uses one attempt, so this happens at most `max_attempts` times.
- A job can be lost from Redis while its notification is `queued` (a Redis restart without persistence). Only the sweep recovers it, after 25 hours. For this reason, Docker Compose runs Redis with append-only persistence.
- Stored idempotent responses use space for 24 hours. `model:prune` removes them daily.
