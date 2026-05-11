# Design — Notifications v2

## Approach
Sit on top of the v1 dispatcher (`AnnotationNotificationDispatcher`) and validator (`NotificationAnnotationValidator`). v2 features are additive: every change extends the validator's vocabulary (new trigger type / recipient kind / channel) and adds a new branch in the dispatcher. No v1 wire shape changes.

The two heaviest features (scheduled + threshold + persistent webhooks) need a new `NotificationsAnnotationInstaller` service that runs at schema-save time. It reads each declared notification and, for the subset that needs install-time setup (cron BackgroundJob registration, Webhook entity upsert, threshold-listener registration), persists the right artifacts.

## Files Affected
- `lib/Service/Notification/NotificationAnnotationValidator.php` — extend `VALID_TRIGGERS` (+ scheduled, threshold), `VALID_RECIPIENT_KINDS` (+ object-acl, expression), `VALID_CHANNELS` (+ talk). New shape rules: `scheduled` trigger requires `cron`; `threshold` trigger requires `aggregation` + `op` + `value`; `talk` channel requires `talk.token`.
- `lib/Service/Notification/AnnotationNotificationDispatcher.php` — new branches in `dispatch()` for the threshold path (when called from threshold event handler with `context.aggregation`); new branches in `resolveRecipients()` for `object-acl` + `expression`; new emitter for `talk` channel.
- `lib/Service/Notification/NotificationsAnnotationInstaller.php` (new) — runs on `SchemaCreatedEvent` + `SchemaUpdatedEvent`. For each notification:
  - if `trigger.type === "scheduled"`: register/refresh a `BackgroundJob` keyed on `(schemaId, notificationName)`.
  - if `trigger.type === "threshold"`: register/refresh a listener keyed on the referenced aggregation.
  - if `channel === "webhook"` and `webhook.persistent === true`: upsert a `Webhook` entity with mapping derived from the spec.
- `lib/BackgroundJob/ScheduledNotificationJob.php` (new) — runs the scheduled trigger: executes the notification's `filter` via `findObjects`, calls `dispatcher->dispatch()` once per matching object.
- `lib/EventListener/AggregationThresholdListener.php` (new) — subscribes to the aggregation cache invalidation event. Compares previous vs new value, fires the notification when the threshold is crossed.
- `lib/Service/Notification/RecipientResolverInterface.php` (new) — public contract for app-side `expression` recipient resolvers.
- `lib/AppInfo/Application.php` — register installer + listener; `registerNotifierService` for the new app-side resolvers via DI tags.

## Out of scope
- Cron-expression linting (the BackgroundJob crashes loudly if the cron is malformed; that's acceptable signal).
- Threshold debouncing across rapid invalidation events — first crossing fires, subsequent ones within the same event cycle are coalesced naturally by the cache.
- Talk-channel reactions / reply chains; v2 sends a one-shot message.
