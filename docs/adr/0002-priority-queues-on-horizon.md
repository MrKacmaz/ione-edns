# ADR 0002: Priority queues with strict order and a low-priority floor

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

Notifications carry a priority of `high`, `normal` or `low`. During a burst (a flash sale, breaking news) a high-priority notification must not wait behind thousands of low-priority ones, while low-priority notifications must still be delivered eventually.

The queue runs on Redis with Laravel Horizon. A Horizon supervisor processes a list of queues; with `balance: false` every worker takes the first queue in the list that has a job, so the order of the list is the order of priority.

## Decision

Each priority has its own queue (`notifications-high`, `notifications-normal`, `notifications-low`, see `NotificationPriority::queue()`), and `config/horizon.php` runs two supervisors:

- `supervisor-notifications` processes all three queues in strict order (`balance: false`): a worker only takes a normal job when no high job is waiting, and a low job only when neither high nor normal is waiting.
- `supervisor-notifications-low` runs one worker that processes only `notifications-low`, so low priority always moves, even during a long run of high and normal notifications.

Horizon's `waits` thresholds differ per queue (30 seconds for high, 120 for normal, 600 for low), so a long wait is reported according to what each priority promises.

## Considered options

1. **One queue, priority as a sort order.** Redis lists have no ordering by a field; it would need a database-backed queue with polling, which gives up Horizon and adds load to the database.
2. **Strict order only.** Simple, but low priority can starve completely during a burst.
3. **Horizon's `auto` balancing across the three queues.** Balances by queue length, so a long low-priority queue would draw workers away from high priority, which is the opposite of what priorities mean.
4. **Strict order plus a dedicated low-priority floor** (chosen).

## Consequences

**Positive**

- High priority is drained first by every worker of the main supervisor and also wins the per-channel send slots of the rate limiter (ADR 0003) during a burst.
- Low priority keeps a guaranteed, small share of capacity.
- Capacity is tuned in one place: the process counts of the two supervisors.

**Negative**

- Normal priority has no floor of its own: a sustained flood of high-priority notifications delays it until the flood ends.
- The low-priority floor is a fixed number of processes, not a share that grows with load.
