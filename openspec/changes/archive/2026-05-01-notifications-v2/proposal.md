# Notifications v2

## Problem
The `notifications-annotation` change (archived 2026-04-29) shipped a v1 of the `x-openregister-notifications` annotation: schemas declare named notifications with trigger types `created`/`updated`/`transition`, recipient kinds `users`/`field`/`groups`/`relation`, and channels `nc-notification`/`email`/`activity`/`webhook`. v1 covers most of the everyday notification needs apps had to hand-write before.

Several v2 features were carved out of v1 because each is a multi-day engineering chunk:

- **Scheduled triggers** — fire on a cron, not on a save event.
- **Threshold triggers** — fire when an aggregation crosses a value.
- **Webhook auto-create entity** — instead of a fire-and-forget HTTP POST per dispatch, install a real `Webhook` entity at schema-save so the existing retry / HMAC / dead-letter / multi-tenancy machinery applies.
- **Recipient kinds**: `object-acl` (resolve via OR's per-object ACL) and `expression` (DI-tagged user resolver).
- **Channel**: `talk` (post a chat message into a Talk room).

Without these, apps that need cron-driven reminders, "alert when overdue count exceeds 10", durable webhook delivery with retries, dynamic recipient resolution, or Talk-room broadcasts have to fall back to bespoke PHP.

## Proposed Solution
Layer five additions onto the existing v1 dispatcher and validator without changing the v1 wire shape:

1. `trigger.type: "scheduled"` — installer (subscribed to `SchemaUpdatedEvent` / `SchemaCreatedEvent`) registers a `BackgroundJob` per notification keyed on the cron expression. The job runs the notification's `filter` as a `findObjects` query and dispatches per matching object.
2. `trigger.type: "threshold"` — installer subscribes to the existing aggregation cache invalidation event. When the referenced aggregation crosses the declared threshold (op + value), fires the notification once with `context: { aggregation: ..., previousValue: ..., newValue: ... }`.
3. `webhook` channel: when `webhook.persistent: true`, the installer creates / upserts a `Webhook` entity (event mapping, payload Twig template, HMAC config, retry policy) instead of doing a fire-and-forget POST. Dispatch then goes through the existing `WebhookService::dispatchEvent` pipeline.
4. `recipient.kind: "object-acl"` — walks the object's ACL (existing `OrObjectAclMapper`) and returns every user/group with the named permission level (e.g. `read` or `manage`).
5. `recipient.kind: "expression"` — DI tag on the spec (`{kind:"expression", resolver:"app.notif.foo"}`); the dispatcher resolves the tag to a `RecipientResolverInterface` and asks it for uids.
6. `channel: "talk"` — post the rendered subject as a chat message into the Talk room declared on the spec (`{channel:"talk", talk: {token:"abc..."}}`); uses the standard NC Talk REST API.

## Out of scope (v3 backlog)
- Notification grouping / batching across many objects in a short window.
- User-side opt-in / opt-out preferences per notification name (separate from NC's global notification settings).
- Localised subject templates (current `{{prop}}` interpolation is single-locale).
