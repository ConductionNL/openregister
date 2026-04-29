# Tasks — Notifications v2

## Validator vocabulary

- [ ] 1.1 Extend `VALID_TRIGGERS` to include `scheduled` and `threshold`.
- [ ] 1.2 Extend `VALID_RECIPIENT_KINDS` to include `object-acl` and `expression`.
- [ ] 1.3 Extend `VALID_CHANNELS` to include `talk`.
- [ ] 1.4 New shape rules:
  - `trigger.type: scheduled` requires a `cron` string (validated against the standard 5-field cron grammar).
  - `trigger.type: threshold` requires `aggregation` (must reference a name declared in the schema's `x-openregister-aggregations`), `op` (`gt`/`gte`/`lt`/`lte`/`eq`), and `value` (number).
  - `channel: talk` requires `talk.token` (string identifying the Talk conversation).
  - `channel: webhook` AND `webhook.persistent: true` requires `webhook.events` (array) on top of the existing `webhook.url`.
  - `recipient.kind: expression` requires `resolver` (DI tag string).
  - `recipient.kind: object-acl` requires `permission` (`read`/`manage`).

## Installer service

- [ ] 2.1 Create `lib/Service/Notification/NotificationsAnnotationInstaller.php`. Constructor: `WebhookMapper`, `IJobList`, `LoggerInterface`, current notification annotation reader.
- [ ] 2.2 `installSchema(Schema $schema)`: iterate `x-openregister-notifications`; for each entry:
  - if scheduled → register a `BackgroundJob` of class `ScheduledNotificationJob` with arguments `{schemaId, notificationName}`. Skip if already present (idempotent).
  - if threshold → register a `BackgroundJob` for `AggregationThresholdJob` (lighter than a listener; runs at the cache TTL boundary).
  - if webhook persistent → upsert `Webhook` entity by `name=or-notif-{schemaSlug}-{notificationName}`.
- [ ] 2.3 `uninstallSchema(Schema $schema)`: remove jobs / webhooks for any notification name no longer in the annotation.
- [ ] 2.4 Wire to `SchemaCreatedEvent` + `SchemaUpdatedEvent` listeners in `Application::registerEventListeners()`.

## Scheduled trigger

- [ ] 3.1 Create `lib/BackgroundJob/ScheduledNotificationJob.php` extending `OCP\BackgroundJob\TimedJob`. Constructor takes `SchemaMapper`, `MagicMapper`, `AnnotationNotificationDispatcher`, `LoggerInterface`.
- [ ] 3.2 Implement `run()`:
  - Look up schema by id (from job arguments).
  - Read notification spec by name.
  - Run `findObjects(filter)` → list of matching objects.
  - For each matching object, call `$dispatcher->dispatch(object, trigger: 'scheduled', context: {})`.
- [ ] 3.3 Dispatcher: extend `matches()` to return true when `trigger.type === 'scheduled'` and the dispatch was called with `trigger === 'scheduled'`. (Other event-driven dispatch calls won't match.)
- [ ] 3.4 Unit tests: cron parsing edge cases (`@daily`, `0 9 * * *`, malformed), filter execution, idempotency on multi-run.

## Threshold trigger

- [ ] 4.1 Create `lib/EventListener/AggregationThresholdListener.php`. Subscribes to the aggregation cache invalidation event published by `aggregations-annotation` (currently lives in `AggregationInvalidationListener`).
- [ ] 4.2 On invalidation: re-run the referenced aggregation, compare the previous cached value to the new value, fire the notification if the threshold was crossed in this cycle.
- [ ] 4.3 Dispatcher: extend `dispatch()` to accept a `'threshold'` trigger; `context` carries `{aggregation, previousValue, newValue}`. Subject template can interpolate `{{newValue}}` etc.
- [ ] 4.4 Unit tests: simulate value moving across the threshold (and back); verify the notification fires only on the crossing edge.

## Webhook auto-create entity

- [ ] 5.1 Add `webhook.persistent: bool` and `webhook.events: array<string>` to the spec shape. When `persistent === true`, the installer creates a `Webhook` entity instead of relying on the dispatcher's fire-and-forget POST.
- [ ] 5.2 Installer: builds a `Webhook` payload with `name=or-notif-{schemaSlug}-{notificationName}`, `url`, `events`, `headers`, `payloadTemplate` (Twig template that mirrors the v1 dispatcher's JSON shape), HMAC config from `webhook.secret`, retry from `webhook.retry`, dead-letter from `webhook.dlq`.
- [ ] 5.3 Dispatcher: when `channel === 'webhook'` AND `webhook.persistent === true`, skip the inline POST and let the standard `WebhookService::dispatchEvent` pipeline handle delivery (it already runs on the same event types we care about).
- [ ] 5.4 Idempotency: re-saving the schema with the same notification name must NOT create duplicate Webhook entities.

## Recipient kinds: object-acl + expression

- [ ] 6.1 Dispatcher: `resolveRecipients()` gains a branch for `kind === 'object-acl'`. Reads the object's ACL via `OrObjectAclMapper::findByObject($uuid)`; returns every uid (and resolves group memberships) holding the requested `permission`. Falls back to `[]` when ACL is not configured.
- [ ] 6.2 Dispatcher: `resolveRecipients()` gains a branch for `kind === 'expression'`. Resolves `resolver` DI tag through `\OC::$server` (server container, autowired). The resolved class must implement `RecipientResolverInterface`. Calls `$resolver->resolve($object, $context): string[]`.
- [ ] 6.3 Public contract: `lib/Service/Notification/RecipientResolverInterface.php`.
- [ ] 6.4 Unit tests: object-acl with read/manage filters; expression resolver registered + invoked correctly; resolver class missing fails closed.

## Talk channel

- [ ] 7.1 Dispatcher: when `channel === 'talk'`, emit a chat message via the NC Talk REST API at `POST /ocs/v2.php/apps/spreed/api/v1/chat/{token}`. Body: `{message: rendered, actorType: 'bot' or 'user'}`. Skip silently when Talk app is not enabled.
- [ ] 7.2 Talk message is a one-shot per dispatch (not per recipient). Recipients in the spec still get nc-notification / email if those channels are also declared.
- [ ] 7.3 Unit tests: spec with `channels: ["nc-notification","talk"]` fires both; spec with `channels: ["talk"]` only fires the chat message.

## Documentation

- [ ] 8.1 Update `docs/annotations/x-openregister-notifications.md` with v2 shapes (scheduled cron, threshold op+value, persistent webhook events, object-acl permission, expression resolver tag, talk token).
- [ ] 8.2 Update `openspec/platform-capabilities.md` `x-openregister-notifications` row to reflect the v2 status.

## Live verification

- [ ] 9.1 Decidesk pilot: declare a `meetingReminderDaily` scheduled notification on the Meeting schema (cron `0 9 * * *`, filter `lifecycle: scheduled`, recipient `field: chair`, channel `nc-notification`). Verify the BackgroundJob registers and fires.
- [ ] 9.2 Decidesk pilot: declare a `tooManyOverdue` threshold notification on the ActionItem schema (aggregation `totalOverdue`, op `gt`, value `10`, recipient `groups: [admin]`). Create enough overdue items to cross the threshold; verify single fire.
- [ ] 9.3 Decidesk pilot: declare a persistent `webhook` channel for `meetingClosed`. Verify a `Webhook` entity exists and the message gets delivered via the standard webhook pipeline (with retry on transient failure).
