---
kind: code
---

# Proposal: field-rules-by-state

## Summary

Let a schema say which fields are hidden, read only or required for a
given role in a given lifecycle state. Field-level security has the role
axis (property `authorization` blocks with conditional `match` rules), and
a lifecycle transition declares `inputs: [{field, required}]` for the
moment of the transition. Nothing makes a field mandatory while an object
sits in a state, and nothing tells a form what to render before the user
tries to save. This change adds `x-openregister-lifecycle.states.<state>.fields`,
evaluates it on save, and publishes the effective rules on every object
read.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 11.25 | Fields hidden, required or read only by role and case state | partial | M |

## Why

The register's note: "Field-level permissions exist but the matrix already
calls them thin, and there is no per-state or per-screen dimension." The
best competitor, verbatim from the `best` column: "Zammad 7 Core Workflow;
Redmine WorkflowPermission best in corpus
(`_round3/compare/proposed-rows.md`)".

The register's `why`: "hidden, required or read-only by role and state is
field-level RBAC plus the lifecycle transition's inputs contract", and its
`covered` note: "row-field-level-security has the role axis, nothing makes a
field mandatory per state".

## What changes

- `x-openregister-lifecycle.states.<state>.fields`: a map of `hidden`,
  `readOnly` and `required`, each a list of `{ "fields": [...],
  "groups": [...] | "authenticated" }`. A rule without groups applies to
  everyone.
- On save, a `required` field of the object's current state (or of the
  target state on a transition) that is empty is refused with 422; a
  `readOnly` field that changed is refused; a `hidden` field is stripped
  on read and refused on write, exactly as property authorization does.
- Every object read carries `@self.fieldRules`: the effective `hidden`,
  `readOnly` and `required` lists for the current user and state, so a
  form renders them without a second call.
- A transition's `inputs` keep their meaning; the state rules apply
  before and after, and the validator refuses an `inputs` entry that names
  a field `hidden` in the target state.
- Schema-save validation refuses a field name the schema does not have and
  a state the lifecycle does not declare.

## Consumers

- dossiq: declare per case type and status which fields are hidden,
  mandatory or read only. Specified in dossiq by the dossiq lane (register
  row 11.25).
- nextcloud-vue's `CnFormDialog` reads `@self.fieldRules` in a follow-up
  change; until then the server-side refusal holds.
- decidiq, humaniq, pipelinq.

## ADRs

- ADR-031: declared on the schema, replacing per-app form logic.
- ADR-022.
- ADR-098 decision 5: the task-form contract is the same `inputs` contract.

## Impact

- Extends: `row-field-level-security` requirement "Schemas MUST support
  field-level security via property authorization blocks" and
  `object-lifecycle` requirement "REQ-006: Schema lifecycle annotations MUST
  be shape-validated at schema-save time".
- Affected code: the lifecycle annotation validator,
  `PropertyRbacHandler` (state dimension), `SaveObject` (required and
  readOnly by state), `RenderObject` (`@self.fieldRules`).
- Backwards compatible: a lifecycle without `fields` is unchanged.
- Size: M.

## Extension, discovery wave 1 (2026-09-14)

Cluster CT-2 of `procest/_round4/discovery/build-plan.md`, from the depth
study `procest/_round4/discovery/casetype-configurability.md`
(ConductionNL/market-intelligence, 2026-09-14): "The rules engine behind
the smart field". Study rows B3, B4, B5, B6, B9 and the second half of C1.
Owner openregister, size L. The build plan names this change as one of the
three vehicles, so it is extended rather than duplicated.

**The decision.** D3, option 2: the engine is OpenRegister's flow guards
extended, which is this change plus `lifecycle-declarative-conditions`
plus the JSON-AST evaluator. The decision also says, in the same
paragraph, what is still missing when all three land:

> a rule whose condition reads a case-type property rather than a built-in
> field, and a rule action that makes a field required. xxllnc cannot do
> the second either, and that is worth writing in a tender.

That sentence is this extension's scope.

**The proving passers.** The study's table B rates B4, shown or hidden by
rule, as `yes` for xxllnc-zaken, glpi, frappe, itop and youtrack; B5,
required by rule, as `yes` for glpi, frappe, itop and youtrack and `no`
for xxllnc; and B6, read only by fase or by role, as `yes` for tuleap and
itop. The competitor that buyers mean when they say smart fields is
xxllnc, which answers with a per-fase rule carrying 19 conditions and 23
actions, two branches and grouping. dossiq answers with six guards and
eight action handlers hanging off a transition, "so a rule can block a
move but cannot shape a form".

For C1's second half the study's reading of dossiq is: "conditions hang on
the transition, not the status; six guards, no expression, no and/or
grouping, no else branch".

**What this change gains.**

- **A field rule's condition reads the object.** The condition operand may
  be any property of the object, including one an extending form
  declared, and not only the lifecycle field and the built-in scalars.
- **A rule may make a field required.** `required`, `hidden` and
  `readOnly` become conditional: they apply when the declared condition
  holds, so "verplicht als bedrag boven 50.000" is expressible without a
  state per branch.
- **A state carries entry and exit conditions.** A condition on the state
  itself, grouped with and or or, evaluated on the way in and on the way
  out, with the refusal naming the clause that failed. Today a condition
  can only hang on a transition, which means declaring the same rule on
  every edge that reaches the state.

**What stays out.** Read and write permission per field (study row B9)
belongs to `row-field-level-security`, which already carries the role axis
for ledger row 13.8. The option-narrowing half of B3 is
`code-list-lifecycle-and-hierarchy` REQ-CLH-002. The operator surface for
all of this, the inventory, the run log, the ceiling and the dry run, is
`rules-engine-operability`.
