# Tasks: Notification Engine — Scheduled Trigger Conditions & Per-Object Dedup

## Phase 1 — Filter operator evaluator

- [ ] 1.1 Add `lib/Service/Notification/ScheduledFilterEvaluator.php` with `matches(array $objectData, array $filter, \DateTimeImmutable $now): bool`: scalar entries keep strict equality (v1, byte-for-byte); operator objects `{"operator","value"}` implement `equals`, `notEquals` (missing/null field satisfies `notEquals` for non-null value), `withinNext` (field date in `(now, now+duration]`), `olderThan` (field date before `now-duration`); AND across entries; fail closed + debug log on unparsable object date values.
- [ ] 1.2 Refactor `ScheduledNotificationJob::fire()` to use the evaluator with one `now` per scan (drop the private `matchesFilter()`); keep `isDue()`/`markFired()` per-rule throttling unchanged.
- [ ] 1.3 Unit tests `tests/Unit/Service/Notification/ScheduledFilterEvaluatorTest.php`: scalar back-compat, each operator happy/negative path, window boundaries (exactly `now`, exactly `now+duration`), date-only vs date-time values, missing field, unparsable value fail-closed, AND combination.

## Phase 2 — Save-time grammar validation

- [ ] 2.1 Extend `lib/Service/Notification/NotificationAnnotationValidator.php`: for `scheduled` triggers, validate each filter operator object (known operator; `value` present; ISO-8601 `DateInterval`-parseable duration for `withinNext`/`olderThan`) and `trigger.dedupeFields` (non-empty array of strings when present); reject with HTTP 422 + structured error `{code, ruleKey, field, value}` mirroring the throttle-window-grammar contract; scalar entries always pass.
- [ ] 2.2 Unit tests: unknown operator 422, bad duration 422 (`"24h"`), missing value 422, malformed `dedupeFields` 422, valid mixed scalar+operator filter accepted.

## Phase 3 — Per-object dedup state

- [ ] 3.1 Migration: `oc_openregister_notification_dedupe` (`schema_id`, `rule_key`, `object_uuid`, `fingerprint`, `dispatched_at`, `seen_at`; unique index on schema_id+rule_key+object_uuid; index on object_uuid; index on seen_at).
- [ ] 3.2 Add `lib/Db/NotificationDedupeState.php` + `NotificationDedupeStateMapper.php` (find by key triple, upsert, deleteByObject, deleteByRule, deleteSeenBefore).
- [ ] 3.3 Wire dedup into `ScheduledNotificationJob::fire()`: compute fingerprint (SHA-1 of JSON-encoded watched-field values; watched fields = relative-date-operator fields, overridden by `trigger.dedupeFields`, else constant), dispatch only when no row or fingerprint differs, upsert after dispatch, touch `seen_at` on fingerprint-equal match.
- [ ] 3.4 Pruning: delete dedup rows on object purge (hook into the existing object-deletion path), on annotation save for removed rule keys (schema-save diff), and via a retention sweep in the job run (`seen_at` older than `notification_dedupe_retention_days`, default 90).
- [ ] 3.5 Unit tests: first-match dispatch + row written, fingerprint-equal skip, fingerprint-change re-arm exactly once, `dedupeFields` override, constant-fingerprint fire-once, prune-by-object / prune-by-rule / retention sweep, durability (state read from DB after simulated cache flush).

## Phase 4 — Spec, integration, docs

- [ ] 4.1 Sync the `notificatie-engine` delta (`specs/notificatie-engine/spec.md`) into `openspec/specs/notificatie-engine/spec.md` on archive; give the `scheduled` trigger its own normative section.
- [ ] 4.2 Integration test (PHPUnit, magic-table backed): hourly rule with `withinNext PT24H` + `notEquals done` over a seeded population — assert exactly one notification per object per due date across three simulated scans, re-arm on due-date change, no dispatch for `done` objects.
- [ ] 4.3 Update `docs/` notification-engine page: operator grammar table, dedup semantics (`dedupeFields`, re-arm, retention), worked `taskDueSoon`-style example; note that `updated` field-change conditions already exist and are documented separately.
- [ ] 4.4 Notify consumers: comment on planix `due-date-reminder-dispatch` (BLOCKED_EXTERNAL → unblocked once released) and mark the fleet-notification-plan `updated`-trigger prerequisite as already-closed with this change's citation. Bump `appinfo/info.xml` `<version>`.
