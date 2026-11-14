# ADR 0002: Queues per channel and priority, with strict order and floors

- **Status:** Accepted (revised: queues per channel, a floor for normal priority)
- **Date:** 2026-11-14

## Context

Notifications carry a priority of `high`, `normal` or `low`. During a burst (a flash sale, breaking news) a high-priority notification must not wait behind thousands of low-priority ones, while lower priorities must still be delivered eventually.

Each channel also has its own send limit of 100 messages a second (ADR 0003). A worker that holds a job of a channel whose second is full waits for the next slot. When all channels share one queue, a burst of SMS fills that queue and keeps the workers busy waiting for SMS slots, so email and push stall behind it although their own limits are far from reached (head-of-line blocking).

The queue runs on Redis with Laravel Horizon. A Horizon supervisor processes a list of queues; with `balance: false` every worker takes the first queue in the list that has a job, so the order of the list is the order of priority.

## Decision

Each channel and priority has its own queue, for example `notifications-sms-high` (see `NotificationPriority::queueFor()`). `config/horizon.php` runs three supervisors per channel:

- `supervisor-{channel}` processes the channel's three queues in strict order (`balance: false`): a worker only takes a normal job when no high job is waiting, and a low job only when neither high nor normal is waiting.
- `supervisor-{channel}-normal` and `supervisor-{channel}-low` each run workers that process only that one queue, so normal and low priority always move, even during a long run of high-priority notifications, and normal never gets fewer workers than low.

A channel that waits for send slots therefore holds up only its own workers. Horizon's `waits` thresholds differ per priority (30 seconds for high, 120 for normal, 600 for low), so a long wait is reported according to what each priority promises.

## Considered options

1. **One queue, priority as a sort order.** Redis lists have no ordering by a field; it would need a database-backed queue with polling, which gives up Horizon and adds load to the database.
2. **Queues per priority only, shared by all channels** (the first version). A burst on one channel holds up the others, and the only floor was for low priority, so under a sustained flood of high-priority notifications low moved while normal stood still.
3. **Release a job instead of waiting for its send slot.** Frees the worker, but sends the job to the back of its queue and turns a burst into many re-queued jobs; it does not stop one channel's backlog from filling the shared queue either.
4. **Horizon's `auto` balancing across the queues.** Balances by queue length, so a long low-priority queue would draw workers away from high priority, which is the opposite of what priorities mean.
5. **Queues per channel and priority, strict order plus floors for normal and low** (chosen).

## Consequences

**Positive**

- One channel's burst or rate-limit wait never delays the other channels.
- High priority is drained first by every worker of its channel's main supervisor and also wins the channel's send slots during a burst.
- Normal and low priority keep a guaranteed share of capacity, and normal always gets at least as much as low.
- Capacity is tuned per channel with three process counts (`HORIZON_NOTIFICATION_WORKERS`, `HORIZON_NORMAL_PRIORITY_WORKERS`, `HORIZON_LOW_PRIORITY_WORKERS`).

**Negative**

- Nine notification queues and nine supervisors instead of three and two; the minimum is nine worker processes, even when a channel is idle.
- Idle workers of one channel cannot help another channel; a channel's capacity is its own process count.
- The floors are fixed numbers of processes, not shares that grow with load.
