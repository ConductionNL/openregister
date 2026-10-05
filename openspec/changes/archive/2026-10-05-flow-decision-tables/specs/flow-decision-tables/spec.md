# Spec: flow decision tables

## Purpose

One step type that evaluates a decision table inside a flow: rules in the
graph, evaluated by the engine's shared evaluator, with the outputs written
onto the items so downstream nodes can route on them. Rule steps are the
engine's; human decisions stay with the user-task machinery and its
consumers.

## ADDED Requirements

### Requirement: A decision-table step evaluates its table against every item

The system SHALL provide a step node type `openregister.decision-table`.
For each item that reaches it, the node SHALL build the table's declared
inputs from the item's record, evaluate the table through the shared
decision-table evaluator, and write every declared output's value onto the
item's record.

The node SHALL delegate evaluation in full: hit policies, unary-test
grammar, type coercion and typed errors are the shared evaluator's, and the
node SHALL NOT re-implement or wrap any of them in altered form.

Input values SHALL be read via `inputMapping` (declared input name to a
dotted path in the item's record) with a same-name default, and outputs
SHALL be written via `outputMapping` with the same default — the vocabulary
dossiq's `evaluateDecision` handler already uses, kept so the migration is
a mechanical rewrite.

An item that lacks a declared input SHALL fail that item's evaluation with
the evaluator's typed error rather than wildcarding the gap: a decision
over absent data is not a decision.

A firing with no items SHALL return no items and do nothing else.

#### Scenario: A dossiq table decides an item

- **GIVEN** a decision-table step carrying dossiq's LHS enforcement table
  (three string inputs, one output, UNIQUE)
- **WHEN** an item carrying `severity`, `behaviour` and `actorType` passes
  through
- **THEN** the item's record MUST carry the matched rule's `intervention`
- @e2e exclude engine-internal node behaviour, proven by the
  DecisionTableNode unit suite's dossiq fixture

#### Scenario: Mapped paths read and write nested fields

- **GIVEN** `inputMapping` pointing an input at `case.severity` and
  `outputMapping` pointing an output at `advies.maatregel`
- **WHEN** an item with a nested `case` record passes through
- **THEN** the input MUST be read from the nested path and the output MUST
  be written at the nested path, containers created as needed
- @e2e exclude engine-internal mapping semantics, covered by unit tests

#### Scenario: A missing input fails loudly

- **GIVEN** a table declaring input `severity` and an item without it
- **WHEN** the step fires
- **THEN** the step MUST fail with the evaluator's typed error
- **AND** the step MUST NOT evaluate the table as if the input were a
  wildcard
- @e2e exclude failure-path semantics, covered by unit tests

### Requirement: The step is deterministic and never suspends

The node SHALL be a pure function of (item record, step configuration): no
I/O, no clock reads and no stored state in the evaluation path. Evaluating
the same item against the same configuration twice SHALL produce identical
outputs.

The node SHALL NOT suspend the run, create a task, or wait for anything.
Automated rule evaluation is a rule step; a decision needing a person is a
user task and belongs to that node type and its consumers.

#### Scenario: The same item decides the same way twice

- **GIVEN** any valid decision-table step and any item its table accepts
- **WHEN** the step fires twice over an identical item
- **THEN** both firings MUST produce identical records
- @e2e exclude determinism is a unit-level property, asserted by unit tests

### Requirement: The table travels inline and is pinned with the flow

The step SHALL carry its decision table inline in the `table` configuration
key. The table is thereby part of the flow definition: versioning, pinning
and `x-openregister-flows` import apply to it exactly as they apply to any
other authored step configuration, with no additional machinery.

The system SHALL NOT resolve the table from live data at run time. Changing
a decision rule is changing the flow, and the flow's version history SHALL
record it as such.

#### Scenario: A pinned run keeps the rules it was published with

- **GIVEN** a published flow whose decision-table step carries version A of
  a table
- **WHEN** the draft's table is edited to version B without republishing
- **THEN** runs of the published flow MUST keep evaluating version A
- @e2e exclude follows from definition pinning, which flow-engine already
  specifies and tests; the table adds no new pinning surface

### Requirement: A table the evaluator cannot execute is refused at save

The node's configuration validation SHALL refuse, when the flow is saved or
imported and with a message naming the offending part:

- a missing or non-object `table`;
- a hit policy the shared evaluator does not implement, by name;
- a table without at least one input, one output and one rule;
- a duplicate input name or duplicate output name;
- a rule whose `inputEntries` or `outputEntries` count differs from the
  declared inputs/outputs count;
- a rule cell the evaluator's own grammar cannot execute.

Grammar validation SHALL be performed by exercising the shared evaluator
against every rule cell, not by a parallel parser: the accepted grammar
MUST be the executable grammar by construction.

The validation SHALL also refuse configuration the node would otherwise
silently ignore: an `inputMapping` or `outputMapping` entry naming an
undeclared input/output, a templated (`{{...}}`) mapping path, and a
non-string mapping path.

#### Scenario: A malformed rule cell cannot be saved

- **GIVEN** a table whose rule cell reads `[5..` on a number column
- **WHEN** the flow is saved or imported
- **THEN** the save MUST be refused with a message naming the rule and the
  column
- @e2e exclude save-path refusal, covered by validator and node unit tests

#### Scenario: An unimplemented hit policy is refused by name

- **GIVEN** a table declaring hit policy `OUTPUT ORDER`
- **WHEN** the flow is saved or imported
- **THEN** the save MUST be refused with a message naming `OUTPUT ORDER`
  as not implemented
- @e2e exclude save-path refusal, covered by unit tests

#### Scenario: A mapping over an undeclared name is refused

- **GIVEN** an `outputMapping` entry for an output the table does not
  declare
- **WHEN** the flow is saved
- **THEN** the save MUST be refused: configuration the step ignores looks
  like behaviour and is not
- @e2e exclude save-path refusal, covered by unit tests

### Requirement: Hit policies are the shared evaluator's five, unchanged

The step SHALL support exactly the hit policies the shared evaluator
implements — UNIQUE, FIRST, PRIORITY, ANY and COLLECT — with the shared
evaluator's semantics, including UNIQUE refusing multiple matches, ANY
refusing disagreeing matches, PRIORITY taking the highest priority with
declaration order breaking ties, and COLLECT returning one list per output.

#### Scenario: COLLECT writes lists

- **GIVEN** a COLLECT table where two rules match an item
- **WHEN** the step fires
- **THEN** each mapped output field MUST hold a two-entry list in rule
  declaration order
- @e2e exclude policy semantics live in the shared evaluator; the node's
  pass-through is unit-tested per policy

#### Scenario: UNIQUE with two matches fails the step

- **GIVEN** a UNIQUE table where two rules match an item
- **WHEN** the step fires
- **THEN** the step MUST fail with the evaluator's `hit_policy_violation`
- @e2e exclude failure-path semantics, covered by unit tests

### Requirement: No-match takes the author's explicit default or fails loudly

When no rule matches under a single-winner policy, the step SHALL fail with
the evaluator's `no_rule_matched` error unless the configuration carries
`defaultOutputs`, in which case the step SHALL write those values instead
and mark the evaluation record as defaulted.

`defaultOutputs`, when present, SHALL be refused at save unless it provides
a value for every declared output: a partial default writes half a decision.
It SHALL also be refused on a COLLECT table, whose empty list is an answer
rather than a failure to answer.

#### Scenario: No match without defaults fails the step

- **GIVEN** a FIRST table none of whose rules match an item, and no
  `defaultOutputs`
- **WHEN** the step fires
- **THEN** the step MUST fail with `no_rule_matched`
- @e2e exclude failure-path semantics, covered by unit tests

#### Scenario: No match with a complete default row decides the default

- **GIVEN** the same table with `defaultOutputs` covering every output
- **WHEN** the step fires
- **THEN** the item MUST carry the default values
- **AND** the evaluation record under `resultKey`, when configured, MUST
  say `defaulted: true` with no matched rule ids
- @e2e exclude default-path semantics, covered by unit tests

### Requirement: The node describes its own form and writes an optional evaluation record

The node SHALL implement the config-form contract so the canvas can edit
it, describing at least the table, both mappings, `defaultOutputs` and
`resultKey`. All palette and form strings SHALL be translated, in sentence
case, without em-dashes.

When `resultKey` is configured, the node SHALL write the evaluation record
`{hitPolicy, matchedRuleIds, defaulted, tableName, tableKey}` at that
dotted path on each item, so a downstream switch can branch on which rule
fired. Without `resultKey` the node SHALL write only the mapped outputs.

#### Scenario: The palette serves the form

- **GIVEN** the node catalog endpoint
- **WHEN** the palette is fetched
- **THEN** `openregister.decision-table` MUST be present with a non-empty
  `configForm`
- @e2e exclude catalog plumbing is flow-node-config-forms' tested surface;
  the node's form content is unit-tested
