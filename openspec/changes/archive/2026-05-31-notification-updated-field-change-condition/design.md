## Context

The `notificatie-engine` dispatcher (`lib/Service/Notification/AnnotationNotificationDispatcher.php`) reads the `x-openregister-notifications` schema annotation and fires Nextcloud notifications when a triggering event matches. Its `matches()` method handles four trigger types: `created`, `updated`, `transition`, and `calculatedChange`. Today the `updated` trigger fires on *every* update unconditionally, and the only conditional path — `calculatedChange` — is numeric-only (`numericConditionMatches()` casts to float for ordering operators). The single most-requested fleet pattern is "notify when a field *changed to X*" (status moved, lead won/lost, assignee re-assigned). That is a non-numeric field-change check, which the engine cannot express today.

The listener (`lib/Listener/AnnotationNotificationListener.php`) already has both the old and new object on `ObjectUpdatedEvent` (`getOldObject()` / `getNewObject()`). It already passes `_oldData`/`_newData` into the dispatch context for the `calculatedChange` trigger, but the plain `updated` dispatch is called with no context. So the data the new condition needs is already in hand — it just isn't forwarded on the `updated` path.

## Goals / Non-Goals

**Goals:**
- Add an optional non-numeric field-change `condition` to the `updated` trigger: `changed`, `equals` (with optional `from`).
- Evaluate the condition against the same old-vs-new object data the engine already uses for `calculatedChange`.
- Fail closed when old/new data is absent, mirroring `calculatedChange`.
- Preserve back-compat: condition-less `updated` rules keep firing on every update.

**Non-Goals:**
- No new numeric operators or changes to `calculatedChange`/`numericConditionMatches()` semantics.
- No new schemas, DB tables, entities, or migrations — this is engine code interpreting a declarative annotation.
- No multi-field / boolean-combinator conditions (single `field` + single `operator` only in this change).
- No changes to recipient resolution, channels, rate-limiting, coalescing, or preferences.

## Declarative-vs-imperative

Per ADR-031, schema-declared behaviour is the declarative source of truth and imperative code is justified only where it *interprets* that declaration. This change is squarely on the justified side: the `condition` block is authored declaratively inside the `x-openregister-notifications` annotation (`{"field": "status", "operator": "changed"}`), exactly like the existing `calculatedChange` `condition`/`previously` blocks. The PHP we add is the *engine* that reads and evaluates those declarations at dispatch time — it introduces no new imperative business rule of its own. No schema author writes PHP; they only add a declarative `condition` to their annotation. The imperative evaluator is the interpreter for a declarative DSL, which ADR-031 explicitly permits for engine code.

## Seed Data

No seed data, no new schemas, no new tables. This change is pure engine code: it extends `matches()` (an evaluator) and forwards already-available data through the listener's `updated` dispatch. There is nothing to seed — the feature activates purely by schema authors adding an optional `condition` block to an existing `updated` rule in their own schema annotation. Existing seeded schemas are unaffected (condition-less rules behave exactly as before).

## Decisions

**Decision: Add a string-condition evaluator beside `numericConditionMatches()`, do not overload it.**
`numericConditionMatches()` casts to float for `lt`/`lte`/`gt`/`gte` and to string for `eq`/`ne`; its contract is numeric thresholds. The new operators (`changed`, `equals` + optional `from`) are non-numeric string comparisons over an old/new pair, not a single value against a threshold. Overloading the numeric evaluator would muddy both contracts. Instead, `matches()` gains an `updated`-branch that, when the `trigger` declares a `condition`, reads `_oldData`/`_newData` from context and delegates to a new small private string-condition evaluator (e.g. `fieldChangeConditionMatches()`). *Alternative considered:* reuse `calculatedChange` entirely by aliasing — rejected because `calculatedChange` is a separate trigger type with `condition`+`previously` numeric semantics, and apps want the plain `updated` event (which already fires for every save) to carry the field-change filter without re-declaring as `calculatedChange`.

**Decision: Forward `_oldData`/`_newData` on the plain `updated` dispatch in the listener.**
`AnnotationNotificationListener::handle()` already extracts both objects and already builds the `_newData`/`_oldData` context for the `calculatedChange` dispatch. Extend the `updated` dispatch call to pass the same context (when an old object is available). This is the minimal, symmetric change — the data is already in hand. *Alternative considered:* have the dispatcher re-read versioned history to reconstruct the old value — rejected as redundant I/O when the listener already holds the old object.

**Decision: Fail closed when old/new data is absent.**
When `_oldData`/`_newData` are not arrays in the context (e.g. no previous object, or a non-update code path that reuses the dispatcher), a `condition`-bearing `updated` rule MUST NOT fire. This mirrors the existing `calculatedChange` guard (`is_array($newData) === false || is_array($oldData) === false` → return false). Failing closed avoids spurious fires when the engine cannot actually evaluate the declared condition.

**Decision: Condition-less `updated` rules stay unconditional (back-compat).**
The new logic only engages when the `trigger` block contains a `condition` key. A rule with no `condition` skips the evaluator entirely and matches on trigger-type alone, exactly as today. No existing rule changes behaviour.

## Risks / Trade-offs

- **Risk: a non-update caller invokes the dispatcher with `updated` but no old/new context, silently suppressing a condition rule.** → Mitigated by the fail-closed contract being explicit in the spec scenario and unit-tested; condition-less rules (the legacy shape) are unaffected.
- **Risk: type coercion mismatch between old/new values (e.g. `"1"` vs `1`) causes `changed` to mis-fire.** → Mitigated by defining `changed`/`equals` as string-normalised comparison in the evaluator, consistent with how `numericConditionMatches()` already string-casts for `eq`/`ne`; documented in the evaluator docblock.
- **Trade-off: single-field, single-operator only.** Multi-field / AND-OR composition is deferred; acceptable because the fleet demand (status/assignee change) is single-field. If composition is later needed it extends the same evaluator without breaking this shape.

## Migration Plan

No migration. Engine-only change, back-compatible. Deploy is a code update; rollback is reverting the two touched files — no data or schema state is affected.
