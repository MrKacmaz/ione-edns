# Backlog

Work that is planned or was deliberately deferred, with the reason it was deferred.
Add an item here whenever something is postponed, and remove it once it ships.

## Planned

- [ ] **Deployment** — CI publishes the images to GitHub Container Registry and creates releases, but nothing deploys them. A target (a server with Docker Compose, Kubernetes or Laravel Cloud) needs runtime configuration instead of the Compose defaults: real secrets, the public Reverb host baked into the frontend build, sized Horizon supervisors, and a PostgreSQL that takes their connections (about 105 with the production pools, above the default `max_connections` of 100), through a higher limit or a pooler such as PgBouncer.
- [ ] **Multi-platform images** — the published images are `linux/amd64` only; add `linux/arm64` once a target needs it (QEMU builds are much slower).

## Deferred

### Data retention

Notifications and batches are never deleted today, while the system is expected to send millions of notifications a day.

- Define how long notifications and batches are kept (for example 90 days) and delete older rows with a scheduled `MassPrunable` model, or partition `notifications` by month and drop old partitions, which avoids large `DELETE` statements on the hottest table.
- Decide whether finished notifications are archived (for example to object storage) before they are deleted.
- Idempotency keys are already covered: they expire after `notifications.idempotency.retention_hours` (24 by default) and `model:prune` removes them daily.

### Soft deleted users

- Decide what happens to a deleted user's `waiting`, `scheduled` and `queued` notifications: cancel them when the user is deleted, or let the worker skip them.
- The unique index on `users.email` still includes soft deleted users, so their email address cannot be registered again.

### Authorization

- Operators are marked with `users.is_operator`, which only the seeder or a manual update sets. There is no screen or command to grant or revoke it. In production the seeder creates no user at all, so the first operator has to be created by hand (for example with `php artisan tinker`); a `users:create-operator` command would make that safe and repeatable.
- Multi-tenancy is out of scope. Notifications are already scoped to the token's owner through `user_id`, which is the seam for a tenant scope later.

### API documentation

- The Postman collection generated from the spec (`postman/collections/EDNS API`) shares one example `Idempotency-Key` between its create requests, so sending one twice replays the first response. The generator also fills every optional query parameter with a placeholder; `composer postman:sync` trims the two list requests afterwards, but a new list endpoint needs the same step. The curated `EDNS Smoke Flow` collection is the runnable one.

### Delivery details

