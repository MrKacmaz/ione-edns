# ADR 0001: An own notification model instead of Laravel Notifications

- **Status:** Accepted
- **Date:** 2026-11-14

We store every notification as an Eloquent model with an explicit status lifecycle. Our own pipeline delivers it. We do not use Laravel Notifications for it.

## Context

The service accepts notification requests from API clients. It delivers them as SMS, email or push messages through one external provider (webhook.site). The requirements ask for:

- status queries by notification id or batch id,
- cancellation of pending notifications,
- a filtered, paginated list (status, channel, date range),
- batches of up to 1000 notifications,
- idempotency, to prevent duplicate sends,
- at most 100 messages per second per channel,
- high, normal and low priorities,
- retries that separate transient failures from permanent failures,
- metrics such as success rate, failure rate and latency.

Laravel has a notification system (`Illuminate\Notifications`). A `Notification` class selects its channels in `via()`: mail, database, broadcast, Vonage SMS, Slack and others. `$notifiable->notify()` sends it, optionally through the queue. The system is for an application that tells its own users about an event. It does not keep track of a sent notification.

## Decision

`App\Models\Notification` (table `notifications`) is a first-class Eloquent model with an explicit lifecycle:

```
waiting | scheduled → queued → processing → sent | failed | cancelled
```

Our own pipeline delivers it:

```
CreateNotificationsAction → DispatchNotificationsAction → SendNotificationJob → DeliveryService → NotificationGateway
```

We do not use Laravel Notifications for the notifications that clients submit.

## Considered options

1. **Laravel Notifications only**, with on-demand sends through `Notification::route(...)->notify(...)`.
    - Sending is fire-and-forget. Nothing is stored for queries, lists, cancellation, batches, deduplication or metrics.
    - The built-in `database` channel stores in-app messages with a `read_at` column. It does not store delivery state.
    - Laravel expects the recipients to be notifiable models of the application. Our recipients are arbitrary phone numbers, email addresses and device tokens.
2. **An own model, with Laravel Notifications as the transport.** The job loads the model and calls `notifyNow()` with a custom webhook channel.
    - The model solves the state problem, but the extra layer gives us nothing that we use.
    - `via()` would always return the one channel that the request already set.
    - The custom channel still needs our own classification of the provider's answer (202, 429 with `Retry-After`, 5xx, other 4xx).
    - Laravel uses one channel per provider. Here, one provider serves all channels with the same payload.
3. **An own model and an own delivery pipeline** (chosen).

## Consequences

**Positive**

- Every requirement maps to stored state: status queries, cancellation, lists, batch summaries and idempotency keys.
- Each status change is one conditional `UPDATE` (claim for delivery, cancel). Thus the service never sends a cancelled notification, and two workers never deliver the same notification.
- `notification_attempts` records every provider call. The retry policy and the metrics use these records.
- One `NotificationGateway` contract matches one provider. `DeliveryChannel` strategies hold the per-channel rules.

**Negative**

- We maintain code that Laravel Notifications would otherwise supply, for example queued delivery and the channel abstraction.
- The model name shadows `Illuminate\Notifications\Notification`. We removed the `Notifiable` trait from `User`. A file that needs both classes must alias one of them.
- `notifications` is also the default table of Laravel's database notification channel. If the application needs in-app notifications for its own users, that channel must use a different table.

## Where Laravel Notifications still fit

Laravel Notifications fit messages to the system's own people, for example an alert to operators when failed notifications accumulate. Horizon already uses Laravel Notifications for its long-wait alerts on the notification queues.
