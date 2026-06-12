## 1. Confirm the open question (BLOCKING — answer before implementing)

- [x] 1.1 Confirm the dispatch path does NOT currently fire on OpenRegister's own system entities (verified: system entities are plain `OCP\AppFramework\Db\Entity` records, not `ObjectEntity`, and do not flow through `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectTransitionedEvent`).
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
