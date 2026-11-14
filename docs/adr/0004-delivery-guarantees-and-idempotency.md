# ADR 0004: Delivery guarantees and idempotency in three layers

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

A notification must be sent once, even though:

- API clients retry requests after timeouts, and may resend a notification they already submitted;
- the database and the queue are two systems, so a job can be pushed for a row that was never committed, or a row can be committed without its job (Redis down, process killed);
- a worker can die in the middle of a delivery, and a job can run twice.

Exactly-once delivery across a network is not achievable; the realistic goal is at-least-once delivery to the provider with duplicates suppressed wherever we can detect them.

## Decision

**The database is the source of truth, with `waiting` as an outbox state.**

- `CreateNotificationsAction` inserts the notifications in one transaction with status `waiting` (or `scheduled`).
- The jobs are pushed in `DB::afterCommit()`, so a worker never looks for a row that is not visible yet. A failed push is reported and left to the sweep below; the request still answers `202`, because the notifications are stored.
- `DispatchNotificationsAction` pushes each job first and marks the notification `queued` second. If the push fails, the notification stays `waiting`.
- `notifications:redispatch-stale` runs every minute. It pushes `waiting` notifications older than two minutes again, and puts `processing` notifications untouched for five minutes back to `waiting` and pushes them again. `queued` notifications get the same treatment after 25 hours, past the one-day retry window of their job, which covers a job lost from Redis.
- When a job gives up for good (timeout, one day of retries), `SendNotificationJob::failed()` fails a notification that is still `queued` or `processing`, so none is left behind without a job.

**Three idempotency layers.**

1. **Request level:** the `Idempotency-Key` header, following the IETF draft. The first response is stored for 24 hours and replayed with `Idempotent-Replayed: true`. The key is bound to a fingerprint of the payload, where reordered JSON keys count as the same payload. A different payload with the same key answers `422`, a request while the first is still running answers `409`, and an empty or too long key answers `400`. Keys are scoped per user.
2. **Notification level:** an optional `idempotency_key` per notification, unique per user. A repeated key with the same notification returns the existing one (`200` instead of `202`) and queues nothing; with a different channel, recipient, content, priority or schedule it answers `422` at that notification's position, so a new message is never dropped silently. Concurrent inserts are resolved with `insert … on conflict do nothing` followed by a lookup.
3. **Delivery level:** a worker claims a notification with one conditional `UPDATE` (`waiting`/`queued` → `processing`). A second job for the same notification, or a job for a cancelled one, claims nothing and is skipped. The attempt record and the status change after the provider call are written in one transaction. The provider receives `Idempotency-Key: {notification id}`, so a provider that honours it drops a resend after a worker died mid-request.

## Considered options

1. **Push the job inside the transaction.** A rollback leaves a job for a row that never existed, and a worker can run before the commit.
2. **A separate outbox table and relay process.** The textbook pattern; here the notification row already carries the state, so a status value plays the same role without a second table.
3. **Only one idempotency layer.** Each layer catches a different duplicate: client retries of a request, resubmissions of a notification, and duplicate jobs.

## Consequences

**Positive**

- No notification is lost between the database and Redis: a missing push is repaired within a few minutes.
- Duplicates are suppressed at the API, at notification creation and at delivery.
- Every status change is a single conditional statement, so cancellation and delivery cannot both win.

**Negative**

- Delivery to the provider is at-least-once: if a worker dies after the provider accepted a message but before recording it, the message is sent again, and only a provider that honours the idempotency key can drop it.
- A job lost from Redis while its notification is `queued` (a Redis restart without persistence) is only recovered by the sweep after 25 hours. Docker Compose runs Redis with append-only persistence for this reason.
- Stored idempotent responses take space for 24 hours; `model:prune` removes them daily.