- The SMS content limit is a flat 160 characters. Real SMS segments hold 160 GSM-7 characters but only 70 UCS-2 characters (for example Turkish characters such as `ş`), so multipart messages and per-encoding limits need a decision.
- There is no `delivered` status because the provider only acknowledges with `202 accepted`. Supporting provider delivery receipts (a callback webhook) would add it.
- Every notification that reaches a final status queues one WebSocket broadcast on the `broadcasts` queue, also when nobody listens. At millions of notifications a day, consider broadcasting only to users with an open connection (Reverb's channel occupancy) or batching updates per user.
- The dashboard's live feed shows the signed-in operator's own notifications; a system-wide feed would need sampling or aggregation to stay readable at full volume.

### API behavior

- A batch whose notifications all reuse existing idempotency keys still creates a new, empty batch (`total` 0, `is_finished` true), while each notification keeps its original batch. Decide whether such a request should answer without creating a batch.
- The API limit counts requests per account (600 a minute), not notifications, so one account may queue up to 600,000 notifications a minute in batches, and all accounts share the same queues: one account's large batches delay the others' notifications of the same channel and priority. A notifications-per-minute quota per account, and fair scheduling across accounts (for example a queue per account and priority, worked round-robin), would bound that.
- `/health` is not part of the OpenAPI document, which describes the versioned API under `/api/v1` only; it is described in the README.
- A template deleted while a notification is being created from it makes the insert fail on the foreign key, which answers `500` instead of `422`. Locking the named templates (`FOR SHARE`) while the notifications are created would close that gap; the window is a few milliseconds.

### Performance at volume

- The metrics run `count(*)` and `percentile_cont` over the 5-minute and 1-hour windows of `notifications`. The snapshot is cached for five seconds (`notifications.metrics.cache_seconds`) and shared by the endpoint, the dashboard and the broadcast, so the queries run at most once per cache period; at millions of rows a day, Redis counters and latency histograms updated at delivery would replace them.
- A batch is pushed onto the queue one job at a time, so a batch of 1000 makes 1000 Redis round trips inside the request. Pushing the jobs in one Redis pipeline would cut that to one.
- The `Idempotency-Key` record stores the full response, which reaches several megabytes for a batch of 1000. Storing the batch id and rebuilding the response on replay would keep the rows small.

### Operations

- The provider is a free webhook.site URL set in `NOTIFICATIONS_GATEWAY_URL` (local `.env` only); without it, Docker Compose delivers to its mock provider. It answers every request with the same static `202` body, so every notification gets the same `messageId`; it expires after seven days, and its inbox shows a limit of 50 requests, which may cap or rotate what a load test can send. Create a new URL when it expires, and use a paid URL or a local stub provider for load tests.
- The GCRA rate limiter requires the phpredis client (`REDIS_CLIENT=phpredis`); predis is not supported.
- A notification whose job is lost from Redis (for example a restart without persistence) is pushed again by the stale sweep only after 25 hours (`notifications.delivery.stale_after_seconds.queued`), because a queued job may legitimately wait through its one-day retry window. Docker Compose runs Redis with AOF persistence, which makes such losses rare; a production Redis needs the same.
- The half-open probe of a circuit is taken before the rate limiter. When the rate limiter then releases the probe job, the probe stays taken until its 20-second timeout (`notifications.circuit_breaker.probe_timeout_seconds`) passes, so the channel recovers up to 20 seconds later. Handing the probe back when the job is released would remove the wait.
- A delivery job gives up one day after it was dispatched (`retryUntil`), for example while a channel's circuit stays open; its notification then fails and can be retried through `POST /notifications/{id}/retry`. Decide whether such notifications should be retried automatically once the circuit closes.
- The queue metric `oldest_pending_age_seconds` counts from when a job was first pushed, so it includes time the job spent delayed or released back. A "ready for" age would need the time a job became available, which Laravel's Redis queue does not record.
- Every channel has its own circuit, although all channels currently share one provider URL, so an outage of that provider opens the three circuits one by one.
- A send slot of the rate limiter is reserved before the notification is claimed, so a job that turns out to have nothing to send (a cancelled notification, a duplicate job) still uses a slot. Checking that the notification is claimable before reserving costs a database read per job; reserving after the claim would need the claim undone when the slot is far away.
- With fewer than 20 calls a minute a circuit cannot open, so a provider URL that stopped working fails low-traffic notifications after their five attempts without an alert. Once the URL is fixed they are retried one by one with `POST /api/v1/notifications/{id}/retry`; an alert on the failure rate would catch it sooner, and a bulk retry would resend them in one step.

- The app image is about 860 MB, mostly the Debian-based `php:8.5-fpm` with its compiled extensions; an Alpine base would shrink it. Development dependencies live only in the separate `tests` image.
- Docker Compose runs with `APP_ENV=local`, so Horizon uses the small local worker counts. A production deployment needs `APP_ENV=production`, `HORIZON_NOTIFICATION_WORKERS` sized to the provider latency (about 30 workers per channel for 100 messages a second at 300 ms) and real secrets instead of the generated ones in the `secrets` volume.
- Tracing every notification is expensive at production volume. Add sampling (for example 10%) and an OpenTelemetry Collector in front of the tracing backend.
- `/health` is public and unthrottled, and every call runs its checks (a database query, a Redis ping, Horizon's status). Its answers reveal nothing internal, but an exposed deployment should throttle it or cache its result for a few seconds.
- The metrics report the success rate and the latency percentiles for all channels together; only the queue depth and the circuit state are per channel. Grouping the attempt queries by channel would show, for example, that only SMS is slow.
