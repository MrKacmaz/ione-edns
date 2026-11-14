# ADR 0008: Real-time updates and observability

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

Both internal teams and API consumers need visibility into delivery status: consumers want to learn the outcome of their notifications without polling, operators want live queue and success numbers and to know when a provider is failing, and anyone debugging needs to follow one request through the API, the queue and the provider call.

At millions of notifications a day, broadcasting every status change would multiply the WebSocket traffic, and per-notification metrics queries would not scale.

## Decision

- **Push only final statuses, on Laravel Reverb.** `NotificationStatusChanged` goes to the owner's private channel when a notification is `sent`, `failed` or `cancelled`, without its content. It is sent after the transaction commits and from a separate `broadcasts` queue, so delivery workers are not slowed down. API clients authorize with their Sanctum token, the dashboard with its session.
- **Give operators aggregates, not a feed.** Operators (`users.is_operator`) get a live dashboard on a private channel: a metrics snapshot every five seconds, every circuit change as it happens, and their own latest notifications.
- **One correlation id per request.** It is taken from `X-Correlation-ID` or created, carried through Laravel's `Context` into every log line and queued job, stored on the notification and sent to the provider. Jobs pushed later by the sweep or the scheduler get the stored id, so they log it from the start.
- **One trace per delivery.** OpenTelemetry auto-instrumentation traces requests, queries, HTTP calls and jobs. Each delivery job starts its own trace linked to the creating request, so a batch of 1000 yields 1000 small traces rather than one huge one, and passes its context to the provider in `traceparent`.
- **Metrics from the delivery history.** Queue depth comes from Redis; status counts, success rates and latency percentiles come from `notifications` and `notification_attempts`. The snapshot is cached for five seconds and shared by the endpoint, the dashboard and the broadcast. `/health` separates `ok`, `warn` (a circuit is open) and `fail`.

The endpoints and payloads are described in the README.

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
