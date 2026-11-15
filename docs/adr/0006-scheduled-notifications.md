# ADR 0006: Scheduled notifications with a minute sweep and delayed jobs

- **Status:** Accepted
- **Date:** 2026-11-14

A scheduled notification stays in the database until less than a minute before its time. A sweep then queues it as a job delayed to its exact `scheduled_at`.

## Context

A notification can carry a `scheduled_at` up to 30 days ahead. It must go out at that moment, not up to a minute later. It must stay cancellable until it is sent.

A queue job can be delayed (`delay()`). But a delay of days keeps the job in Redis for days:

- The job is lost when Redis loses its memory.
- The database cannot find or change the job.
- The job of a cancelled notification stays in Redis until it runs.

## Decision

- A notification with `scheduled_at` is stored with status `scheduled`. It is not queued.
- `notifications:dispatch-due` runs every minute. It queues the scheduled notifications that are due within the next 60 seconds (`Notification::dueForDispatch()`). It delays each job until its exact `scheduled_at`.
- An overdue notification, for example after scheduler downtime, is queued without a delay.
- A notification created with a `scheduled_at` inside the 60-second window is queued at once, with the same delay. It does not wait for the next sweep.
- A worker can claim a scheduled notification once its time has come. Thus a job that runs before its notification is marked `queued` is not lost.
- Validation: `scheduled_at` must be in the future and at most `NOTIFICATIONS_SCHEDULE_MAX_DAYS` (30) days ahead. Otherwise the request answers `422`. In a batch, every notification has its own `scheduled_at`.
- For scheduled notifications, the end-to-end latency metric starts at `scheduled_at`.

## Considered options

1. **Delay the job at creation for the full period.** This is precise, but keeps jobs in Redis for up to 30 days, with the problems above.
2. **Sweep every minute and dispatch due notifications without a delay.** This is simple, but sends up to a minute late.
3. **Sweep with a lookahead and delayed jobs** (chosen). The database holds the schedule. Redis only holds jobs that are due within a minute.

## Consequences

**Positive**

- Notifications go out within a few seconds of their time. In a local test, one was sent two seconds after its time.
- Until the last minute, a scheduled notification exists only in the database. There it can be listed, cancelled and recovered.
- A scheduler outage delays notifications but loses none.

**Negative**

- The precision depends on a scheduler that runs every minute (`schedule:work`, or `schedule:run` from cron).
- Many notifications scheduled for the same moment go out at the channel's rate limit. For example, 10,000 SMS for 09:00 take about 100 seconds.
