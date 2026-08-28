# Tasks — Notifications Annotation

> **Prerequisite:** `aggregations-and-calculations` (and the eventual split in PR #1354) MUST be implemented first. This change consumes two artefacts declared there:
>
> - `lib/Service/Search/PlaceholderResolver.php` — used by notification trigger filters (task 6.2 of `aggregations-and-calculations/tasks.md`).
> - The aggregation cache-invalidation event — required by `trigger.type: "threshold"` (task 1.7 below).
>
> The Hydra supervisor MUST treat this dependency as authoritative for build sequencing. When the eventual `hydra.json` is produced for this change, it MUST contain `depends_on: ["aggregations-only"]` (or, until PR #1354 lands, `["aggregations-and-calculations"]`). Without this, parallel dispatch can race the build of the cache-invalidation event class and notifications-annotation will fail to subscribe.

- [ ] 1.1 Add `x-openregister-notifications` schema-save validation — every trigger type known, every recipient kind known, every channel kind in `notificatie-engine`'s implemented list, every relation reference resolvable, every aggregation reference (for threshold triggers) resolvable, no two notifications share a name.
- [ ] 1.2 Create `lib/Service/Notification/NotificationsAnnotationInstaller.php` — runs at schema save / schema install; for each declared notification, upserts a Webhook entity with the right `events`, `mapping` (i18n template key), and `recipients` glue. Idempotent — a re-save with no changes is a no-op.
- [ ] 1.3 Create `lib/Service/Notification/RecipientResolver.php` — given a recipient block (e.g., `{kind: "relation", relation: "approvers"}`) and a triggering object, returns the uid list. Delegates: `users` → literal list, `groups` → `IGroupManager::getUsersFromGroup`, `field` → object's named field, `relation` → existing `RelationsService` walk, `object-acl` → existing OR ACL lookup, `expression` → DI-tagged user-provided service.
- [ ] 1.4 Wire `RecipientResolver` into the existing `WebhookService::buildPayload` callsite so installed-from-annotation webhooks resolve recipients per the declared kind.
- [ ] 1.5 Schema-save validation also rejects malformed channel blocks (channels not in the set: `nc-notification` / `email` / `webhook` / `talk` / `activity`) per the existing `notificatie-engine` channel registry.
- [ ] 1.6 Schedule trigger support — for `trigger.type: "scheduled"`, the installer registers a `BackgroundJob` per notification that fires on the cron schedule, runs the trigger filter via the existing aggregation engine, and dispatches per matching object.
- [ ] 1.7 Threshold trigger support — for `trigger.type: "threshold"`, the installer subscribes to the existing aggregation cache invalidation event; when the referenced aggregation crosses the threshold, fire the notification.
- [ ] 1.8 Unit tests: every trigger type round-trips (annotation → webhook entity → fired event → delivery). Recipient resolver: every kind returns the expected uid list.
- [ ] 1.9 Integration test: declare a notification on a test schema; create an object that should fire it; verify the existing INotificationManager receives the notification + the webhook delivery job is queued.
- [ ] 1.10 Doc: `docs/annotations/x-openregister-notifications.md` — full reference covering trigger types, recipient kinds, channels, templates, throttling.
- [ ] 1.11 Migration helper: `occ openregister:reinstall-notifications <register> <schema>` — re-runs the installer (used after a schema update changes the notification list).
- [ ] 1.12 Update the platform-capabilities catalog to register `x-openregister-notifications` and link to the existing `notificatie-engine` for delivery semantics.
- [ ] 1.13 Add a CHANGELOG entry under "Unreleased → Added" when this change graduates from `proposed` to `implemented`. Entry covers: `x-openregister-notifications` annotation, the channel block format, the threshold/scheduled trigger types, and the auto-Webhook installer. (Spec-only PRs MAY defer the entry to the implementing PR; this task tracks the obligation.)
