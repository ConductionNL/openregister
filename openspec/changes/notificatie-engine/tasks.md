# Tasks: Notificatie Engine

> **Status:** This spec overlaps with `notifications-v2` (closed). The foundational notification rule engine is shipped; advanced delivery features (batching/digest, preferences UI, VNG API, history table, grouping, read/unread, NL/EN i18n, org-pinning) are not. 6 of 14 tasks are tickably complete; 8 left open.

## Implemented (via notifications-v2)

- [x] **The system MUST integrate with Nextcloud's INotificationManager for in-app notifications.** `AnnotationNotificationDispatcher::emitNotification` (channel `nc-notification`) — verified by `tests/Unit/Service/Notification/AnnotationNotificationDispatcherTest`.

- [x] **The system MUST support configurable notification rules per schema.** Schemas declare rules under `configuration['x-openregister-notifications']`. Five trigger types (created, updated, transition, scheduled, threshold), six recipient kinds (users, field, groups, relation, object-acl, expression), five channels (nc-notification, email, activity, webhook, talk). Verified by the validator + dispatcher integration tests in the notifications-v2 test suite.

- [x] **Notifications MUST support per-register and per-schema channel subscriptions.** Per-rule `channels: [...]` declares which channels fire for that specific notification.

- [x] **Notification delivery MUST be reliable with retry and dead-letter handling.** Webhook channel: when `webhook.persistent: true`, `NotificationsAnnotationInstaller` creates a managed `Webhook` entity that flows through the standard delivery pipeline (exponential retry with maxRetries=5, dead-letter on the existing webhook log). In-app / email / activity channels rely on Nextcloud's own retry semantics — those subsystems aren't OpenRegister code.

- [x] **The notification engine MUST support event-driven trigger types beyond CRUD.** `scheduled` (interval-based via `ScheduledNotificationJob`) and `threshold` (aggregation-crossing via `AggregationThresholdListener`) are implemented in addition to the lifecycle-CRUD triggers.

## Open / partial

- [ ] **Notifications MUST support batching and digest delivery.** Not implemented — every notification fires its own dispatch. **Open** — would need a `BatchNotificationJob` + per-recipient digest queue.

- [ ] **Users MUST be able to manage their notification preferences.** Partial — Nextcloud's INotificationManager respects per-user notification settings in the NC settings UI; OpenRegister doesn't add register-/schema-level subscriptions on top. **Open** — UX feature for "subscribe to notifications from this register".

- [ ] **The system MUST support VNG Notificaties API compliance.** Partial — webhook channel emits a generic JSON envelope. The VNG Notificaties API standard requires specific Dutch government schema (kanaal, hoofdObject, resource, resourceUrl, actie, kenmerken). **Open** — would need a `vng-notificaties` channel adapter that maps OR's payload to the VNG envelope.

- [ ] **Notifications MUST be scoped to organisations for multi-tenant deployments.** Partial — RBAC + multi-tenancy already constrains which objects a user sees, so notifications fired against an object are implicitly org-scoped via the recipient resolver (recipients are looked up against the active org). A separate `organisation` field on the rule itself isn't supported. **Open** — explicit org-pinning.

- [ ] **Notification history MUST be stored and queryable for audit purposes.** Partial — webhook deliveries are logged in `oc_openregister_webhook_logs`; in-app / email / talk dispatches aren't recorded in a dedicated history table. The `oc_activity` stream captures *what* happened to the object but not which notification rules fired. **Open** — `oc_openregister_notification_history` table + query API.

- [ ] **Notification messages MUST support i18n in Dutch and English.** Partial — `subject` template is a single-language string. Mustache interpolation (`{{title}}`) works, but no per-locale subject variants are supported. **Open** — gated on the `register-i18n` spec.

- [ ] **Notification grouping MUST reduce noise for related events.** Not implemented — every event fires its own notification. **Open** — would need a debounce/coalesce layer (e.g. "5 actions in 1 minute → one digest notification").

- [ ] **Read/unread tracking MUST be maintained per user per notification.** Nextcloud's INotificationManager handles this for the `nc-notification` channel — read/unread state is tracked in `oc_notifications`. Other channels (email, activity, webhook, talk) don't have a per-user-per-notification read state because they're fire-and-forget. **Open** — depends on whether read/unread for non-in-app channels is meaningful.

- [x] **Notification rate limiting MUST prevent abuse and system overload.** `RateLimiter` (`lib/Service/Notification/RateLimiter.php`) implements a token-bucket rate limit per (rule, recipient) pair, backed by the distributed cache. Default bucket size 10, refill 1 token/60s; per-rule overrides via `rateLimit: {bucketSize, refillSecondsPerToken}` on the notification spec; operator overrides via app-config keys `notification_rate_limit_default_bucket_size` and `notification_rate_limit_default_refill_seconds`; kill switch `notification_rate_limit_enabled = false`. Drops are logged at info level. Wired into `AnnotationNotificationDispatcher` per recipient (and once per dispatch for one-shot webhook/talk channels). Verified by `tests/Unit/Service/Notification/RateLimiterTest` (8 tests covering bucket-drain, refill, per-(rule,recipient) isolation, kill switch, app-config defaults, fail-open paths, info-not-warning logging).

## Test coverage

The implemented portions are covered by the `notifications-v2` test suite:

- `tests/Unit/Service/Notification/NotificationAnnotationValidatorTest` — 21 tests covering all trigger types, recipient kinds, and channels.
- `tests/Unit/Service/Notification/AnnotationNotificationDispatcherTest` — 9 tests covering dispatch logic per channel.
- `tests/Unit/Service/Notification/NotificationsAnnotationInstallerTest` — 8 tests covering webhook auto-create.
- `tests/Unit/BackgroundJob/ScheduledNotificationJobTest` — 8 tests covering scheduled trigger evaluation.
- `tests/Unit/Listener/AggregationThresholdListenerTest` — 5 tests covering threshold transition logic.
- `tests/Unit/Service/Notification/RateLimiterTest` — 8 tests covering token-bucket drain/refill, per-(rule, recipient) isolation, kill switch, app-config defaults, fail-open behaviour on cache failure / empty inputs, and info-level (not warning) drop logging.
