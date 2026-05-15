# Tasks: Add Live Updates (notify_push backend)

## Capability: realtime-updates (notify_push transport)

### Task 1 — Investigate existing event class names and PermissionHandler API
Before implementing the listener, confirm:

1. Exact class names and constructor signatures of OR's object lifecycle events: read `lib/Event/ObjectCreatedEvent.php`, `lib/Event/ObjectUpdatedEvent.php`, `lib/Event/ObjectDeletedEvent.php`. Note how the event exposes the underlying `ObjectEntity` (likely `getObject(): ObjectEntity` or similar) and the `register` / `schema` references.
2. Existing listener pattern: read `lib/Listener/GraphQLSubscriptionListener.php` end to end — the new `NotifyPushListener` follows the same shape (constructor injection, `handle(Event $event): void`, RBAC-aware fan-out).
3. `PermissionHandler` reader-list method: read `lib/Service/PermissionHandler.php` and check whether a `getReadableByUsers(ObjectEntity $object): array<string>` method (or equivalent) exists. If it does not, add it as part of Task 3 — the implementation MUST use a query-based approach (DO NOT iterate all NC users with per-user permission checks). Look at how `GraphQLSubscriptionListener` performs RBAC filtering for reference.
4. Listener registration site: read `lib/AppInfo/Application.php` around the `GraphQLSubscriptionListener` registration to find the canonical place to register `NotifyPushListener`.

Document findings as a comment block at the top of `NotifyPushListener.php`.

### Task 2 — Create `lib/Push/PushEvents.php`
Create the constants class following the Deck pattern (`OCA\Deck\NotifyPushEvents`):

```php
namespace OCA\OpenRegister\Push;

class PushEvents {
    public const OR_OBJECT     = 'or-object';
    public const OR_COLLECTION = 'or-collection';
}
```

Include EUPL-1.2 SPDX docblock header (per project convention — SPDX inside the docblock, not as separate `// SPDX-...` lines). PHPDoc on each constant explaining the suffix pattern (`-{uuid}` / `-{register-slug}-{schema-slug}`).

