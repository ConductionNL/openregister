# Proposal: rematerialise writes changed calculated values, and resolves aggregates as the system

## Why

The live pass of 5 Oct (lane 13) found the archived change `2026-10-05-rematerialise-writes-as-system` (#4338) incomplete. It made the command save as the system, but:

- O8: a row whose calculated value actually changes is refused, "Cannot modify readOnly properties: durationMinutes, isPast, sessionDayBucket". The command wrote the recomputed values into the payload, and `ObjectService::enforceReadOnlyOnUpdate()` refuses a payload that changes a readOnly property whatever `_rbac` says; learniq declares every materialised property readOnly, as it should.
- O9: under occ every aggregate-reference resolved to null ("You do not have permission to aggregate schema lesson-completion", 410 warnings for 205 enrolments), because `AggregateReferenceResolver` ran `AggregationRunner::runAdhocByRef()` under the (absent) session user; the null equalled the stored null and the command reported 205 rows "unchanged" with exit 0.

That change stays archived; this follow-up change records what it left undone (no un-archive).

## What changes

- The command no longer injects values. It re-saves the stored data unchanged, as the temporal sweep does, so `CalculationOnSaveListener` materialises on the normal write path (the only path that may set calculated readOnly properties; no readOnly bypass is added), and then reads back that every changed value arrived; a row where it did not is counted failed.
- `AggregateReferenceResolver` resolves aggregate-references as the system for every saver (`bypassRbac: true`, new on `AggregationRunner::runAdhoc()`/`runAdhocByRef()`), the rule references already follow (`calculations-resolve-references-regardless-of-saver`). `resolveAllWithOutcome()` reports which references failed; the command counts such a row failed instead of comparing a null.

## Impact

- `lib/Command/RematerialiseCalculationsCommand.php`, `lib/Service/Calculation/AggregateReferenceResolver.php`, `lib/Service/Aggregation/AggregationRunner.php` (optional parameter, default unchanged).
- Behaviour change on web saves: an aggregate-reference now resolves even when the saver may not aggregate the referenced schema (before: null). That is the schema author's declared derived value, and the same rule as references.
