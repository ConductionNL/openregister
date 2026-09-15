---
kind: code
---

# Proposal: property-code-list-from-concept-scheme

## Summary

Let a schema property take its allowed values from a SKOS concept scheme.
`skos-concept-registers` ships `conceptScheme` and `concept` schemas, an
importer and a resolution API, and nothing lets a property say "my values
are the concepts of scheme X". An app that wants a shared code list has to
copy it into `enum`, which is what dossiq's `propertyDefinition.enumValues`
does. This change adds `x-openregister-concepts` on a property: validation
against the scheme, options with labels for the form, and no schema change
when the list changes.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 11.10 | Code lists or choice fields | partial | S |

The register marks the row covered by `skos-concept-registers` under the
slug `code-lists-from-concepts (dossiq)`. The coverage check for this
programme found no requirement in that spec that lets a property reference
a scheme, so the openregister half is here; the dossiq lane keeps the
declaration.

## Why

The register's note: "`propertyDefinition.enumValues`; no shared code-list
entity". The best competitor, verbatim from the `best` column: "xxllnc
Zaken:
`backend/zaken/src/zsnl_domains/admin/catalog/entities/versioned_casetype.py`
(`_round2/compare/M1-functionality.md`)".

The register's `why`: "shared code lists are SKOS concept registers", and
its `dossiq_half`: "propertyDefinition.enumValues becomes a concept scheme
reference".

## What changes

- A string property (or an array of strings) MAY declare
  `x-openregister-concepts: { "scheme": "<scheme uri or uuid>",
  "store": "uri" | "notation", "allowDeprecated": false }` instead of
  `enum`. Declaring both is refused.
- On save the value must be a concept of that scheme (by URI or notation
  as declared); a deprecated concept is refused unless allowed; the error
  names the scheme.
- The schema read exposes the property's options
  (`{value, label, notation}` per concept, label in the negotiated
  language) so a form renders a select without knowing SKOS, bounded and
  searchable for large schemes through the existing concept resolution
  API.
- Object reads carry the concept's label beside the stored value under
  `@self.labels.<property>` when `_extend` asks for it.
- Editing the scheme (a new concept, a deprecation) needs no schema save;
  the options are read live and cached per scheme version.
- Facets on such a property group by concept and show labels.

## Consumers

- dossiq: `propertyDefinition.enumValues` becomes a concept scheme
  reference. Specified in dossiq by the dossiq lane under
  `code-lists-from-concepts`.
- opencatalogi (thema lists), stackiq (licence lists), humaniq (contract
  types), pipelinq: one scheme, many schemas.

## ADRs

- ADR-031: the code list is declared, not coded.
- ADR-048 (cross-app semantic references): a scheme is referenced by URI
  and resolves null-safe.
- openregister ADR-007: options for facets come from the built-in search
  path.
- ADR-025 (i18n source of truth): labels come from the concept's
  `prefLabel` per language.

## Impact

- Extends: `skos-concept-registers` (a new requirement on the property
  side) and the schema validator.
- Affected code: the schema validator, `ValidationHandler`
  (value-in-scheme), schema read (options), `RenderObject` (labels), the
  facet handler.
- Backwards compatible: `enum` keeps working.
- Size: S.

## Extension, discovery wave 1 (2026-09-14)

Cluster CT-4 of `procest/_round4/discovery/build-plan.md`, from the depth
study `procest/_round4/discovery/casetype-configurability.md`
(ConductionNL/market-intelligence, 2026-09-14): "Code lists a property
takes its values from". Study rows A4 and part of B3. Owner openregister
for the mechanism, dossiq for the declaration, size S each. The build plan
names this change as the vehicle, so it is extended rather than
duplicated.

The study's verdict on the mechanism is that it is already written here:

> `openregister/openspec/changes/property-code-list-from-concept-scheme`
> adds `x-openregister-concepts` so a property takes its values from a
> SKOS concept scheme, with labels for the form and no schema change when
> the list changes. dossiq's half is
> `openspec/changes/code-lists-from-concepts` (0 of 4), which adds
> `propertyDefinition.conceptScheme`. Both are written. Neither is built.

Two things sit beside that and are not in this change today.

**The B3 half, options narrowed by another field's value, is carried
elsewhere.** `code-list-lifecycle-and-hierarchy` REQ-CLH-002 lets a coded
property bind its option subset to another property's value or to a
declared context key, beside the hierarchy and the validity window. It is
written once, there, so the two do not diverge.

**The A4 defect has an openregister half.** The study's finding is
dossiq's editor: "the Properties tab has no input for enumValues, so
choosing enum yields a list nobody can fill". The reason an administrator
can reach that state at all is that this layer accepts a choice property
with no source of values. A property that is a choice and has neither a
non-empty `enum` nor a concept scheme is a field that can never be filled
correctly, and the save is where that is cheapest to catch. That
requirement is added below, and the editor half goes to the dossiq lane
with the study row.
