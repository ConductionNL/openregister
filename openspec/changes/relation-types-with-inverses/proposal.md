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

## Discovery cluster 61 extension (2026-09-14)

The round 4 discovery sweep in ConductionNL/market-intelligence,
`procest/_round4/discovery/build-plan.md`, names this change as the
vehicle for cluster 61, "Relations, split, merge and the relation graph".
Owner openregister, size L, seven candidates: C-case-core-15, -17, -19,
-22, -23, -33 and -38. Highest relevance `should`, no `must`, no matrix
hole. Passers: 9, eight driven and one documented. Proving system
kanboard. dossiq rates `partial` on one and `no` on six. The cluster
enters under D6 on relevance rather than on a count, and
C-case-core-17 is admitted under D21 as documented.

**What the cluster asks that the inverse label does not answer.** This
change names both ends of a link. The cluster asks what else a link can
be, and what you can do along one.

- **A new record starts from one entry of an existing one**
  (C-case-core-15): zammad, "Split
  (app/controllers/tickets_controller.rb:420-434, no provenance column)".
  One melding that turns out to be two zaken is routine, and zammad's own
  gap is worth copying deliberately: it keeps no provenance.
- **A sub-record inherits the parent's classification, sensitivity and
  responsible user** (C-case-core-22): opencase, "Case detail Actions
  (CaseDetail-CaseHierarchy.md)". dossiq: `DeelzaakService.php`.
- **A web address outside the product is an item on the record in its own
  right** (C-case-core-23, `could`): kanboard and plane, "Task, Add
  external link, with a title and a type".
- **Mentioning another record in a sentence records a navigable link on
  both sides** (C-case-core-33): forgejo and gitea, "Issue sidebar,
  Reference, and a mention in prose creating a timeline event".
- **The graph of what a record is linked to is drawn and exported**
  (C-case-core-38, `could`): glpi, "Impact graph (Tools,
  front/impactitem.php, impactcsv.php, src/Impact.php)", and
  request-tracker.
- **A recurring underlying cause is its own record many records point at**
  (C-case-core-17, documented): jira-service-management, "Create a problem
  work item". Tien bezwaren met dezelfde oorzaak zijn een beleidsprobleem.
- **A second record type with its own lifecycle beside the first, under
  one parent class** (C-case-core-19, `could`): glpi, "Assistance,
  Problems". Recorded, not built: OpenRegister's schemas already are
  separate types, and dossiq's note that `caseType` is data on one case
  schema is dossiq's modelling choice, not a platform gap.

**What the extension adds.**

- **A split that keeps its provenance.** A new object created from an
  entry of an existing one carries a typed relation to the source and the
  entry it came from. That is the column zammad does not have.
- **Declared inheritance along a relation.** A relation type may declare
  which properties a child takes from its parent at creation:
  classification, confidentiality and responsible principal are the three
  the corpus names. Inheritance happens once, at creation, and is recorded.
- **An external link is a relation target.** A URL with a title and a type
  is a relation row like any other, so it appears in the graph and in the
  reverse view rather than in a description field.
- **A reference written in prose is a relation.** A reference recorded by
  the timeline's pattern resolution creates a typed relation row on both
  sides, so the graph follows from the writing.
- **The graph is readable and exportable.** One bounded query returns the
  objects within a declared depth of a root, with the relation type and
  direction on each edge, and the same answer exports.

`relation-resourceurl-deeplinks` keeps the deep link shape, and the
reverse view of everything that references an object is
`objects-as-the-hinge-between-cases`. This extension owns the relation
row, not the surface that reads it.
