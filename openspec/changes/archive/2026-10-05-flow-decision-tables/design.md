# Design: flow-decision-tables

## D-1: The table lives inline in the node config, not behind a reference

Two storage shapes were on the table:

1. **Inline**: the full table (`hitPolicy`, `inputs`, `outputs`, `rules`)
   sits in the node's `table` config key.
2. **Referenced**: the node holds a register/schema/key triple and fetches
   an engine-stored table object at run time — the shape dossiq has today
   (`DecisionTableService::findByKey()` over a `decisionTable` schema).

Inline wins, and the deciding facts are dossiq's own tables:

- **Size.** dossiq's real tables are small. The LHS enforcement matrix —
  the largest real producer, projected by
  `LhsMatrixDecisionTableMigrator` — is three string inputs, one string
  output and one rule per matrix cell; the register schema caps the shape
  at flat positional arrays of short strings. A node config carries that
  comfortably. Nothing in dossiq's data needs an out-of-band store.
- **Pinning is the point, and inline gets it for free.** A published flow
  is pinned to its definition; a table inside that definition is pinned
  with it. A referenced table is live data under a published flow: editing
  a rule silently changes what every pinned run does, which is precisely
  the mutable-rule-store hazard this migration retires. Making a reference
  honour pinning would mean engine-side table versioning, a second
  version graph, and a copy-on-publish story — all machinery the data does
  not ask for.
- **Import already works.** `x-openregister-flows` imports flow
  definitions whole; an inline table arrives inside its step and is
  validated on the same save path as every hand-authored one. A reference
  would need ordered two-phase import (tables before flows) and a
  broken-link story.
- **Validation at save is only possible for a shape the save can see.**
  The spec requires refusing a malformed table when the flow is saved. An
  inline table is validated then and there. A referenced table can only be
  validated at run time, which is the edge-condition defect's shape: an
  accepted configuration whose executable content was never checked.

What is given up, and why that is acceptable:

- **Reuse across flows** becomes copy-per-node. dossiq's tables are keyed
  per decision and invoked from specific transitions; fleet-wide, a
  genuinely shared decision is expressed once in one flow and invoked via
  `openregister.sub-flow`, which also gives the shared decision one
  version history. If a real catalogue need emerges, a `tableFrom` key
  reading a table fetched by `openregister.object-read` composes on top of
  this node without changing its contract — recorded here so the future
  discussion starts from the refusal, not from scratch.
- **Editing rules without republishing** is exactly the property refused
  on purpose. Changing a decision rule IS changing the flow; the version
  history should say so.

## D-2: Validation executes the evaluator's grammar; nothing re-implements it

The lesson written into this repo more than once: an accepted grammar that
is not the executable grammar accepts flows that fail at 03:00.
`DecisionTableValidator` therefore does not parse rule cells itself. For
every cell it calls `UnaryTestEvaluator::matches()` with a probe value of
the column's effective type and treats `invalid_expression` and
`type_mismatch` as authoring errors to report with the rule id and column
name. A cell the evaluator cannot execute cannot be saved; a cell it can
execute needs no second parser to agree.

Two consequences worth naming:

- The evaluator's type normalisation (`integer` → `number`, `bool` →
  `boolean`, unknown → `string`) is exposed as
  `DecisionTableEvaluator::effectiveType()` and used by the validator, so
  the two cannot drift on what a column's type means.
- The evaluator's implemented-hit-policy list is exposed as a constant and
  the validator refuses anything outside it BY NAME
  (`hit policy "X" is not implemented`), loudly, at save. dossiq's five
  (UNIQUE, FIRST, PRIORITY, ANY, COLLECT) are all implemented, so no
  migrating table hits this; a future sixth spelling fails the save, not
  the run.

Structural checks beyond the grammar, each refusing a silent no-op or a
silent collapse:

- at least one input, one output, one rule (a table missing any of them
  can never decide anything);
- unique input names and unique output names (the evaluator keys results
  by name, so a duplicate would silently collapse two columns into one);
- per rule, entry counts equal to the declared input/output counts
  (positional alignment is the contract; a short row would silently
  wildcard the missing tail);
- `priority`, when present, an integer.

## D-3: No-match is an explicit choice: a complete default row, or a loud failure

The evaluator throws `no_rule_matched` for the single-winner policies and
returns empty lists for COLLECT. The node adds exactly one thing on top:
an optional `defaultOutputs` map. When configured, a no-match yields those
outputs (flagged `defaulted: true` in the `resultKey` record); when not,
the exception propagates and the step's `onError` policy decides. Two
refusals keep this honest:

- `defaultOutputs` must cover EVERY declared output — a partial default
  would write half a decision and nulls for the rest, which is the silent
  shape this whole node refuses.
- `defaultOutputs` is refused on a COLLECT table: COLLECT's empty list is
  a real answer ("nothing applied"), not a failure to answer, so a default
  would be unreachable code that looks like behaviour.

## D-4: The node is a rule step: per item, deterministic, no suspension

- **Per item, independently.** Inputs are read from each item's record via
  `inputMapping` (declared input name → dotted path, same-name default —
  dossiq's handler vocabulary, kept verbatim so the migration is a
  mechanical rewrite). Outputs are written back per item via
  `outputMapping`, same default. One item's failure semantics follow the
  engine's `onError` policy like every other node.
- **Deterministic.** The evaluation path does no I/O, reads no clock, and
  holds no state. Same item + same config = same outputs. This is asserted
  by test, not just by prose.
- **Never suspends.** Rule steps and human steps are different species:
  the human half is `openregister.user-task` and decidiq's consumption of
  it. This node completes in the firing that reached it.
- **Mapping paths are literal.** A `{{templated}}` mapping path is refused
  at save. SetFieldsNode earned its templated positions; here a
  data-controlled write position on a RULE step would mean the table's
  outputs land somewhere the author never named, and no dossiq table needs
  it. Add it later if a table does; refusing now keeps the contract small.
- **A mapping for a name the table does not declare is refused.** It would
  be configuration the node silently ignores — the looks-like-it-works
  shape `IFlowNodeConfigForm`'s own docblock warns about.

## D-5: What lands on the item

- Each declared output's value at its mapped (dotted) path, created
  containers included — the same `assign` semantics as
  `openregister.set-fields`, minus templated positions.
- Optionally, under `resultKey`, the evaluation record:
  `{hitPolicy, matchedRuleIds, defaulted, tableName, tableKey}` — enough
  for a downstream Switch to branch on which rule fired and for a run log
  reader to see why. Off by default: a node that stamps bookkeeping on
  every record unasked pollutes schemas.
- COLLECT writes lists (one entry per matched rule, in declaration order),
  matching the shared evaluator's contract unchanged.
