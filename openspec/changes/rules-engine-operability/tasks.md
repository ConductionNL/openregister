# Tasks: rules-engine-operability

## 1. The inventory

- [x] 1.1 `GET /api/schemas/{schema}/rules`: every lifecycle condition, state field block, calculation and triggering flow for the schema, in evaluation order, each with source, last run, last error and enabled state (D-1).
- [x] 1.2 A rule is switched off and on from that surface, recorded in the audit trail with the actor.
- [x] 1.3 The inventory is a projection: a rule absent from the schema cannot appear, proven by a test that adds and removes an annotation.

## 2. The run log

- [x] 2.1 Every evaluation records rule, object, verdict and, when it did not fire, the first deciding operand with the value it read (D-2).
- [x] 2.2 `GET /api/rules/{rule}/runs` with filters on verdict and period; a rule with no run in a configured window is reported in the inventory.
- [x] 2.3 The log is pruned by the daily retention pass with its own period, and the inventory keeps last run and last error after pruning (D-3).

## 3. Bounds, dry run and replay

- [x] 3.1 `maxObjects` on a rule; the count is taken before the first write and the whole run is refused above it, naming the rule and the count (D-4).
- [x] 3.2 `POST /api/schemas/{schema}/rules/{rule}/evaluate` with an object id or a sample payload, `commit: false`, returning the verdict and the writes it would make (D-5).
- [x] 3.3 Replay over existing objects as a background job with a preview and a per-object outcome, under the ceiling, through `bulk-action-jobs` (D-5).

## 4. One evaluation point, one vocabulary

- [x] 4.1 The evaluator is reached from the save pipeline; a test enumerates the write paths and asserts each reaches it (D-6).
- [x] 4.2 The JSON AST is accepted for a condition operand and a rule write target, beside the existing JSONLogic and Twig forms, which are documented as legacy (D-7).
- [x] 4.3 A property MAY declare a dependent value table naming the controlling property and the allowed pairs; schema save refuses an unknown property or an unknown value.
- [x] 4.4 A property default MAY be an AST expression evaluated on create; a failing expression refuses the create and names the property.

## 5. Tests

- [x] 5.1 `tests/e2e/ci/rules-engine-operability.spec.ts`: save a rule, read the inventory, dry run it, read the run log, switch it off.
- [x] 5.2 Unit tests: the deciding operand, the ceiling refusal before any write, the dependent value table, the expression default, and the inventory as a projection.
- [x] 5.3 A regression test that a schema declaring no ceiling and no dry run evaluates exactly as before.
- [x] 5.4 `openspec validate rules-engine-operability --strict`.

## 6. Hand over

- [ ] 6.1 Hand the inventory and the run log to the dossiq lane for `field-rules-declared`, with the cluster 19 candidate ids.

## Shipped so far

PR 1 (`feat/rules-engine-operability`, openregister#3745) ships the rule record
and the operator's reads: the inventory, the run log with its deciding operand,
the switch, the dry run, the daily prune and the one evaluation point.

PR 2 (`feat/rules-engine-operability-part-2`) ships the engine's other half:
the ceiling (3.1), the JSON AST as a condition dialect the save path speaks
(4.2), the dependent value table (4.3), the expression default (4.4), the
replay as a previewed bulk job (3.3) and the no-ceiling regression (5.3).

Left for the last branch: 6.1 the hand-over, and the admin surface the reads
exist for.

4.2 moved the dry run and the save path in ONE change, on purpose. A trial that
understood a dialect the save path does not would answer "it fires" about a
rule that refuses every transition in production.
