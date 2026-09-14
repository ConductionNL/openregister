# Tasks: rules-engine-operability

## 1. The inventory

- [ ] 1.1 `GET /api/schemas/{schema}/rules`: every lifecycle condition, state field block, calculation and triggering flow for the schema, in evaluation order, each with source, last run, last error and enabled state (D-1).
- [ ] 1.2 A rule is switched off and on from that surface, recorded in the audit trail with the actor.
- [ ] 1.3 The inventory is a projection: a rule absent from the schema cannot appear, proven by a test that adds and removes an annotation.

## 2. The run log

- [ ] 2.1 Every evaluation records rule, object, verdict and, when it did not fire, the first deciding operand with the value it read (D-2).
- [ ] 2.2 `GET /api/rules/{rule}/runs` with filters on verdict and period; a rule with no run in a configured window is reported in the inventory.
- [ ] 2.3 The log is pruned by the daily retention pass with its own period, and the inventory keeps last run and last error after pruning (D-3).

## 3. Bounds, dry run and replay

- [ ] 3.1 `maxObjects` on a rule; the count is taken before the first write and the whole run is refused above it, naming the rule and the count (D-4).
- [ ] 3.2 `POST /api/schemas/{schema}/rules/{rule}/evaluate` with an object id or a sample payload, `commit: false`, returning the verdict and the writes it would make (D-5).
- [ ] 3.3 Replay over existing objects as a background job with a preview and a per-object outcome, under the ceiling, through `bulk-action-jobs` (D-5).

## 4. One evaluation point, one vocabulary

- [ ] 4.1 The evaluator is reached from the save pipeline; a test enumerates the write paths and asserts each reaches it (D-6).
- [ ] 4.2 The JSON AST is accepted for a condition operand and a rule write target, beside the existing JSONLogic and Twig forms, which are documented as legacy (D-7).
- [ ] 4.3 A property MAY declare a dependent value table naming the controlling property and the allowed pairs; schema save refuses an unknown property or an unknown value.
- [ ] 4.4 A property default MAY be an AST expression evaluated on create; a failing expression refuses the create and names the property.

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/rules-engine-operability.spec.ts`: save a rule, read the inventory, dry run it, read the run log, switch it off.
- [ ] 5.2 Unit tests: the deciding operand, the ceiling refusal before any write, the dependent value table, the expression default, and the inventory as a projection.
- [ ] 5.3 A regression test that a schema declaring no ceiling and no dry run evaluates exactly as before.
- [ ] 5.4 `openspec validate rules-engine-operability --strict`.

## 6. Hand over

- [ ] 6.1 Hand the inventory and the run log to the dossiq lane for `field-rules-declared`, with the cluster 19 candidate ids.
