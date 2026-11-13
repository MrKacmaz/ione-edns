# Backlog

Work that is planned or was deliberately deferred, with the reason it was deferred.
Add an item here whenever something is postponed, and remove it once it ships.

## Planned

- [ ] **Bonus features** — the WebSocket dashboard, circuit breaker based failure handling, and a fuller GitHub Actions pipeline.
- [ ] **Delivery** — move every service to Docker Compose, write the README and the decision records in `docs/`, and finalize the OpenAPI specification.

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

- The Horizon dashboard is closed in every environment except `local`. Grant access to operator accounts once the dashboard introduces them.
- `GET /api/v1/metrics` reports system-wide numbers to every token holder, so an API client can see the overall volume of all clients. Restrict it to operator accounts once they exist.
- Multi-tenancy is out of scope. Notifications are already scoped to the token's owner through `user_id`, which is the seam for a tenant scope later.

### API documentation

- The OpenAPI document lists `403` for another user's notification, batch or template, while the API answers `404`.
- A notification's `variables` is documented as an array of strings, while it is an object that maps variable names to text or number values.
- Document the `Idempotency-Key` header, its `400`, `409` and `422` responses and the `Idempotent-Replayed` response header.
- The batch request schema marks the whole `notifications` array as `uniqueItems`, while only each item's `idempotency_key` must be distinct.

### Delivery details

- The SMS content limit is a flat 160 characters. Real SMS segments hold 160 GSM-7 characters but only 70 UCS-2 characters (for example Turkish characters such as `ş`), so multipart messages and per-encoding limits need a decision.
- There is no `delivered` status because the provider only acknowledges with `202 accepted`. Supporting provider delivery receipts (a callback webhook) would add it.

### Testing

- The path where two concurrent requests create a notification with the same idempotency key is handled in `CreateNotificationsAction` but has no automated test.

### Continuous integration

- The GCRA rate limiter tests need Redis. Add a Redis service and the `redis` PHP extension to the tests workflow, next to the PostgreSQL service.

### Operations

- The webhook.site URL is not set up yet. Create it, make it answer `202` with a JSON `messageId`, and set `NOTIFICATIONS_GATEWAY_URL`; until then every attempt fails as retryable and the notifications end up failed.
- The GCRA rate limiter requires the phpredis client (`REDIS_CLIENT=phpredis`); predis is not supported.
- A notification whose job is lost from Redis (for example a restart without persistence) stays `queued`, because the stale-notification sweeper only covers `waiting` and `processing`. This includes scheduled notifications, whose delayed jobs wait in Redis for up to a minute. Enable Redis AOF persistence, or sweep `queued` notifications whose `queued_at` (or `scheduled_at`) is long past.

- The Docker image is about 1.1 GB because it includes development dependencies so that tests can run in the container. Add a slimmer production target.
- Tracing every notification is expensive at production volume. Add sampling (for example 10%) and an OpenTelemetry Collector in front of the tracing backend.
