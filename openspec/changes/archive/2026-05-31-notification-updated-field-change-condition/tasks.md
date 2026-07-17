## 1. Listener: forward old/new data on the `updated` dispatch

- [x] 1.1 In `AnnotationNotificationListener::handle()`, pass `_newData`/`_oldData` context on the plain `updated` dispatch (when an old object is available), mirroring the existing `calculatedChange` dispatch.

## 2. Dispatcher: evaluate the field-change condition

- [x] 2.1 In `AnnotationNotificationDispatcher::matches()`, add an `updated`-trigger branch that engages only when the `trigger` block declares a `condition`.
- [x] 2.2 Read `_oldData`/`_newData` from context in that branch; when either is not an array, return false (fail-closed).
- [x] 2.3 Add a private string-condition evaluator (e.g. `fieldChangeConditionMatches()`) beside `numericConditionMatches()` that compares the field's old vs new value.
- [x] 2.4 Implement operator `changed`: matches only when the old value differs from the new value (string-normalised comparison).
- [x] 2.5 Implement operator `equals`: matches only when the new value equals `value`; when optional `from` is present, also require the old value to equal `from`.
- [x] 2.6 Ensure a `condition`-less `updated` rule skips the evaluator entirely and matches on trigger-type alone (back-compat).

## 3. Unit tests

- [x] 3.1 `changed` fires when old != new; does NOT fire when old == new.
- [x] 3.2 `equals` fires when new == value; does NOT fire when new != value.
- [x] 3.3 `equals` with `from` fires only on the declared `from` -> `value` transition; does NOT fire when the prior value differs.
- [x] 3.4 Missing `_oldData`/`_newData` -> a `condition`-bearing rule does NOT fire (fail-closed).
- [x] 3.5 A `condition`-less `updated` rule still fires on every update.
- [x] 3.6 Listener test: the `updated` dispatch receives `_newData`/`_oldData` when an old object is present.

## Acceptance criteria

- The `updated` trigger accepts an optional non-numeric field-change `condition` (`changed`, `equals` with optional `from`), evaluated against old-vs-new object data.
- Condition-bearing rules fail closed when old/new data is absent, matching `calculatedChange`.
- Condition-less `updated` rules are unchanged and fire on every update.
- `calculatedChange` / `numericConditionMatches()` numeric semantics are untouched.
- No new schemas, tables, entities, or migrations are introduced.

## Quality items

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) with no new violations.
- New PHPUnit tests pass and existing notification dispatcher tests remain green.
- No regressions verified against opencatalogi and softwarecatalog notification rules.
