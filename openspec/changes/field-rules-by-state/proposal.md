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
