---
kind: code
---

# Proposal: notification-scheduled-filter-grammar

## Summary

Give the `scheduled` notification trigger's `filter` **one** grammar, defined
once and consumed by both the validator and the evaluator: add `in` / `notIn`,
`before` / `after`, a bare list as shorthand for `in`, and the `all` / `any`
combinators — and close the validator hole that lets a filter shape the
evaluator cannot execute be saved without a word.

## Why

`ScheduledFilterEvaluator` (`lib/Service/Notification/ScheduledFilterEvaluator.php`)
accepts a flat `field => spec` map, entries ANDed, where `spec` is a scalar
(strict `===`, line 118) or an operator object with exactly four operators —
`equals`, `notEquals`, `withinNext`, `olderThan` (lines 124-161). Anything else
hits the `default:` arm and fails closed (line 162-167).

`NotificationAnnotationValidator::validateScheduledFilterEntry()` is supposed to
catch that at save time. Its first statement is the defect:

```php
// lib/Service/Notification/NotificationAnnotationValidator.php:1018-1021
private function validateScheduledFilterEntry(string $ruleKey, string $field, $spec): array {
    // Scalar shortcut: always accepted (legacy v1 strict equality).
    if (is_array($spec) === false || array_key_exists('operator', $spec) === false) {
        return [];
    }
```

The branch is named for scalars but it is reached by **any** value that is not
an operator object — including every array. So a list, a combinator, or an
operator object that spells its key `op` instead of `operator` is accepted as
valid, and then at run time line 117-118 of the evaluator compares a scalar
field against an array: `$actual === $spec` is false forever. The rule is
syntactically valid, semantically dead, and completely silent — no warning at
save, only a debug line at scan time, and only for the operator arms.

A fleet census of every `x-openregister-notifications` block under
`lib/Settings/` in the workspace (ran 2026-08-22) counts **24 filter entries
across 23 rules in 3 apps that the evaluator structurally cannot execute**,
against 49 entries (28 scalar, 21 canonical-operator) that work today. Issue
ConductionNL/openregister#2787. Three teams independently invented three
dialects, none of which the engine knows:

- **decidesk — 18 rules, 18 entries.** A bare list meaning set-membership:
  `{"lifecycle": ["open","in-uitvoering"]}`
  (`decidesk/lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json:216`
  and 17 siblings across fragments 45/47/50/52/53/56/59/60/62).
- **shillinq — 4 rules, 4 entries, 7 clauses.** A combinator plus two unknown
  operators: `{"all":[{"field":"state","operator":"notIn","values":["paid","written-off","voided"]},{"field":"dueDate","operator":"before","value":"now"}]}`
  (`shillinq/lib/Settings/register.d/bookkeeping-accounts-payable-core.json:840`,
  plus `contract-lifecycle-management.json:385`, `:433`, `:703`).
- **openconnector — 1 rule, 2 entries.** The wrong key and an unknown operator:
  `{"isEnabled":{"op":"equals","value":true},"nextRun":{"op":"lt","value":"now"}}`
  (`openconnector/lib/Settings/openconnector_register.json:1154`, rule
  `job.job-overdue`).

The shillinq case proves the hole is already known and still open.
`shillinq/lib/Settings/register.d/shillinq-notifications.json:7` records, in
prose, that this grammar is non-canonical — *"a non-canonical
{all:[{field,operator,…}]} filter grammar with operators (notIn, before) the
canonical scheduled-filter grammar … does not know"* — and that the sibling
ARInvoice rules were rewritten to `{"lifecycleState": "overdue"}` for exactly
that reason (`:17`). The four rules in the other two fragments were never
touched. A note in a description is not a gate.

Two facts sharpen the fix and are worth stating up front, because both
contradict the obvious reading:

1. **Tightening the validator alone does not stop anything shipping.**
   `SchemaMapper::validateNotificationsAnnotation()` calls the validator and
   then deliberately **discards** its errors —
   `lib/Db/SchemaMapper.php:1364-1381` logs a warning and returns, with the
   comment *"A malformed OPTIONAL notification annotation must NOT block the
   schema itself"*. There is no 422 anywhere in that file. Meanwhile
   `openspec/specs/notificatie-engine/spec.md:846-853` requires the validator
   to *"reject, with HTTP 422"*. The spec and the code have disagreed since the
   `notification-engine-scheduled-conditions` change landed, and nobody noticed
   because nothing reads the warning. Detection therefore cannot live in the
   validator alone.
2. **The same class of defect exists on the `created` path.**
   `AnnotationNotificationDispatcher::createdFilterMatches()`
   (`lib/Service/Notification/AnnotationNotificationDispatcher.php:1686-1724`)
   reads `$filter['field']` and returns false when it is empty (`:1687-1690`).
   openconnector's two `created` rules `call_log.call-failed`
   (`openconnector_register.json:1940`) and `job_log.job-error` (`:2114`) pass a
   map-shaped filter with the `op` key, so they too match nothing, ever. They
   are out of scope for this change's evaluator but they belong in the same
   detection net.

