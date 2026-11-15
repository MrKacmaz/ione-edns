# Backlog

This file lists planned work and deliberately deferred work, with the reason for each deferral.
Add an item when you postpone something. Remove the item when it ships.

## Planned

- [ ] **Deployment.** CI publishes the images to GitHub Container Registry and creates releases, but nothing deploys them. A target (a server with Docker Compose, Kubernetes or Laravel Cloud) needs its own runtime configuration, not the Compose defaults. It needs real secrets, the public Reverb host in the frontend build, and sized Horizon supervisors. PostgreSQL must accept their connections (about 105 with the production pools, above the default `max_connections` of 100): raise the limit or add a pooler such as PgBouncer.
- [ ] **Multi-platform images.** The published images are `linux/amd64` only. Add `linux/arm64` when a target needs it; QEMU builds are much slower.

## Deferred

### Data retention

The system never deletes notifications or batches, but it must send millions of notifications a day. Idempotency keys already expire after `notifications.idempotency.retention_hours` (24 by default), and `model:prune` removes them daily.

- **Retention period.** Define how long notifications and batches are kept, for example 90 days. Then delete older rows with a scheduled `MassPrunable` model, or partition `notifications` by month and drop old partitions. Partitions avoid large `DELETE` statements on the busiest table.
- **Archive.** Decide whether finished notifications go to an archive (for example object storage) before deletion.

### Soft-deleted users

- **Open notifications of a deleted user.** Decide what happens to a deleted user's `waiting`, `scheduled` and `queued` notifications. Either cancel them when the user is deleted, or let the worker skip them.
- **Email address stays taken.** The unique index on `users.email` includes soft-deleted users. Thus nobody can register again with a deleted user's email address.

### Authorization

- **Operator management.** Only the seeder or a manual update sets `users.is_operator`. No screen or command grants or revokes it. In production the seeder creates no user, so the first operator must be created by hand (for example with `php artisan tinker`). A `users:create-operator` command would make this safe and repeatable.
- **Multi-tenancy** is out of scope. `user_id` already scopes notifications to the token's owner. A later tenant scope can start there.

### API documentation

- **Generated Postman collection.** The collection generated from the spec (`postman/collections/EDNS API`) uses one example `Idempotency-Key` for all create requests, so a second send replays the first response. The generator also fills every optional query parameter with a placeholder. `composer postman:sync` trims the two list requests afterwards, but a new list endpoint needs the same step. The curated `EDNS Smoke Flow` collection is the one to run.

### Delivery details

