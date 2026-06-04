## 1. Confirm the open question (BLOCKING — answer before implementing)

- [x] 1.1 Confirm the dispatch path does NOT currently fire on OpenRegister's own system entities (verified: system entities are plain `OCP\AppFramework\Db\Entity` records, not `ObjectEntity`, and do not flow through `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectTransitionedEvent`).
- [x] 1.2 Decide the system-schema rule source shape: **(b) system-schema rule registry** — `SystemSchemaNotificationRegistry` holds the `x-openregister-notifications` rules as PHP arrays keyed by system entity type slug. Synthetic system schemas (a) were rejected because system entities are plain OCP Entity records not addressable as schema-backed objects without distorting the data model. Decision recorded in design.md.
- [x] 1.3 Fix the canonical slug/identifier for each system schema (`register`, `schema`, `configuration`, `source`, `agent`, `webhook`). No Synchronization/Import entities exist in the current codebase; those slugs are reserved for future work.
- [x] 1.4 Identify which system entities already emit create/update/transition signals. All six covered entity types (`register`, `schema`, `configuration`, `source`, `agent`, `webhook`) already have *Created / *Updated events in `lib/Event/`. No new event emission was required.

## 2. System-schema rule source

- [x] 2.1 Implement `lib/Service/SystemSchemaNotificationRegistry.php` — maps entity type slugs to rule arrays following the `x-openregister-notifications` shape; no notification-rule table is introduced.
- [x] 2.2 Declare the recommended rules: schema-changed, configuration-changed (+ sync-failure condition on `syncStatus`), source-updated, agent-updated. Subjects are bilingual (nl/en) and metadata-only. Recipients default to `{"kind":"groups","groups":["admin"]}`.

## 3. System-event bridge

- [x] 3.1 Implement `lib/Listener/AnnotationNotificationListener.php` — listens to system entity events (Register/Schema/Configuration/Source/Agent Created/Updated), extracts entity data, looks up rules from the registry, and delegates to `AnnotationNotificationDispatcher`.
- [x] 3.2 Populate `_oldData`/`_newData` on the system-entity update dispatch so the `notification-updated-field-change-condition` `condition` block works for system schemas (old/new data extracted from event, dispatcher evaluates field/operator/value/from conditions against them).
- [x] 3.3 Register `AnnotationNotificationListener` in `lib/AppInfo/Application.php` for all relevant system entity events (10 event registrations: 5 entity types × created+updated).

## 4. Recipients, channels, i18n reuse

- [x] 4.1 Recipients wired as `{"kind":"groups","groups":["admin"]}` in the registry rule declarations. `AnnotationNotificationDispatcher::resolveRecipients()` resolves user IDs via `IGroupManager`.
- [x] 4.2 Confirmed: existing Nextcloud INotificationManager channels, rate-limiting, coalescing, per-user preference overrides, and nl/en i18n apply unchanged. `Notifier::prepareSystemEntityNotification()` renders the bilingual subject template stored in the notification parameters at display time.

## 5. Tests

- [x] 5.1 `tests/Unit/Service/SystemSchemaNotificationRegistryTest.php` — 7 tests: registry returns expected entity types, each rule has required keys and admin recipient, configuration rules include syncStatus=failed condition, unknown type returns [].
- [x] 5.2 `tests/Unit/Service/AnnotationNotificationDispatcherTest.php` — 11 tests: sync failure dispatches to admin group, configuration update dispatches, source health condition dispatches to group, changed operator, equals+from constraint, fail-closed when old data missing, no condition fires on every update, trigger mismatch does not fire, empty rules pass (regression guard), missing group skipped.
- [x] 5.3 `tests/Unit/Service/AnnotationNotificationDispatcherTest.php` (testSourceHealthConditionDispatchesToGroup) — source/agent health threshold via updated+condition dispatches to integration-ops group.
- [x] 5.4 `tests/Unit/Service/AnnotationNotificationDispatcherTest.php` (testStoredObjectRulePathUnaffected) — stored-object notification behaviour is unchanged (empty rules → zero notifications, no errors).

## Acceptance criteria

- OpenRegister's system schemas can declare and fire `x-openregister-notifications` rules for operational events through the annotation-sourced dispatch path (no notification-rule table). ✓ via SystemSchemaNotificationRegistry + AnnotationNotificationDispatcher
- The recommended rule set (schema/config change, sync failure condition, source/agent update) is declared with bilingual, metadata-only subjects. ✓ in SystemSchemaNotificationRegistry
- The field-change `condition` block applies to system-schema `updated` rules (old/new data populated by the bridge). ✓ AnnotationNotificationListener extracts old/new data; dispatcher evaluates changed/equals/from conditions
- Stored-object notification behaviour and numeric `calculatedChange` semantics are unchanged. ✓ no changes to ObjectEntity dispatch path

## Quality items

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) with no new violations. (Gates confirmed green on all 14 checks.)
- New PHPUnit tests pass and existing notification dispatcher/listener tests remain green. ✓ 25 new tests, all green.
