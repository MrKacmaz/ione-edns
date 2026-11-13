# ADR 0001: An own notification model instead of Laravel Notifications

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

The service accepts notification requests from API clients and delivers them as SMS, email or push messages through one external provider (webhook.site). The requirements ask for:

- querying a notification's status by its id or batch id,
- cancelling notifications that are still pending,
- a filtered, paginated listing (status, channel, date range),
- batches of up to 1000 notifications,
- idempotency to prevent duplicate sends,
- at most 100 messages per second per channel and high, normal and low priorities,
- retries that tell transient failures from permanent ones,
- metrics such as success and failure rates and latency.

Laravel ships a notification system (`Illuminate\Notifications`). A `Notification` class picks its channels in `via()` (mail, database, broadcast, Vonage SMS, Slack and others), and `$notifiable->notify()` sends it, optionally through the queue. It is built for an application that tells its own users about something that happened, and it does not keep track of a sent notification.

## Decision

Notifications are a first-class Eloquent model, `App\Models\Notification` (table `notifications`), with an explicit lifecycle:

```
waiting → queued → processing → sent | failed | cancelled
```

They are delivered by an own pipeline:

```
CreateNotificationsAction → DispatchNotificationsAction → SendNotificationJob → DeliveryService → NotificationGateway
```

Laravel Notifications are not used for the notifications clients submit.

## Considered options

1. **Laravel Notifications only**, sending on demand with `Notification::route(...)->notify(...)`.
   Sending is fire-and-forget: nothing is stored that could be queried, listed, cancelled, grouped into a batch, deduplicated or measured. The built-in `database` channel stores in-app messages with a `read_at` column, not delivery state. Recipients are expected to be notifiable models of the application, while ours are arbitrary phone numbers, email addresses and device tokens.

2. **An own model, with Laravel Notifications as the transport**: the job loads the model and calls `notifyNow()` with a custom webhook channel.
   The state problem is solved by the model, but the extra layer brings nothing we use. `via()` would always return the one channel already decided by the request, the custom channel would still need our own classification of the provider's answer (202, 429 with `Retry-After`, 5xx, other 4xx), and Laravel's channel-per-provider design does not match one provider serving all channels with the same payload.

3. **An own model and an own delivery pipeline** (chosen).

## Consequences

**Positive**

- Every requirement maps to stored state: status queries, cancellation, listing, batch summaries and idempotency keys.
- Status changes are single conditional `UPDATE` statements (claiming for delivery, cancelling), so a cancelled notification is never sent and two workers never deliver the same notification.
- Every provider call is recorded in `notification_attempts`, which drives the retry policy and the metrics.
- One `NotificationGateway` contract matches one provider; per-channel rules live in `DeliveryChannel` strategies.

**Negative**

- We maintain code Laravel Notifications would otherwise provide, such as queueing a delivery and the channel abstraction.
- The model's name shadows `Illuminate\Notifications\Notification`. The `Notifiable` trait was removed from `User`, and a file that needs both classes has to alias one of them.
- The table name `notifications` is also the default of Laravel's database notification channel. If the application ever needs in-app notifications for its own users, that channel must use another table.

## Where Laravel Notifications still fit

Notifying the system's own people, for example alerting operators when failed notifications pile up. Horizon already uses Laravel Notifications for its long-wait alerts on the notification queues.
