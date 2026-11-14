# ADR 0005: Retries with full jitter and a per-channel circuit breaker

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

The provider can time out, answer `5xx`, throttle with `429` (possibly with `Retry-After`), or reject a request with another `4xx`. Retrying a rejected request cannot succeed; retrying a transient failure usually does. When the provider is down, retrying every notification on its own schedule keeps hammering it and fills the queues with jobs that are bound to fail.

## Decision

**Classification** (`WebhookSiteGateway`): a `2xx` with a `messageId` is accepted, and a `2xx` without one is permanent, because the provider did not confirm the message; a timeout, a connection error, `408`, `429` and `5xx` are retryable; `401`, `403`, `404` and `410` say that the endpoint or its credentials are wrong (for example an expired webhook.site URL), would hit every message alike, and are therefore retryable too, so they open the circuit and alert the operators instead of failing each notification quietly; any other `4xx` is permanent and fails the notification at once. Every call is stored in `notification_attempts`. The stored error never quotes the underlying exception, whose message names the provider URL; the full message goes to the log.

**Backoff** (`RetryBackoff`): after the n-th failed attempt the next attempt waits a random time between zero and `base * 2^(n-1)` seconds (30 seconds doubling, at most 300), with a minimum of one second. This is "full jitter", which spreads retries of notifications that failed together. A `Retry-After` from the provider is honoured as a lower bound, read strictly as delay seconds or an RFC 9110 HTTP date, and capped at one hour. After five attempts the notification fails.

**Retries are job releases, not Laravel attempts.** The job releases itself with the backoff delay and the notification records when the next attempt is due (`next_attempt_at`), so no other job for it can run the attempt sooner; the notification's own attempt counter decides when to give up, so rate-limit waits and an open circuit never use up attempts. A database or Redis outage releases the job with a growing delay without counting as an exception (ADR 0004). Any other unexpected exception is retried by Laravel after 10, 30, 60 and 120 seconds, at most five times. `POST /notifications/{id}/retry` gives a failed notification a fresh set of attempts and keeps its history.

**Circuit breaker** (`RedisCircuitBreaker`, one circuit per channel, shared in Redis):

- While closed, call outcomes are counted in one-second buckets. When the last 60 seconds hold at least 20 calls and at least 50% of them failed in a retryable way, the circuit opens. Permanent failures count as healthy, because the provider did answer.
- While open, the `CheckDeliveryChannelCircuit` job middleware releases jobs until the 30-second cooldown ends, without claiming their notifications and before the rate limiter, so no attempt and no send slot is used. Each job waits the circuit's remaining time plus a random extra of up to the same length, so the jobs held back together return spread over the next window instead of all in the same second.
- After the cooldown the first job becomes the probe (half open) and all others keep waiting. The probe's success closes the circuit; its failure opens it for another cooldown. A probe that does not report back within 20 seconds is replaced.
- Every transition happens in one Lua script, so exactly one worker learns that a circuit opened or closed. That worker logs it and broadcasts it to the dashboard.

## Considered options

1. **Laravel's `tries` and `backoff`.** They count every release, including rate-limit waits, and cannot honour a provider's `Retry-After` per attempt.
2. **Exponential backoff without jitter.** Notifications that failed together retry together and hit a recovering provider at the same moment.
3. **No circuit breaker.** During an outage every notification burns all its attempts, then fails, and needs a manual retry.
4. **A circuit breaker library.** None fits a shared, Redis-backed breaker driven from job middleware; the logic is small enough to own.

## Consequences

**Positive**

- Transient failures recover without help; permanent ones fail fast with the provider's status in the error.
- During an outage, notifications wait without using attempts, the provider gets one probe per cooldown, and each change is logged and shown on the dashboard once.
- The dashboard, `/metrics` and `/health` (status `warn`) show which channel is paused.

**Negative**

- All channels currently share one provider URL, so an outage opens the three circuits one by one rather than together.
- A job still gives up one day after its first dispatch; if a circuit stays open longer, its notifications fail and need a manual retry.
- Below 20 calls a minute a circuit cannot open, so with little traffic an expired provider URL still fails the notifications after their five attempts; once the URL is fixed they are retried one by one with `POST /notifications/{id}/retry`.
- The thresholds are global defaults; a provider with a different error profile needs tuning through `NOTIFICATIONS_CIRCUIT_*`.
