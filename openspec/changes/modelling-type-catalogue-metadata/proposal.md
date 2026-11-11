---
kind: code
---

# Proposal: modelling-type-catalogue-metadata

## Summary

A functional administrator describes each record type the way a data catalogue
needs it. A schema gets a data classification (open, internal, confidential,
strictly confidential), a maintainer, a contact person, the source system, an
update frequency and a documentation link. Anyone who may list schemas can
filter them by classification. A catalogue such as opencatalogi or a DCAT
harvester reads the same fields from the schema API.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | mod-catalogue-meta | Describe each record type with its owner, contact person, source system and update frequency, so it can be listed in a data catalogue | partial |
| openregister | acc-classification | Label each record type with a confidentiality level such as open, internal or confidential, and list types by that level | no |

Both rows come from Open Register's own matrix.

**mod-catalogue-meta.** Demand: changelog,
https://github.com/maykinmedia/open-object/blob/master/CHANGELOG.rst.
Competitor rated yes, objects-api (Objects API and Objecttypes API, source read at
4.2.1, not driven): "objecttype model carries maintainer organization and
department, contact person and e-mail, source system, update frequency, provider
organization, documentation URL and labels (objects-api:src/objects/core/models.py:65-123),
exposed on the objecttypes API (objects-api:src/objects/api/v2/views.py:95-99)".

**acc-classification.** Demand: the same changelog,
https://github.com/maykinmedia/open-object/blob/master/CHANGELOG.rst.
Competitor rated yes, objects-api (source read at 4.2.1, not driven): "each
objecttype carries data_classification (objects-api:src/objects/core/models.py:58)
with choices open, intern, confidential, strictly confidential
(objects-api:src/objects/core/constants.py:11-15), and the objecttypes endpoint
filters on dataClassification (objects-api:src/objects/api/v2/filters.py:141-149,
used by objects-api:src/objects/api/v2/views.py:99)".

On its own acc-classification would have been deferred (a changelog row and one
competitor, outside the core area). It is in this change because the Objects API
keeps the classification on the objecttype beside the catalogue fields, and both
land on the same entity and the same edit dialog.

## Why

`lib/Db/Schema.php` carries `description` (:492), `version` (:493), `owner`
(:512), `organisation` (:514) and linked contact entity ids (`contacts`, :522).
It has no classification, no source system, no update frequency, no maintainer
role and no documentation link. The matrix note says it plainly: "owner,
organisation and description exist; the catalogue fields a data catalogue needs
do not".

`lib/Db/Register.php:253` has a field called `classification`, but it is the
register type, not a data classification.

The per-object confidentiality tier is a different capability.
`confidentiality-classification-primitive` (open) gives an object a tier, a legal
ground and a release rule. This change labels the record type as a whole, the
way a catalogue lists it. The two do not overlap: a type classified `internal`
can hold objects whose own tier is `confidential`.

## What changes

- A schema gains `classification`: one of `open`, `internal`, `confidential`,
  `strictly-confidential`, or null. It is a column, so a list can filter on it.
- A schema gains a `catalogue` block: `maintainer` (organisation and department),
  `contact` (name and e-mail), `sourceSystem`, `updateFrequency` (a fixed
  vocabulary: `realtime`, `daily`, `weekly`, `monthly`, `yearly`, `irregular`),
  `documentationUrl` and `labels`.
- `GET /api/schemas` accepts `classification` as a filter, and the schema JSON
  carries both fields.
- The schema edit dialog shows a Catalogue tab. The tab lives in nextcloud-vue's
  `CnSchemaFormDialog`; Open Register passes the vocabulary.
- The OpenAPI and JSON-LD output of a register carries the classification and
  catalogue fields of each schema, so a DCAT harvester can read them.

## Consumers

- opencatalogi lists record types in a catalogue and can show their
  classification and maintainer without a field of its own.
- stackiq and every app that ships a register JSON can declare the block in its
  descriptor, so the catalogue metadata travels with the app.

## ADRs

- hydra ADR-001 (data layer): the fields live on the schema entity, not in an app.
- hydra ADR-022: apps consume the platform field rather than growing their own.
- hydra ADR-011 (schema standards): the classification vocabulary follows the
  Objects API values so an import from that API maps one to one.
- openregister ADR-006: the classification is descriptive metadata. It is not an
  access decision. Access stays with schema RBAC.

## Impact

- New capability `schema-catalogue-metadata`.
- Affected code: `lib/Db/Schema.php`, a migration, `lib/Db/SchemaMapper.php`
  (filter), `lib/Controller/SchemasController.php`, `lib/Service/OasService.php`
  and the JSON-LD output, `lib/Service/Configuration` export and import.
- Backwards compatible: both fields are nullable and absent means unset.
- Size: M.

## Out of scope

- Enforcing anything from the classification. Access stays with schema RBAC and,
  per object, with `confidentiality-classification-primitive`.
- The same block on a register. A register can gain it later with the same shape.
- The Catalogue tab markup itself, which is a nextcloud-vue change named in
  tasks.md.