### Task 3 — Add `PermissionHandler::getReadableByUsers()` if missing
Per Task 1 findings: if `PermissionHandler` does not yet expose a method returning the list of user IDs authorised to read a given `ObjectEntity`, add it. Implementation must be query-based (mirror `GraphQLSubscriptionListener`'s RBAC filtering approach), not iteration. If the method already exists under a different name, document the mapping in `NotifyPushListener` and skip this task.

### Task 4 — Create `lib/Listener/NotifyPushListener.php`
Implement `IEventListener` handling `ObjectCreatedEvent | ObjectUpdatedEvent | ObjectDeletedEvent`:

- Constructor: `IAppManager $appManager`, `LoggerInterface $logger`, `IServerContainer $container` (lazy IQueue resolution), `PermissionHandler $permissionHandler`, `IAppConfig $appConfig` (for the `push_available` flag set on first successful push).
- Static fields: `$batchMode`, `$batchedCollections`, `$seen` (per-request dedup accumulator).
- `handle(Event $event): void` — lazy-resolves `IQueue` from the container in a try/catch on `\Throwable` (soft-fail; at most one DEBUG log per request); determines `action`, `uuid`, `registerSlug`, `schemaSlug`, `version` from the event; runs dedup check on `(uuid, action)`; delegates to `dispatchPushes()` or accumulates in batch mode.
- `dispatchPushes(string $action, ObjectEntity $object, IQueue $queue): void` — resolves authorised users via `PermissionHandler::getReadableByUsers($object)`; emits `or-object-{uuid}` per user; emits `or-collection-{register-slug}-{schema-slug}` per user on create/delete only (NOT on update).
- `public static function setBatchMode(bool $enabled): void`
- `public static function flushBatch(IQueue $queue, PermissionHandler $permHandler): void` — emits one collection event per accumulated `(register, schema)` pair across all authorised users; clears static state.
- After the first successful `IQueue::push()` call in a request, set the `openregister.push_available` AppConfig key to `'1'` (only if not already set). This is what the admin settings page reads to display "active" state.

Payload structure: `['action' => $action, 'register' => $registerSlug, 'schema' => $schemaSlug, 'uuid' => $uuid, 'version' => $version]`

`IQueue::push` call: `$queue->push('notify_custom', ['userId' => $userId, 'data' => json_encode($payload)])`

### Task 5 — Register listener in `lib/AppInfo/Application.php`
Add three listener registrations next to the existing `GraphQLSubscriptionListener` registrations:

```php
$context->registerEventListener(ObjectCreatedEvent::class, NotifyPushListener::class);
$context->registerEventListener(ObjectUpdatedEvent::class, NotifyPushListener::class);
$context->registerEventListener(ObjectDeletedEvent::class, NotifyPushListener::class);
```

Add `use OCA\OpenRegister\Listener\NotifyPushListener;`.

### Task 6 — Add batch-mode wrap to bulk import path
Search OR's import services (likely `lib/Service/DataImportService.php` or similar) for the bulk-save loop. Wrap it:

```php
NotifyPushListener::setBatchMode(true);
try {
    // ... import loop
} finally {
    NotifyPushListener::flushBatch($queue, $permissionHandler);
    NotifyPushListener::setBatchMode(false);
}
```

If multiple bulk-import call sites exist, wrap each. If no clear single entry point, document the wrap pattern at each call site with a `@todo` referencing this task.

### Task 7 — Unit tests for `NotifyPushListener`
Create `tests/Unit/Listener/NotifyPushListenerTest.php`. Test scenarios (mock `IQueue`, `PermissionHandler`, `IServerContainer`):

- `testSoftFailWhenQueueNotResolvable()` — container throws, no exception propagated, no WARNING/ERROR log
- `testEmitsObjectEventOnUpdate()` — `IQueue::push` called with `or-object-{uuid}` for each authorised user; collection event NOT called
- `testEmitsCollectionEventOnCreate()` — both `or-object-*` and `or-collection-*` called per user
- `testEmitsCollectionEventOnDelete()` — same as create
- `testCollectionEventUsesSlugsNotIds()` — assert event string contains register slug + schema slug
- `testDedupPreventsDoubleEmit()` — same `(uuid, action)` pair fired twice; `IQueue::push` called once
- `testBatchModeSuppressesPerObjectPush()` — `setBatchMode(true)`, handle 10 events, no per-object pushes
- `testFlushBatchEmitsOneCollectionEvent()` — after 10 batched events, `flushBatch` emits one collection event per `(register, schema)` pair
- `testPushAvailableFlagSetOnFirstSuccess()` — AppConfig `setValueString('openregister', 'push_available', '1')` called once

### Task 8 — Unit tests for `PushEvents` constants
Create `tests/Unit/Push/PushEventsTest.php`. Verify:

- `OR_OBJECT === 'or-object'`
- `OR_COLLECTION === 'or-collection'`
- both constants are non-empty strings (typed `const`)

### Task 9 — Integration test: end-to-end push delivery (skipped if notify_push not in test env)
Add an integration test that exercises the full flow: create an object, assert that a `notify_custom` event was queued with the expected payload. If the test environment does not have notify_push, skip the test (mark it skipped, not failing). This validates the full path beyond the unit-mocked level.

---

## Capability: admin-settings (push status section)

### Task 10 — Add push status probe to `OpenRegisterAdmin`
Edit `lib/Settings/OpenRegisterAdmin.php`:

- Add constructor params `IAppManager $appManager` and `IAppConfig $appConfig` (or `IConfig` if that's what existing code uses — check first).
- Add private method `getPushStatus(): string` returning one of `'not_installed' | 'unreachable' | 'active'`:
  - `'not_installed'` when `$appManager->isInstalled('notify_push')` is `false`
  - `'active'` when AppConfig key `openregister.push_available` is `'1'`
  - `'unreachable'` otherwise (installed but no confirmed push yet)
- Pass `pushStatus` in the form data array returned by `getForm()`.
- MUST NOT instantiate `IQueue` during settings render (avoid exceptions in the UI when partial install).

### Task 11 — Update admin settings UI with Push notifications section
In the Vue admin settings component (search `src/` for the admin settings entry), add a "Push notifications" section:

- Renders a status badge from the `pushStatus` prop:
  - `not_installed` → red/grey "Realtime push not available — the notify_push app is not installed" + link to Nextcloud App Store notify_push listing
  - `unreachable` → amber "notify_push is installed but not yet active" + link to `https://github.com/nextcloud/notify_push#configuration`
  - `active` → green "Realtime push active" + no link
- Use NL Design System tokens (`var(--color-success)` / `var(--color-warning)` / `var(--color-error)`) — no hardcoded hex values.
- Include nl + en translations in `l10n/`.

---

## Documentation

### Task 12 — Create `docs/Integrations/Deck.md`
Write the Deck integration doc using the existing `docs/Integrations/n8n.md` format as a template. Sections:

- `## Overview` — what the Deck integration does
- `## Backend integration` — `DeckCardService` / `DeckController` overview
- `## Linking cards to objects` — how the link table works
- `## Push events` — table of Deck's `notify_custom` events. Read the canonical list from `custom_apps/deck/lib/NotifyPushEvents.php`. Document each as: `event string | fired when | payload fields`. Cover at minimum `deck_board_update` and `deck_card_update`.
- `## Subscribing from the frontend` — how `@conduction/nextcloud-vue` consumers can listen for Deck events on a given OR object (cross-link to the upcoming `add-live-updates-plugin` change in nextcloud-vue)

Reference `lib/Push/PushEvents.php` for OR's own event-string pattern as a contrast.

### Task 13 — Update `docs/Integrations/index.md`
Add a "Nextcloud-native integrations" subsection to the index and link to `Deck.md`. Place this subsection above the existing automation-platform sections (n8n, Windmill) since NC-native integrations are more common for OR consumers.

---

## Quality gates

### Task 14 — Run `composer check:strict` and fix all findings
After all PHP changes, run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan). Fix every finding before submitting — including any pre-existing issues encountered in files this change touches (per project convention: don't leave them for later).

### Task 15 — Run unit tests and confirm no regressions
Run `composer test:unit` (or the PHPUnit invocation from `phpunit.xml`). Confirm new tests pass and no existing tests regress.
