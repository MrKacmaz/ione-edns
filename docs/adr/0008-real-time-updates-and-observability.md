# ADR 0008: Real-time updates and observability

- **Status:** Accepted
- **Date:** 2026-11-14

Laravel Reverb pushes every status change to the notification's owner, and operators get aggregate metrics on a live dashboard. One correlation id and one trace per notification connect the request, the queue and the provider call.

## Context

Internal teams and API consumers both need to see the delivery status:

- Consumers want the outcome of their notifications without polling.
- Operators want live queue and success numbers. They want to know when a provider fails.
- A person who debugs a problem must follow one request through the API, the queue and the provider call.

At millions of notifications a day, a broadcast of every status change multiplies the WebSocket traffic. Metrics queries per notification do not scale.

## Decision

### Every status change is pushed, on Laravel Reverb

- `NotificationStatusChanged` goes to the owner's private channel on every status change: created (`waiting` or `scheduled`), `queued`, `processing`, queued again for a retry, and the final `sent`, `failed` or `cancelled`.
- The payload does not include the content.
- The event is sent after the transaction commits, from a separate `broadcasts` queue, so it does not slow down delivery workers.
- Changes of many notifications at once (a batch created, queued or cancelled) go through `BroadcastStatusChangesAction`. It pushes their broadcast jobs together, in one Redis pipeline, without a tracing span per job.
- API clients authorize with their Sanctum token. The dashboard authorizes with its session.

### Operators get aggregates, not a feed

Operators (`users.is_operator`) get a live dashboard on a private channel. It shows a metrics snapshot every five seconds, every circuit change when it happens, and the operator's own latest notifications.

### One correlation id per request

- The API takes the id from `X-Correlation-ID`, or creates one.
- Laravel's `Context` carries it into every log line and queued job. The notification stores it, and the provider receives it.
- Jobs that the sweep or the scheduler pushes later get the stored id, so they log it from the start.

### One trace per notification

- OpenTelemetry auto-instrumentation traces requests, queries, HTTP calls and jobs.
- A notification stores the span that its delivery attempts belong to (`trace_id`, `span_id`). For a single notification, this is the creating request.
- Each notification of a batch gets its own short `notification.accepted` root span, linked to the request. A batch of 1000 thus gives 1000 small traces, not one very large trace.
- Every attempt runs in a `notification.deliver` span under the stored span. The provider call and the attempt's queries are its children. The provider receives the context in `traceparent`.
- A retry an hour later, a job pushed again by the sweep, or a scheduled send next week all go into the same trace.
- Jobs that make no attempt add nothing to the notification's trace. Each job still has its own trace, linked to the request, for the worker's view.
- `NotificationTracer` implements these rules.

### A resilient span exporter

PHP exports spans synchronously, so a tracing backend that does not answer would hold up requests and workers. `OTEL_TRACES_EXPORTER=otlp_resilient` selects our own exporter (`ResilientSpanExporter`):

- Each export has a timeout of 1 second (`OTEL_EXPORTER_OTLP_TIMEOUT=1000` in Docker Compose) and no retries.
- After a failed export, the process drops spans for 30 seconds, without a network call. Then it tries again.
- `SpanFlusher` exports a worker's finished spans between jobs, at most once a second, so the export does not run during a delivery.

The OpenTelemetry packages are pinned: `open-telemetry/sdk` ~1.15.0, `exporter-otlp` ~1.4.0, `sem-conv` 1.44.\*. The queue hook of `opentelemetry-auto-laravel` 1.10 needs a constant that `sem-conv` 1.45 removed. With 1.45, requests that queue jobs lose their trace context, and delivery attempts lose their spans. The [backlog](../backlog.md) tracks the removal of the pins.

### Metrics from the delivery history

- Queue depth comes from Redis.
- Status counts, success and failure rates, attempt counts per outcome (throttled attempts separately) and latency percentiles come from `notifications` and `notification_attempts`.
- The snapshot is cached for five seconds. The endpoint, the dashboard and the broadcast share it. Circuit states are read live on every snapshot.
- `/health` separates `ok`, `warn` (a circuit is not closed, or a channel is paused) and `fail`.

The README describes the endpoints and payloads.

## Considered options

1. **Broadcast only final statuses.** This sends one event per notification instead of four or five. But clients could not follow a notification while it waits, is attempted or is retried. This was the first choice. It was changed for real-time tracking.
2. **Polling only.** This is simple, but moves the load onto the API and delays the outcomes.
3. **A system-wide live feed of all notifications on the dashboard.** It is unreadable at full volume. The dashboard shows aggregates and the operator's own notifications instead.
4. **Metrics in a separate store (Prometheus pushgateway, StatsD).** This adds more moving parts. Metrics from the delivery history keep one source of truth.

## Consequences

**Positive**

- Consumers learn outcomes in real time. Operators see queues, rates, latency and circuits update without a page refresh.
- One correlation id connects a request, its jobs, its log lines, its trace and the provider call.
- `/health` separates "down" from "degraded", so a load balancer keeps sending traffic while one channel is paused.
- A tracing backend that does not answer does not slow down delivery.

**Negative**

- Every status change costs one queued broadcast job, four or five per notification, also when nobody listens.
- The metrics queries scan the attempts of the last hour. Very high volume needs pre-aggregated counters.
- Tracing every notification is expensive in production and needs sampling.
- Spans of a 30-second suspension period are lost.
- The pinned OpenTelemetry packages cannot be upgraded until a fixed `opentelemetry-auto-laravel` is released.