- **Multipart SMS.** An SMS is limited to one message: 160 GSM-7 septets (extension characters count 2), or 70 UTF-16 units once a character needs UCS-2. Multipart messages (153 or 67 per part) need provider support and a decision on how many parts a notification may use.
- **No `delivered` status.** The provider only confirms with `202 accepted`. Provider delivery receipts (a callback webhook) would add this status.
- **Broadcast volume.** Every status change queues one WebSocket broadcast on the `broadcasts` queue (four or five per notification), also when nobody listens. At millions of notifications a day, consider one of these: broadcast only to users with an open connection (Reverb's channel occupancy), batch the updates per user, or go back to final statuses only.
- **System-wide live feed.** The dashboard feed shows only the signed-in operator's own notifications. A system-wide feed needs sampling or aggregation to stay readable at full volume.

### API behavior

- **Empty batches.** A batch whose notifications all reuse existing idempotency keys still creates a new, empty batch (`total` 0, `is_finished` true). Each notification keeps its original batch. Decide whether such a request should answer without a new batch.
- **Fairness between accounts.** The API limit counts requests per account (600 a minute), not notifications. Thus one account can queue up to 600,000 notifications a minute in batches. All accounts share the same queues: one account's large batches delay the other accounts' notifications of the same channel and priority. A per-account quota of notifications per minute and fair scheduling across accounts (for example a queue per account and priority, worked round-robin) would limit this.
- **`/health` is not in the OpenAPI document.** The document describes only the versioned API under `/api/v1`. The README describes `/health`.
- **Template deleted during creation.** A template can be deleted while a notification is created from it. The insert then fails on the foreign key and answers `500` instead of `422`. A lock on the named templates (`FOR SHARE`) during creation would close this gap. The window is a few milliseconds.

### Performance at volume

- **Metrics queries.** The metrics run `count(*)` and `percentile_cont` over the 5-minute and 1-hour windows of `notifications` and `notification_attempts`. The endpoint, the dashboard and the broadcast share one snapshot, cached for five seconds (`notifications.metrics.cache_seconds`). Thus the queries run at most once per cache period. At millions of rows a day, Redis counters and latency histograms, updated at delivery, would replace them.
- **Large idempotency records.** The `Idempotency-Key` record stores the full response, which reaches several megabytes for a batch of 1000. Store the batch id and rebuild the response on replay to keep the rows small.

### Operations

- **webhook.site as the provider.** The provider is a free webhook.site URL in `NOTIFICATIONS_GATEWAY_URL` (local `.env` only); without it, Docker Compose delivers to its mock provider. Create the URL with `php artisan notifications:webhook-site`, which gives each request its own `messageId`. A free URL expires after seven days, and its inbox shows a limit of 50 requests, which can cap or rotate what a load test sends. Create a new URL when it expires, and use a paid URL or the Compose mock provider for load tests.
- **OpenTelemetry pins.** The packages are pinned (`open-telemetry/sdk` ~1.15.0, `exporter-otlp` ~1.4.0, `sem-conv` 1.44.\*). `opentelemetry-auto-laravel` 1.10.0, the latest release, reads `MessagingIncubatingAttributes::MESSAGING_SYSTEM` when a job is pushed, and `sem-conv` 1.45 removed it. The hook then fails before it opens its span, but it still closes one: the caller's. Requests that queue jobs lose their trace context, and delivery attempts lose their spans. Remove the pins when a fixed `auto-laravel` is released; `tests/Unit/OpenTelemetryCompatibilityTest.php` fails if the constant is missing again.
- **phpredis only.** The GCRA rate limiter and the circuit breaker require the phpredis client (`REDIS_CLIENT=phpredis`). They do not support predis.
- **Lost queued jobs.** A job can be lost from Redis, for example in a restart without persistence. The stale sweep pushes it again only after 25 hours (`notifications.delivery.stale_after_seconds.queued`), because a queued job can correctly wait through its one-day retry window. Docker Compose runs Redis with AOF persistence, which makes such losses rare; a production Redis needs the same.
- **Jobs give up after one day.** A delivery job stops one day after its dispatch (`retryUntil`), for example while its channel's circuit stays open. Its notification then fails, and `POST /notifications/{id}/retry` can retry it. Decide whether such notifications should retry automatically when the circuit closes.
- **Queue age metric.** `oldest_pending_age_seconds` counts from the first push of a job, so it includes time that the job was delayed or released. A "ready for" age needs the time a job became available, which Laravel's Redis queue does not record.
- **Three circuits, one provider URL.** Every channel has its own circuit, but all channels currently share one provider URL. Thus an outage of that provider opens the three circuits one after the other.
- **Send slot before the claim.** The rate limiter reserves a send slot before the job claims the notification. Thus a job with nothing to send (a cancelled notification, a duplicate job) still uses a slot. A claimable check before the reservation costs one database read per job; a reservation after the claim needs the claim undone when the slot is far away.
- **Low-traffic provider failures.** With fewer than 20 calls a minute, a circuit cannot open. Thus a provider URL that stopped working fails low-traffic notifications after their five attempts, without an alert. After the fix, `POST /api/v1/notifications/{id}/retry` retries them one at a time. An alert on the metrics' `failure_rate` would catch the problem sooner. A bulk retry would resend them in one step.
- **Image size.** The app image is about 860 MB, mostly the Debian-based `php:8.5-fpm` with its compiled extensions. An Alpine base would make it smaller. Development dependencies are only in the separate `tests` image.
- **Production settings.** Docker Compose runs with `APP_ENV=local`, so Horizon uses the small local worker counts. A production deployment needs `APP_ENV=production` and real secrets instead of the generated ones in the `secrets` volume. Size `HORIZON_NOTIFICATION_WORKERS` to the provider latency: about 30 workers per channel for 100 messages a second at 300 ms.
- **Tracing sampling.** Tracing every notification is expensive at production volume. Add sampling (for example 10%) and an OpenTelemetry Collector in front of the tracing backend.
- **`/health` throttling.** `/health` is public and has no throttle, and every call runs its checks: a database query, a Redis ping and Horizon's status. Its answers show nothing internal, but an exposed deployment should throttle it or cache its result for a few seconds.
- **Metrics per channel.** The metrics report the success rate, the failure rate, the attempt counts and the latency percentiles for all channels together. Only the queue depth and the circuit state are per channel. Attempt queries grouped by channel would show, for example, that only SMS is slow.
