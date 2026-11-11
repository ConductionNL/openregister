---
kind: code
---

# Proposal: dedup-check-before-create

## Summary

Let a form ask "does this already exist" before it saves. The
`duplicate-detection` spec scores candidate pairs among the objects of a
register and schema, declared through `x-openregister-dedup` and evaluated
RBAC-scoped. It has no way to score an unsaved payload against the stored
objects, so a warning at intake, which is the ledger row, cannot be built on
it. This change adds a check endpoint that takes a candidate body and
returns the stored objects it would pair with, and a save option that
refuses a strong match unless the caller overrides.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 2.24 | Duplicate detection at intake | no | S |

The register marks the row covered by `duplicate-detection` under the slug
`duplicate-warning-at-intake (dossiq)`. The coverage check for this
programme found the rule set and the scoring inside that spec and the
create-time check absent, so the openregister half is here; the dossiq lane
keeps the declaration and the warning on the form.

## Why

The register's note: "`grep -ril "duplicate\|duplicaat" lib/` finds nothing
on the intake path." The best competitor, verbatim from the `best` column:
"Zammad 7: core admin.ticket_duplicate_detection with a permission level
(`_round3/compare/proposed-rows.md`)".

The register's `why`: "duplicate detection is a declared x-openregister-dedup
rule set evaluated RBAC-scoped", and its `dossiq_half`: "declare the rules
on case (requester, subject, address) and show the warning on the intake
form, with Zammad's permission level".

## What changes

- `POST /api/objects/{register}/{schema}/dedup-check` takes a candidate
  body and returns the stored objects the declared rules pair it with, each
  with the score, the matched rules and the fields that matched, RBAC- and
  tenant-scoped like the existing service, bounded by the same candidate
  cap. It saves nothing.
- The schema annotation `x-openregister-dedup` gains `onCreate`:
  `"warn"` (default, the check is advisory), `"block"` (a strong match
  refuses the create with 409 and the matches), and `overrideGroups`, the
  groups that may pass `_dedupOverride=true` to save anyway. Zammad's
  permission level, declared.
- On create the save path runs the same check when `onCreate` is `block`,
  so a client that skips the endpoint is still stopped.
- An override is audited on the new object with the objects it matched.

## Consumers

- dossiq: declare the rules on case (requester, subject, address), call
  the check from the intake form and show the warning, with the override
  group. Specified in dossiq by the dossiq lane under
  `duplicate-warning-at-intake`.
- pipelinq (a lead entered twice), opencatalogi (a publication entered
  twice), humaniq (an applicant).

## ADRs

- ADR-031: the rules and the policy are declared on the schema.
- ADR-045 (openregister owns the MDM surface): detection and merge are one
  surface; a warning at intake feeds the same merge.
- ADR-023: the override is a declared, group-gated permission.

## Impact

- Extends: `duplicate-detection` requirement "Declarative duplicate
  detection over a register/schema".
- Affected code: the dedup service (candidate-versus-stored scoring), a
  controller route, the annotation validator, `SaveObject` (block and
  override), the audit writer.
- Size: S.