## What Changes

- **Extend the scheduled-filter grammar** with the operators three teams
  independently reached for: `in` / `notIn` (taking `values`), and `before` /
  `after` (taking a reference instant: `"now"`, an ISO-8601 date/date-time, or
  a signed ISO-8601 duration relative to now). Fail-closed semantics for
  unparsable dates and unknown operators are unchanged.
- **Bare list as shorthand for `in`.** `{"lifecycle": ["open","x"]}` means
  `{"lifecycle": {"operator": "in", "values": ["open","x"]}}`. **BREAKING** in
  the letter of the contract: today a list spec means strict `===` equality
  against an array-valued field. Measured blast radius: zero. All 18 bare-list
  entries in the fleet sit on scalar enum fields (`lifecycle`, `status`), and
  no rule in the census relies on array-identity equality.
- **`all` / `any` combinators** as reserved top-level keys of `filter`, taking a
  list of clause objects in the `{field, operator, value|values}` shape —
  the same shape `createdFilterMatches()` already parses. **BREAKING** in the
  letter of the contract: a schema field literally named `all` or `any` can no
  longer be filtered by a top-level entry. Measured blast radius: zero — the
  census finds `all`/`any` used only as shillinq's combinators, never as a
  field name.
- **One grammar definition, two consumers.** A new `ScheduledFilterParser`
  produces a normalised filter AST; `ScheduledFilterEvaluator` evaluates that
  AST instead of re-walking raw JSON, and `NotificationAnnotationValidator`
  reports that same parser's errors. A shape the validator accepts but the
  evaluator cannot execute becomes structurally impossible rather than merely
  discouraged.
- **Tighten `validateScheduledFilterEntry()`.** The "scalar shortcut" branch
  narrows to actual scalars. An array that is neither a bare list, nor a
  recognised operator object, nor a combinator is an ERROR. An operator object
  spelled `op` produces an error that names `operator` explicitly, rather than
  being silently accepted.
- **Detection that actually blocks a merge:** extend Hydra gate-18
  (`check_notification_dialect.py`) with a scheduled-filter-shape check, because
  the 23 rules live in repos OpenRegister's own test suite never reads and the
  OR-side validator's findings are discarded before they reach anyone.
- **PERF-3:** the AST is the enabling artefact for the SQL pushdown that
  `lib/BackgroundJob/ScheduledNotificationJob.php:64-67` defers. The richer
  grammar is *more* translatable to a `WHERE` tree, not less.

## Capabilities

### New Capabilities

None. This change extends an existing declarative surface.

### Modified Capabilities

- `notificatie-engine`: the scheduled-trigger filter grammar gains membership,
  date-comparison and boolean-combinator forms; the save-time validation
  requirement is restated so that a shape the evaluator cannot execute is an
  error rather than an accepted no-op, and the requirement's enforcement point
  is stated explicitly (the validator reports; the fleet gate blocks).

## Impact

**OpenRegister code**

- `lib/Service/Notification/ScheduledFilterEvaluator.php` — evaluates an AST;
  new operator arms; `parseDate`/`parseDuration` retained and reused.
- `lib/Service/Notification/ScheduledFilterParser.php` — new; the single
  definition of the grammar.
- `lib/Service/Notification/NotificationAnnotationValidator.php:1018-1102` —
  `validateScheduledFilterEntry()` delegates to the parser.
- `lib/BackgroundJob/ScheduledNotificationJob.php` — unchanged behaviour;
  the `TODO(PERF-3)` note (`:64-67`, `:347`) is updated to name the AST as the
  pushdown input.
- `openspec/specs/notificatie-engine/spec.md` — the grammar requirement.

**Fleet (follow-up changes in other repos, NOT specified here)**

- decidesk: 18 rules become executable **with no edit at all**.
- shillinq: 4 rules become executable **with no edit at all**.
- openconnector: 1 scheduled rule (`job.job-overdue`, 2 entries) still needs an
  edit — `op` → `operator`, `lt` → `before`. Its 2 `created`-trigger rules need
  the same key fix.

**Tooling**

- `.github` (`conduction/hydra-gates`): gate-18 check (c). Not editable from
  this repo; tracked as a follow-up with the grammar in
  `openspec/specs/notificatie-engine/spec.md` as its source of truth.

**Not changed**

- The `created`-trigger filter's comparison semantics
  (`AnnotationNotificationDispatcher.php:1694-1723`, string-coerced) stay as
  they are. See design — converging them here would silently un-match existing
  rules.
