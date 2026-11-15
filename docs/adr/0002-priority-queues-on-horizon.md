# ADR 0002: Queues per channel and priority, with strict order and floors

- **Status:** Accepted (revised: queues per channel, a floor for normal priority)
- **Date:** 2026-11-14

Each channel and priority has its own Redis queue. Per channel, one Horizon supervisor works the three queues in strict priority order, and two small supervisors keep normal and low priority moving.

## Context

A notification has the priority `high`, `normal` or `low`. During a burst (a flash sale, breaking news), a high-priority notification must not wait behind thousands of low-priority notifications. Lower priorities must still be delivered eventually.

Each channel also has its own rate limit of 100 messages per second ([ADR 0003](0003-gcra-rate-limiter-per-channel.md)). When a channel's second is full, its worker waits for the next send slot. If all channels share one queue, an SMS burst fills that queue. The workers then wait for SMS slots, and email and push stop behind them, although their own limits are far from full. This is head-of-line blocking.

The queues run on Redis with Laravel Horizon. A Horizon supervisor works a list of queues. With `balance: false`, every worker takes the first queue in the list that has a job. Thus the order of the list is the priority order.

## Decision

Each channel and priority has its own queue, for example `notifications-sms-high` (see `NotificationPriority::queueFor()`). `config/horizon.php` runs three supervisors per channel:

| Supervisor                    | Queues                                                   | Behavior                                                                                                      |
| ----------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `supervisor-{channel}`        | high, normal and low, in strict order (`balance: false`) | A worker takes a normal job only when no high job waits, and a low job only when no high or normal job waits. |
| `supervisor-{channel}-normal` | normal only                                              | Normal priority always moves, also during a long run of high-priority notifications.                          |
| `supervisor-{channel}-low`    | low only                                                 | Low priority always moves.                                                                                    |

- A channel that waits for send slots holds up only its own workers.
- Horizon's `waits` thresholds differ per priority: 30 seconds for high, 120 for normal, 600 for low. Thus Horizon reports a long wait against what each priority promises.
- `DispatchNotificationsAction` groups the jobs by queue and pushes each group in one Redis pipeline. A batch of 1000 notifications takes one round trip per queue, not 1000.
- The push records no tracing span per job. 1000 such spans cost about one second per batch.

## Considered options

1. **One queue, with priority as a sort order.** Redis lists cannot sort by a field. This needs a database-backed queue with polling, which gives up Horizon and adds load to the database.
2. **Queues per priority only, shared by all channels** (the first version). A burst on one channel holds up the other channels. Only low priority had a floor. Thus, under a long flood of high-priority notifications, low priority moved and normal priority stopped.
3. **Release a job instead of waiting for its send slot.** This frees the worker, but sends the job to the back of its queue. A burst becomes many re-queued jobs. It also does not stop one channel's backlog from filling the shared queue.
4. **Horizon's `auto` balancing across the queues.** It balances by queue length. A long low-priority queue would take workers away from high priority, which is the opposite of priority.
5. **Queues per channel and priority, strict order, plus floors for normal and low** (chosen).

## Consequences

**Positive**

- A burst or a rate-limit wait on one channel never delays the other channels.
- Every worker of the channel's main supervisor takes high-priority jobs first. High priority also wins the channel's send slots during a burst.
- Normal and low priority keep a guaranteed share of capacity. With the default worker counts, normal always gets at least as much as low, because the main supervisor also prefers normal.
- Three process counts per channel set the capacity: `HORIZON_NOTIFICATION_WORKERS`, `HORIZON_NORMAL_PRIORITY_WORKERS` and `HORIZON_LOW_PRIORITY_WORKERS`.

**Negative**

- There are nine notification queues and nine supervisors, instead of three queues and two supervisors. The minimum is nine worker processes, also when a channel has no work.
- Idle workers of one channel cannot help another channel. A channel's capacity is its own process count.
- The floors are fixed numbers of processes. They do not grow with load.
