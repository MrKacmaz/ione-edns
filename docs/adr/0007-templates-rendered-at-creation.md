# ADR 0007: Templates rendered when the notification is created

- **Status:** Accepted
- **Date:** 2026-11-14

A notification can name a template and give variable values. The API renders the template strictly when it creates the notification, and stores the result as the notification's content.

## Context

Clients want to define a message text once, for example "Hi {{ name }}, your order {{ order_id }} has shipped.". Then they send notifications that name the template and give the variable values. Templates change over time. Notifications can wait in a queue or a schedule for days.

## Decision

- A template belongs to a user. It has a name that is unique per user, one channel that cannot change, and content with `{{ variable }}` placeholders (letters, digits and underscores). Clients manage templates at `/api/v1/templates`.
- A template save rejects malformed placeholders. It also rejects fixed text (the content without its placeholders) that alone is longer than the channel's length limit.
- A notification gives either `content`, or `template` (by name) with `variables`. The template must belong to the user and match the notification's channel.
- The API renders the template **when it creates the notification**. It stores the result in the notification's `content`.
- The notification keeps `template_id` for traceability. `template_id` becomes null when the template is deleted.
- Rendering is strict. A missing variable, or a variable that is not text or a number, answers `422` on `variables`. The rendered content must fit the channel's limit.
- Values are inserted as they are and never rendered again. Thus a value that contains `{{ x }}` stays literal.
- A batch loads all templates that it names with one query.

## Considered options

1. **Render at send time.** The text would follow template edits made after the request. Thus a notification's text would depend on when it is sent. Validation errors (a missing variable, content too long) would also occur in a worker, not in the API response.
2. **Lenient rendering** (a missing variable becomes empty). Silent, wrong messages are worse than a `422`.
3. **A template engine such as Blade.** Notifications are plain text and templates need no logic. Execution of user-provided templates is also a security risk.

## Consequences

**Positive**

- The API response reports every validation error, also the rendered length.
- A notification's content never changes after the API accepts it. Its delivery history shows exactly what was sent.
- A template edit or deletion is safe for notifications already created from the template.

**Negative**

- Each notification stores its rendered text. This repeats the template text in the table.
- A template edit cannot correct notifications that already wait. They keep the old text.
