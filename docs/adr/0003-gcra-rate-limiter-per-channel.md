# ADR 0003: An own GCRA rate limiter per channel in Redis

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

Each channel may send at most 100 messages per second to the provider. Many Horizon workers on several machines deliver notifications at the same time, so the limit must be shared through Redis, and it must hold for every one-second window, not only on average.

A worker that has to wait for a slot can either wait in place (keeping its place in the queue) or hand the job back to the queue (freeing the worker, but sending the job to the back of the line).

## Decision

`RedisGcraRateLimiter` implements the Generic Cell Rate Algorithm in one Lua script per reservation:

- Each channel has one Redis key holding its _theoretical arrival time_ (TAT), the moment its next slot opens, in microseconds.
- A reservation takes the slot at `max(TAT, now)` and moves the TAT one emission interval further (10 ms at 100 messages per second), so sends are spaced evenly. The interval is rounded up to whole microseconds, so a limit that does not divide a second evenly never fits one send too many into a second.
- The script reads the clock with Redis `TIME`, so all workers share one schedule regardless of their own clocks, and the read-modify-write is atomic.

The `RateLimitDeliveryChannel` job middleware reserves a slot before each delivery attempt. When the slot opens within `rate_limit_max_wait_ms` (2 seconds by default) the worker sleeps until then; otherwise it releases the job back to the queue for the time until the slot opens. A wait never counts as a delivery attempt.

## Considered options

1. **Laravel's `RateLimiter` / `Redis::throttle()`.** These count hits in a fixed window. Up to twice the limit can pass around a window boundary (100 at the end of one second and 100 at the start of the next), and a denied caller only learns to try again later instead of being given a slot.
2. **Horizon's process count as the limit.** The number of workers limits concurrency, not messages per second; the rate would depend on provider latency.
3. **An own GCRA limiter** (chosen).

## Consequences

**Positive**

- No one-second window ever holds more than the limit, and bursts are smoothed instead of rejected.
- Short waits keep the job's position, which preserves the priority order of ADR 0002.
- Every channel has its own queues and workers (ADR 0002), so a worker waiting for a send slot holds up only its own channel.
- One small key per channel; the state expires on its own when a channel is idle.

**Negative**

- It is custom code with a Lua script, and it requires the phpredis client.
- Its tests depend on the real Redis clock, so their timing tolerances could be tight on a slow CI machine.
- Waiting in place keeps a worker busy for up to two seconds, during which it serves no other notification of its channel.
- The slot is reserved before the notification is claimed, so a job that turns out to have nothing to send (a cancelled notification, a duplicate job) still uses a slot.
