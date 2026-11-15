# ADR 0005: Retries with full jitter and a per-channel circuit breaker

- **Status:** Accepted
- **Date:** 2026-11-14

The gateway classifies each provider answer as accepted, retryable, throttled or permanent. Retries wait with exponential backoff and full jitter, and one circuit per channel stops calls while the provider fails.

## Context

The provider can time out, answer `5xx`, throttle with `429` (possibly with `Retry-After`), or reject a request with another `4xx`. A retry of a rejected request cannot succeed. A retry of a transient failure usually succeeds. When the provider is down, separate retries for every notification keep loading it. They also fill the queues with jobs that will fail.

## Decision

### Classification

`WebhookSiteGateway` classifies each answer:

| Provider answer                                                                                                    | Outcome                                   | Circuit counts it as |
| ------------------------------------------------------------------------------------------------------------------ | ----------------------------------------- | -------------------- |
| `202` with a `messageId` (the provider contract)                                                                   | accepted                                  | healthy              |
| Timeout, connection error, `408`, `5xx`                                                                            | retryable                                 | failure              |
| `401`, `403`, `404`, `410`: wrong endpoint or credentials, for example an expired webhook.site URL                 | retryable                                 | failure              |
| Any other `2xx`, or `202` without a `messageId`: a misconfigured endpoint, for example webhook.site's default page | retryable                                 | failure              |
| `429`                                                                                                              | throttled                                 | healthy              |
| Any other `4xx`                                                                                                    | permanent: the notification fails at once | healthy              |

- The endpoint and misconfiguration answers hit every message the same way. They are retryable, so they open the circuit and alert the operators. Otherwise each notification would fail quietly.
- A throttled call is retried like a retryable one, with backoff and `Retry-After`. The provider is up, so the call does not count against the circuit. The metrics show throttled attempts separately.
- `notification_attempts` stores every call. The stored error never quotes the underlying exception, because its message names the provider URL. The full message goes to the log.

### Backoff

`RetryBackoff` uses "full jitter":

- After the n-th failed attempt, the next attempt waits a random time between zero and `base * 2^(n-1)` seconds. The base is 30 seconds, the maximum 300. The minimum wait is one second.
- Full jitter spreads the retries of notifications that failed together.
- A `Retry-After` from the provider is a lower bound. It is read strictly as delay seconds or as an RFC 9110 HTTP date, and capped at one hour.
- After five attempts, the notification fails.

### Retries are job releases, not Laravel attempts

- The job releases itself with the backoff delay. The notification records when the next attempt is due (`next_attempt_at`). Thus no other job can run the attempt sooner.
- The notification's own attempt counter decides when to stop. Thus rate-limit waits and an open circuit never use attempts.
- Laravel retries an unexpected exception, such as a database or Redis outage, after 10, 30, 60 and then every 120 seconds. This continues until the job's one-day lifetime ends ([ADR 0004](0004-delivery-guarantees-and-idempotency.md)).
- `POST /notifications/{id}/retry` gives a failed notification a new set of attempts and keeps its history.

### Circuit breaker

`RedisCircuitBreaker` keeps one circuit per channel, shared in Redis:

- **Closed:** call outcomes are counted in one-second buckets. The circuit opens when the last 60 seconds hold at least 20 calls and at least 50% of them are retryable failures. Permanent failures and throttled calls count as healthy, because the provider answered.
- **Open:** the `CheckDeliveryChannelCircuit` job middleware releases jobs until the 30-second cooldown ends. It runs before the rate limiter and does not claim the notification. Thus a released job uses no attempt and no send slot.
- Each released job waits the circuit's remaining time plus a random extra of up to the same length. Thus the held jobs come back spread over the next window, not all in the same second.
- **Half open:** after the cooldown, the first job becomes the probe and all other jobs wait. Success of the probe closes the circuit. Failure opens it for another cooldown. A probe that does not report within 20 seconds is replaced.
- A probe job that ends without a provider call gives the probe back at once (`releaseProbe()`). Examples: the rate limiter released the job, the job came early, or its notification was cancelled. Thus the other jobs do not wait for the 20-second probe timeout.
- One Lua script makes each transition. Thus exactly one worker learns that a circuit opened or closed. That worker logs the change and broadcasts it to the dashboard.

## Considered options

1. **Laravel's `tries` and `backoff`.** They count every release, also rate-limit waits. They cannot honour a provider's `Retry-After` per attempt.
2. **Exponential backoff without jitter.** Notifications that failed together retry together. They hit a recovering provider at the same moment.
3. **No circuit breaker.** During an outage, every notification uses all its attempts, fails, and needs a manual retry.
4. **A circuit breaker library.** No library fits a shared, Redis-backed breaker driven from job middleware. The logic is small enough to own.

## Consequences

**Positive**

- Transient failures recover without help. Permanent failures fail fast, with the provider's status in the error.
- During an outage, notifications wait without using attempts. The provider gets one probe per cooldown. Each circuit change is logged and shown on the dashboard once.
- The dashboard, `/metrics` and `/health` (status `warn`) show which channel is paused.

**Negative**

- All channels currently share one provider URL. Thus an outage opens the three circuits one after the other, not together.
- A job still gives up one day after its first dispatch. If a circuit stays open longer, its notifications fail and need a manual retry.
- Below 20 calls a minute, a circuit cannot open. Thus, with little traffic, an expired provider URL still fails the notifications after their five attempts. After the URL is fixed, `POST /notifications/{id}/retry` retries them one at a time.
- The thresholds are global defaults. A provider with a different error profile needs them tuned in `config/notifications.php`.
