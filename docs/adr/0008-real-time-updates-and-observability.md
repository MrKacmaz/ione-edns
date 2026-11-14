# ADR 0008: Real-time updates and observability

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

Both internal teams and API consumers need visibility into delivery status: consumers want to learn the outcome of their notifications without polling, operators want live queue and success numbers and to know when a provider is failing, and anyone debugging needs to follow one request through the API, the queue and the provider call.

At millions of notifications a day, broadcasting every status change would multiply the WebSocket traffic, and per-notification metrics queries would not scale.

## Decision

**WebSocket updates (Laravel Reverb).**

- `NotificationStatusChanged` is broadcast on the owner's private channel `App.Models.User.{id}` when a notification reaches a final status: `sent`, `failed` or `cancelled`. Intermediate statuses are not broadcast. The payload is captured when the event is created and leaves out the content, and the event is sent after the transaction commits from a separate `broadcasts` queue, so delivery workers are not slowed down.
- API clients authorize their channel at `/api/v1/broadcasting/auth` with their Sanctum token; the dashboard uses `/broadcasting/auth` with its session.
- Operators (`users.is_operator`, the `operate` gate) get a live dashboard (Inertia, Vue and Echo) on the private `operators` channel. A metrics snapshot is broadcast every five seconds by the scheduler (`metrics:broadcast`), and circuit changes are broadcast the moment they happen.

**Correlation and tracing.**

- `AssignRequestContext` accepts a UUID in `X-Correlation-ID` (or creates a UUIDv7), echoes it in the response and puts it into Laravel's `Context`. Context is copied into every log entry and every queued job. The id is also stored on the notification and sent to the provider.
- `DispatchNotificationsAction` gives every delivery job its notification's id and stored correlation id as context when it pushes the job. Jobs pushed by the stale sweep or the scheduler, which run outside any request, thus log both ids from the start, including waits before the notification is claimed.
- OpenTelemetry auto-instrumentation traces requests, queries, HTTP calls and jobs to Jaeger. Each delivery job starts its own trace linked to the request's trace (`TracingLinked`), so a batch of 1000 yields 1000 small traces rather than one huge one. The provider call carries the job's trace context in the W3C `traceparent` header, so a provider that traces too continues the trace.

**Metrics and health.**

- `GET /api/v1/metrics` (operators only) reports queue depth per priority from Redis, notifications per unfinished status, and, over five-minute and one-hour windows from `notification_attempts`, attempts per outcome, success rate and p50/p95/p99 provider and end-to-end latency. It also reports each channel's circuit state. The snapshot is cached for five seconds.
- `GET /health` (public) runs tagged checks for the database, Redis, Horizon (running and not paused), the gateway URL and the delivery circuits. It answers `200` with `ok`, `200` with `warn` while a circuit is open, and `503` with `fail`.
- Logs are structured JSON with the notification and correlation ids.

## Considered options

1. **Broadcast every status change.** Three or four events per notification, most of them uninteresting to clients.
2. **Polling only.** Simple, but pushes the load onto the API and delays outcomes.
3. **A system-wide live feed of all notifications on the dashboard.** Unreadable at full volume; the dashboard shows aggregates and the operator's own notifications instead.
4. **Metrics in a separate store (Prometheus pushgateway, StatsD).** More moving parts; computing them from the delivery history keeps one source of truth.

## Consequences

**Positive**

- Consumers learn outcomes in real time; operators see queues, rates, latency and circuits update without refreshing.
- One correlation id ties together a request, its jobs, its log lines, its trace and the provider call.
- `/health` separates "down" from "degraded", so a load balancer keeps sending traffic while one channel is paused.

**Negative**

- Every final status still costs one queued broadcast job, even when nobody is listening.
- The metrics queries scan the attempts of the last hour; very high volume needs pre-aggregated counters.
- Tracing every notification is expensive in production and needs sampling.
