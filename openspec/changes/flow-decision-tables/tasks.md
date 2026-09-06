# Tasks: flow-decision-tables

## 1. The validator

- [x] 1.1 `lib/Service/Dmn/DecisionTableValidator.php`: structural checks
      (hit policy by name against the evaluator's implemented list, at
      least one input/output/rule, unique names, positional entry counts,
      integer `priority`) plus grammar checks that EXECUTE
      `UnaryTestEvaluator::matches()` over every rule cell with a probe of
      the column's effective type. Returns a list of problems naming rule
      and column; never a boolean.
- [x] 1.2 Expose `DecisionTableEvaluator::effectiveType()` and make the
      implemented-hit-policy list public, so validator and evaluator cannot
      drift. `normaliseFields()` switches to `effectiveType()`.

## 2. The node

- [x] 2.1 `lib/Service/Flow/Nodes/DecisionTableNode.php` implementing
      `IFlowNode`, `IFlowNodeConfigKeys` and `IFlowNodeConfigForm`.
      `getId()` returns `openregister.decision-table`; scope is admin and
      user (reshaping data grants no privilege); EUPL-1.2 header; `@spec`
      on every method.
- [x] 2.2 `configKeys()`: `table`, `inputMapping`, `outputMapping`,
      `defaultOutputs`, `resultKey`. `configForm()` describes each in the
      Conduction voice (sentence case, no em-dashes), l10n throughout.
- [x] 2.3 `validateConfig()`: table through the validator; mappings must be
      string maps over declared names only, with literal (untemplated)
      paths; `defaultOutputs` complete over the declared outputs and
      refused on COLLECT; `resultKey` a non-empty literal path when
      present.
- [x] 2.4 `execute()`: per item — inputs via mapping paths (same-name
      default, type-preserving dotted reads), evaluate through
      `DecisionTableEvaluator`, `no_rule_matched` takes `defaultOutputs`
      when configured and rethrows otherwise, outputs written via dotted
      assign, optional `resultKey` record, provenance via
      `FlowItems::item(fromItemIndex:)`. No suspension anywhere.
- [x] 2.5 Register in `lib/Listener/FlowNodeRegistrationListener.php`.

## 3. Tests

- [x] 3.1 `tests/Unit/Service/Dmn/DecisionTableValidatorTest.php`: every
      structural refusal, grammar refusals through the evaluator (bad
      range, bad operand, bad literal for the column type), type aliases
      accepted, a clean table over each of the five policies passing.
- [x] 3.2 `tests/Unit/Service/Flow/Nodes/DecisionTableNodeTest.php`:
      evaluation per hit policy (UNIQUE match + violation, FIRST,
      PRIORITY with tie, ANY agree + disagree, COLLECT lists + empty),
      mapping in and out over nested paths, missing input loud,
      no-match default vs loud failure, validation refusals at the node
      boundary, determinism (two firings, identical records), empty items,
      provenance and binary pass-through.
- [x] 3.3 The dossiq fixture: a table translated from the LHS enforcement
      matrix (`LhsMatrixDecisionTableMigrator::tableFor()`'s shape) with
      the `inputMapping`/`outputMapping` vocabulary of
      `EvaluateDecisionHandler`, proving the migration's expressiveness
      end to end.
- [x] 3.4 Complete `@covers`/`@uses` on both test classes.

## 4. Quality

- [x] 4.1 Analyzers individually and in the foreground: phpcs, phpstan,
      psalm, phpmd (per subdirectory), full Unit slice.
- [x] 4.2 hydra gates `--scope-to-diff`, zero FAIL.
- [x] 4.3 One PR to `development`.
