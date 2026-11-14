# ADR 0007: Templates rendered when the notification is created

- **Status:** Accepted
- **Date:** 2026-11-14

## Context

Clients want to define message texts once ("Hi {{ name }}, your order {{ order_id }} has shipped.") and send notifications by naming a template and giving the variable values. Templates change over time, while notifications may wait in a queue or a schedule for days.

## Decision

- A template belongs to a user, has a name unique per user, one channel that cannot change, and content with `{{ variable }}` placeholders (letters, digits and underscores). It is managed at `/api/v1/templates`.
- Saving a template rejects malformed placeholders and fixed text (the content without its placeholders) that alone exceeds the channel's length limit.
- A notification gives either `content`, or `template` (by name) with `variables`. The template must belong to the user and match the notification's channel.
- The template is rendered **when the notification is created**, and the result is stored in the notification's `content`. `template_id` is kept for traceability and becomes null if the template is deleted.
- Rendering is strict: a missing variable, or one that is not text or a number, answers `422` on `variables`; the rendered content must fit the channel's limit. Values are inserted as they are and never rendered again, so a value containing `{{ x }}` stays literal.
- A batch loads all templates it names with one query.

## Considered options

1. **Render at send time.** The text would follow template edits made after the request, so what a notification says would depend on when it happens to be sent, and validation errors (a missing variable, content too long) would surface in a worker instead of the API response.
2. **Lenient rendering** (leave a missing variable empty). Silent, wrong messages are worse than a `422`.
3. **A template engine such as Blade.** Notifications are plain text, logic in templates is not needed, and executing user-provided templates is a security risk.

## Consequences

**Positive**

- Every validation error, including the rendered length, is reported in the API response.
- A notification's content never changes after it was accepted, and its delivery history shows exactly what was sent.
- Editing or deleting a template is safe for notifications already created from it.

**Negative**

- The rendered text is stored once per notification, which repeats the template's text in the table.
- A template edit cannot fix notifications that are already waiting; they keep the old text.
