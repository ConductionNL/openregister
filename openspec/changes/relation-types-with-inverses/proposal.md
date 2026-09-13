---
kind: code
---

# Proposal: relation-types-with-inverses

## Summary

Give a typed link between two objects a name in both directions. A `$ref`
property already declares `inversedBy` and `writeBack` (referential-integrity
requirement 6), and the `uses` and `used` endpoints traverse both ways
(requirement 12). What no schema can say is what the link is called from
the other side: a case that `blocks` another is, from there, `blockedBy`,
and the relations leaf can only show "referenced by". This change adds the
inverse label to the property, carries the property name and its inverse
label on every relation row, and lets a schema declare a relation vocabulary
as data.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 2.26 | Typed case links with a declared inverse | partial | S |

## Why

The register's note: "We have sub-cases and related cases. A typed graph is
a different feature, and `blocks` is how 'this vergunning waits on that
bezwaar' gets modelled." The best competitor, verbatim from the `best`
column: "GLPI 11: LINK_TO, DUPLICATE_WITH, SON_OF, PARENT_OF; Redmine nine
types with inverses (`_round3/compare/proposed-rows.md`)".

The register's `why`: "a relation vocabulary with declared inverses belongs
on the relation primitive every app links with".

## What changes

- A `$ref` property (single or array) accepts `x-openregister-relation`:
  `{ "label": "blocks", "inverseLabel": "blocked by", "symmetric": false }`.
  A symmetric relation (`duplicate of`) has one label and reads the same
  both ways.
- `GET .../uses` and `GET .../used` return, per row, the property the
  relation came in through, its `label` and the `inverseLabel` to show on
  the far side, so a relations panel renders "blocked by: case 2026-0042"
  without knowing the schema.
- A schema may declare `x-openregister-relation-types`, a named list of
  `{ key, label, inverseLabel, symmetric }`, and a property may point at a
  key instead of inlining the three fields, so several properties share one
  vocabulary and an administrator edits labels in one place.
- Labels are i18n keys resolved per locale like other schema labels; the
  registers' `register-i18n` content translation is not involved.
- Validation refuses an `inverseLabel` on a symmetric relation and a
  vocabulary key that does not exist.

## Consumers

- dossiq: declare `vervolg`, `subject` and `bijdrage` with their inverse
  names and render both directions on Related cases. Specified in dossiq by
  the dossiq lane (register row 2.26).
- decidiq (a decision `supersedes` another), stackiq (a component `depends
  on` another), pipelinq, keepiq: the same declaration.
- nextcloud-vue's relations panel reads the labels; no schema knowledge.

## ADRs

- ADR-031: the vocabulary is schema data, replacing app-local link tables.
- ADR-048 (cross-app semantic references): a relation label is a property
  of the referencing side and travels with the reference.
- ADR-022.

## Impact

- Extends: `referential-integrity` requirements 6 and 12.
- Affected code: schema validation (`x-openregister-relation`,
  `x-openregister-relation-types`), `RelationHandler::getUses()` and
  `getUsedBy()` (label enrichment), the relations leaf rendering.
- Backwards compatible: a property without the annotation reads as today,
  with `label` falling back to the property title and `inverseLabel` to
  "referenced by".
- Size: S.
