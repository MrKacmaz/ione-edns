# ADR 0003: An own GCRA rate limiter per channel in Redis

- **Status:** Accepted
- **Date:** 2026-11-14

Each channel has its own rate limit, enforced by our own GCRA rate limiter in Redis. A worker waits in place for a near send slot and releases the job for a far send slot.

## Context

Each channel may send at most 100 messages per second to the provider. Many Horizon workers on several machines deliver at the same time, so they must share the rate limit through Redis. The limit must hold for every one-second window, not only on average.

A worker that must wait for a send slot has two options:

- Wait in place. The job keeps its position in the queue.
- Release the job to the queue. This frees the worker, but the job goes to the back of the queue.

## Decision

`RedisGcraRateLimiter` implements the Generic Cell Rate Algorithm. One Lua script makes each reservation:

- Each channel has one Redis key. It holds the channel's _theoretical arrival time_ (TAT) in microseconds: the moment the next send slot opens.
- A reservation takes the slot at `max(TAT, now)` and moves the TAT one emission interval further (10 ms at 100 messages per second). Sends are thus spaced evenly.
- The script rounds the interval up to whole microseconds. A limit that does not divide a second evenly thus never fits one send too many into a second.
- The script reads the clock with Redis `TIME`, so all workers share one schedule, independent of their own clocks. The read-modify-write is atomic.

The `RateLimitDeliveryChannel` job middleware reserves a send slot before each delivery attempt:

- If the slot opens within `rate_limit_max_wait_ms` (2 seconds by default), the worker sleeps until then.
- If not, the middleware releases the job to the queue until the slot opens.
- A wait never counts as a delivery attempt.

Each channel has its own limit, because providers often limit channels differently. `NOTIFICATIONS_{SMS,EMAIL,PUSH}_RATE_LIMIT_PER_SECOND` sets the limit of one channel. A channel without its own value uses `NOTIFICATIONS_RATE_LIMIT_PER_SECOND` (default 100).

A limit of `0` pauses the channel:

- The rate limiter does not call Redis for that channel.
- The channel's jobs look again every 5 seconds. They use no delivery attempts.
- `/health` shows a warning (`warn`).
- A job still gives up one day after its dispatch, as during an open circuit ([ADR 0005](0005-retries-and-failure-handling.md)).

## Considered options

1. **Laravel's `RateLimiter` or `Redis::throttle()`.** These count hits in a fixed window. Up to twice the limit can pass around a window boundary: 100 at the end of one second and 100 at the start of the next. Also, a denied caller only learns to try again later. It does not get a send slot.
2. **Horizon's process count as the limit.** The number of workers limits concurrency, not messages per second. The rate would depend on the provider latency.
3. **An own GCRA rate limiter** (chosen).

## Consequences

**Positive**

- No one-second window ever holds more than the limit. The rate limiter smooths bursts instead of rejecting them.
- Short waits keep the job's position. This keeps the priority order of [ADR 0002](0002-priority-queues-on-horizon.md).
- Every channel has its own queues and workers ([ADR 0002](0002-priority-queues-on-horizon.md)), so a worker that waits for a send slot holds up only its own channel.
- Each channel uses one small key. The key expires on its own when the channel has no traffic.

**Negative**

- It is custom code with a Lua script. It requires the phpredis client.
- Its tests use the real Redis clock. Their timing tolerances can be tight on a slow CI machine.
- A wait in place keeps a worker busy for up to two seconds. During that time the worker serves no other notification of its channel.
- The middleware reserves the send slot before the job claims the notification. A job with nothing to send (a cancelled notification, a duplicate job) thus still uses a send slot.
