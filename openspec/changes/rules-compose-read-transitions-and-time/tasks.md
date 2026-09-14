# Tasks: rules-compose-read-transitions-and-time

## 1. Named conditions

- [ ] 1.1 A named condition record: name, description, expression in the shared AST.
- [ ] 1.2 A rule, guard or field rule references a named condition by name.
- [ ] 1.3 Schema save refuses an unknown name and a cycle within the administered depth.
- [ ] 1.4 The rule inventory lists which rules use a named condition.
- [ ] 1.5 An unresolvable reference at evaluation is a refusal, recorded in the run log.

## 2. Before and after

- [ ] 2.1 A condition may address the value before the write and the value after it.
- [ ] 2.2 A rule requiring a prior value declares it, and is refused at save when attached to a create-only trigger.
- [ ] 2.3 The run log records which of the two operands decided the verdict.

## 3. Relative time

- [ ] 3.1 A condition compares a date property to now with an offset in hours, working hours, calendar days or business days.
- [ ] 3.2 Business units resolve through the working calendar the record type resolves.
- [ ] 3.3 The comparison compiles to an indexed query rather than a per-row evaluation.
- [ ] 3.4 An unresolvable calendar is a refusal at save, not a downgrade at evaluation.

## 4. Administered validations

- [ ] 4.1 A schema carries validations: a condition, a severity, the properties concerned and a translatable message.
- [ ] 4.2 A refusing validation refuses the save with the administrator's message and the named properties.
- [ ] 4.3 A warning validation returns the message and saves.
- [ ] 4.4 Validations are evaluated in the save pipeline and are covered by the write-path enumeration test.

## 5. Tests

- [ ] 5.1 Unit tests for the cycle refusal, the unknown name, the create-without-before refusal and the fail-closed evaluation.
- [ ] 5.2 Unit tests with a clock fixture for the working-hours comparison.
- [ ] 5.3 Unit tests asserting the administrator's message is returned verbatim in the refusal.
- [ ] 5.4 An e2e over a save refused by an administered validation showing its own message.
- [ ] 5.5 Deduplication check (ADR-012) recorded in the PR body.
