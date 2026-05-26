## 1. Confirm the open question (BLOCKING — answer before implementing)

- [ ] 1.1 Confirm the dispatch path does NOT currently fire on OpenRegister's own system entities (verified: system entities are plain `OCP\AppFramework\Db\Entity` records, not `ObjectEntity`, and do not flow through `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectTransitionedEvent`).
- [ ] 1.2 Decide the system-schema rule source shape: (a) synthetic schema-backed system schemas vs (b) a system-schema rule registry the dispatcher consults. Record the decision in design.md.
- [ ] 1.3 Fix the canonical slug/identifier for each system schema (`register`, `schema`, `configuration`, `source`, `synchronization`, `import`, `webhook`, `agent`).
- [ ] 1.4 Identify which system entities already emit create/update/transition signals vs need new event emission (esp. Synchronization/Import run outcomes, Source/Agent health).

## 2. System-schema rule source

- [ ] 2.1 Implement the chosen rule source (a or b) so the dispatcher can resolve `x-openregister-notifications` for a system entity through the existing annotation-sourced path — no notification-rule table.
- [ ] 2.2 Declare the recommended rules on the system schemas: synchronization-failed, import-failed, schema-changed, configuration-changed, source-unhealthy, agent-unhealthy (bilingual nl/en, metadata-only subjects).

## 3. System-event bridge

- [ ] 3.1 Route create/update/transition signals for the relevant system entities through `AnnotationNotificationListener` → `AnnotationNotificationDispatcher`.
- [ ] 3.2 Populate `_oldData`/`_newData` on the system-entity update dispatch so the `notification-updated-field-change-condition` `condition` block works for system schemas.
- [ ] 3.3 Emit the missing signals where a system entity does not yet fire one (sync/import run outcomes, source/agent health).

## 4. Recipients, channels, i18n reuse

- [ ] 4.1 Wire recipients: `{"kind":"groups","groups":["admin"]}` / integration-ops group and schema/config owners (`{"kind":"object-acl","permission":"manage"}` or owner field).
- [ ] 4.2 Confirm the existing channels, rate-limiting, coalescing, per-user preference overrides and nl/en i18n apply unchanged to system-schema dispatches.

## 5. Tests

- [ ] 5.1 Unit test: a system synchronization failure dispatches a notification to the admin group via the existing dispatcher path.
- [ ] 5.2 Unit test: a configuration update dispatches an `updated` rule to the admin group.
- [ ] 5.3 Unit test: a source/agent health threshold (or `updated`+condition) dispatches to integration-ops.
- [ ] 5.4 Unit test: stored-object notification behaviour is unchanged (no regression on user-schema rules).

## Acceptance criteria

- OpenRegister's system schemas can declare and fire `x-openregister-notifications` rules for operational events through the existing annotation-sourced dispatch path (no notification-rule table).
- The recommended rule set (sync/import failure, schema/config change, source/agent health) is declared with bilingual, metadata-only subjects.
- The field-change `condition` block applies to system-schema `updated` rules (old/new data populated by the bridge).
- Stored-object notification behaviour and numeric `calculatedChange` semantics are unchanged.

## Quality items

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) with no new violations.
- New PHPUnit tests pass and existing notification dispatcher/listener tests remain green.
