# ADR 0006: Scheduled notifications with a minute sweep and delayed jobs

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

A notification may carry a `scheduled_at` up to 30 days ahead. It should go out at that moment, not up to a minute later, and it must stay cancellable until it is sent.

A queue job can be delayed (`delay()`), but a delay of days keeps the job in Redis for days: it is lost with Redis' memory, cannot be found or changed through the database, and keeps a cancelled notification's job around until it runs.

## Decision

- A notification with `scheduled_at` is stored with status `scheduled` and is not queued.
- `notifications:dispatch-due` runs every minute and queues the scheduled notifications due within the next 60 seconds (`Notification::dueForDispatch()`), each job delayed until its exact `scheduled_at`. An overdue notification (for example after scheduler downtime) is queued without a delay.
- A notification created with a `scheduled_at` inside that 60-second window is queued right away, with the same delay, so it does not wait for the next sweep.
- A scheduled notification is claimable once its time has come, so a job that runs before its notification was marked `queued` is not lost.
- Validation: `scheduled_at` must be in the future and at most `NOTIFICATIONS_SCHEDULE_MAX_DAYS` (30) days ahead, otherwise `422`. In a batch, every notification has its own `scheduled_at`.
- The end-to-end latency metric counts from `scheduled_at` for scheduled notifications.

## Considered options

1. **Delay the job at creation for the whole period.** Precise, but keeps jobs in Redis for up to 30 days, with the problems above.
2. **Sweep every minute and dispatch due notifications without a delay.** Simple, but sends up to a minute late.
3. **Sweep with a lookahead and delayed jobs** (chosen): the database holds the schedule, and Redis only ever holds jobs due within a minute.

## Consequences

**Positive**

- Notifications go out within a few seconds of their time; in a local test one was sent two seconds after it.
- Until the last minute a scheduled notification exists only in the database, where it can be listed, cancelled and recovered.
- A scheduler outage delays notifications but loses none.

**Negative**

- Precision depends on the scheduler running every minute (`schedule:work`, or `schedule:run` from cron).
- Many notifications scheduled for the same moment are sent at the channel's rate limit, so 10,000 SMS for 09:00 take about 100 seconds.
