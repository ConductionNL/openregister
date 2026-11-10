---
kind: code
depends_on: [generated-identifier]
---

# Proposal: identity-survives-a-move

## Summary

Let an object move to another register or schema without losing what points
at it. Today there is no move: an object is created in one register and
schema and stays there, and the only way to "move" a case between teams is
to copy it, which mints a new uuid and a new number. This change adds a
move that keeps the uuid, the generated identifier, the audit chain, the
relations, the watchers and the favourites, and makes the old address
answer.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q2.30 | When a case moves, does its identity survive, and what happens to what pointed at it | no | S |

## Why

The register's note: "No identity to preserve. Matrix row 2.1 records
`case.identifier` as free text, empty on five of seven demo rows, with a
generator only for complaints." The best competitor, verbatim from the
`best` column: "Znuny 7.3: a queue is a column, the number is allocated
once (`_round4/compare/proposed-rows-batch4.md`)".

The register's `why`: "an identity that survives a move is the object uuid
plus the generated identifier; neither moves with a register change".
`generated-identifier` freezes the number after creation. This change makes
sure a move cannot mint a second one.

## What changes

- `POST /api/objects/{register}/{schema}/{id}/move` with a target register
  and schema, allowed for a user with `manage` on the source and `create`
  on the target. The target schema must validate the object's data, or the
  move is refused with the validation errors.
- The move keeps the uuid, every `x-openregister-generated` value (the
  sequence is not consulted), the audit trail (one `moved` entry naming
  both addresses), the versions, the relations in both directions, the
  files, the notes, the watchers, the favourites and the locks.
- The old address `/{oldRegister}/{oldSchema}/{id}` resolves to the object
  with `@self.movedTo` for one year, and relation records carrying the old
  deep link are rewritten (relation-resource-urls).
- Timers bound to the object are re-resolved against the target
  organisation's calendar, as a supersession with reason `moved`.
- A move across organisations is refused unless the caller has `manage` on
  both.

## Consumers

- dossiq: nothing once `generated-identifier` lands; re-rate against a case
  moved between teams. A team move is a change of `assignedGroup`, not a
  move between registers, so dossiq's half is a sentence. Specified in
  dossiq by the dossiq lane (register row Q2.30).
- Every app whose objects change type over their life: pipelinq (a lead
  becomes a customer), stackiq, opencatalogi.

## ADRs

- ADR-022.
- openregister ADR-003: the move is an audit fact on an unbroken chain.
- ADR-052 (URL canonicalization): the old and new address compare through
  the shared canonicalizer.

## Impact

- Extends: `objects-crud` (a new requirement), `generated-identifier`
  requirement "A generated identifier is frozen after creation".
- Affected code: `lib/Service/Object/MoveObject.php`, `ObjectsController`,
  the slug resolver (old address), `RelationHandler` (deep-link rewrite),
  `FlowTimerService::supersede()` (reason `moved`).
- Size: S.
