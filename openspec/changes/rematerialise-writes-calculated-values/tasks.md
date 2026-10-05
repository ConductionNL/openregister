# Tasks: rematerialise-writes-calculated-values

- [x] 1.1 The command re-saves the stored data unchanged and reads back that each changed value arrived; a row where it did not is failed (O8).
- [x] 1.2 `AggregationRunner::runAdhoc()`/`runAdhocByRef()` take `bypassRbac`; `AggregateReferenceResolver` resolves as the system and reports failures through `resolveAllWithOutcome()` (O9).
- [x] 1.3 The command counts a row with an unresolved aggregate as failed.
- [x] 2.1 `tests/Unit/Command/RematerialiseWritesCalculatedValuesTest.php`: the REAL `ValidateObject` readOnly rule on what the command saves; red on development with the live message; a save that did not materialise fails; an aggregate under no session resolves through the real resolver, a failing one fails the row.
- [ ] 3.1 Live: rerun `occ openregister:rematerialise-calculations learniq session` and `... learniq enrolment` on the dev instance (needs the live instance).
