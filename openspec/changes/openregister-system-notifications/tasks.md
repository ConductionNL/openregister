## 1. Confirm the open question (BLOCKING — answer before implementing)

- [x] 1.1 Confirm the dispatch path does NOT currently fire on OpenRegister's own system entities (verified: system entities are plain `OCP\AppFramework\Db\Entity` records, not `ObjectEntity`, and do not flow through `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectTransitionedEvent`).
<<<<<<< HEAD
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
=======
- [x] 1.2 Decide the system-schema rule source shape: (a) synthetic schema-backed system schemas vs (b) a system-schema rule registry the dispatcher consults. Decision: **(b) system-schema rule registry** (`lib/Service/Notification/SystemSchemaRules.php`) — synthetic schema rows would require DB migrations and distort the data model. Recorded in design.md.
- [x] 1.3 Fix the canonical slug/identifier for each system schema: `openregister_register`, `openregister_schema`, `openregister_configuration`, `openregister_source`, `openregister_agent`, `openregister_webhook`.
- [x] 1.4 Identified existing events: Register/Schema/Configuration/Source/Agent all emit Created/Updated events. Synchronization and Import do NOT exist as DB entities in the current codebase — noted as out of scope for this iteration.

## 2. System-schema rule source

- [x] 2.1 Implemented `SystemSchemaRules` (`lib/Service/Notification/SystemSchemaRules.php`) with `buildSchema()` producing a synthetic Schema carrying the rules; `AnnotationNotificationDispatcher::dispatchWithSchema()` added as the rule-aware entry point.
- [x] 2.2 Declared rules: schema-changed, configuration-changed, source-unhealthy, source-changed, agent-unhealthy, agent-changed, register-changed, webhook-changed — all bilingual nl/en, metadata-only subjects, admin group recipients.

## 3. System-event bridge

- [x] 3.1 `SystemEntityNotificationListener` (`lib/Listener/SystemEntityNotificationListener.php`) routes create/update signals for Register, Schema, Configuration, Source, Agent through `dispatchWithSchema()`. Registered in `Application::registerEventListeners()`.
- [x] 3.2 Updated events populate `_oldData`/`_newData` in the dispatch context so the field-change `condition` block works for system schemas too. `ConfigurationUpdatedEvent` missing getters were added.
- [x] 3.3 Existing events are bridged. Synchronization/Import do not exist as DB entities in this codebase version; new event emission for those is deferred.

## 4. Recipients, channels, i18n reuse

- [x] 4.1 All system-schema rules declare `{"kind":"groups","groups":["admin"]}` recipients; channels default to `nc-notification`.
- [x] 4.2 Confirmed: `dispatchWithSchema()` reuses the identical pipeline (rate-limiting, coalescing, preference overrides, nl/en i18n) as `dispatch()`.

## 5. Tests

- [x] 5.1 Unit test: `SystemEntityNotificationListenerTest::testSourceCreatedDispatchesViaAnnotationPath` — system source creation dispatches to the admin group via the dispatcher path.
- [x] 5.2 Unit test: `SystemEntityNotificationListenerTest::testConfigurationUpdateDispatchesUpdatedTrigger` — configuration update dispatches an `updated` rule to the admin group.
- [x] 5.3 Unit test: `SystemEntityNotificationListenerTest::testSourceUpdatedDispatchesWithOldAndNewData` — source update dispatches with old/new data for field-change condition evaluation.
- [x] 5.4 Unit test: `SystemEntityNotificationListenerTest::testUnknownEventDoesNotCallDispatcher` and `testStoredObjectEventsAreNotHandledBySystemListener` — stored-object behaviour unchanged; `SystemSchemaRulesTest::testGetRulesReturnsDeclaredRulesForKnownSlugs` confirms rule isolation.

## Acceptance criteria

- OpenRegister's system schemas can declare and fire `x-openregister-notifications` rules for operational events through the existing annotation-sourced dispatch path (no notification-rule table).
- The recommended rule set (sync/import failure, schema/config change, source/agent health) is declared with bilingual, metadata-only subjects.
- The field-change `condition` block applies to system-schema `updated` rules (old/new data populated by the bridge).
- Stored-object notification behaviour and numeric `calculatedChange` semantics are unchanged.

## Quality items

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) with no new violations.
- New PHPUnit tests pass and existing notification dispatcher/listener tests remain green.
>>>>>>> origin/development
