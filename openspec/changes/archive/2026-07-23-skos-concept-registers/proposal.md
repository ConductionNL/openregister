---
kind: code
---

## Why

Controlled vocabularies are becoming a fleet-wide, legally forced capability:
NL-SBB (the Dutch SKOS profile for begrippenkaders, on the Forum
Standaardisatie list) is **mandatory for Federatief Datastelsel participants
per 1 January 2026**, DiWoo binds Woo publications to TOOI value lists,
DCAT-AP-NL 3.0 requires EU/NL vocabulary URIs, and ADR-048/049 semantic
references assume shared concept URIs across apps. Today every leaf hand-rolls
its own: OpenCatalogi carries `TooiVocabularyService` and
`DcatVocabularyService` with hardcoded lists, softwarecatalog needs GEMMA
referentiecomponenten, decidesk needs ORI/organisation URIs. Per ADR-022 a
capability needed by multiple leaves with schema-shaped data is
OpenRegister's to own — vocabularies are literally objects in registers.
This was identified in the 2026-07-23 OpenCatalogi market deep-dive (logged
in the spectr register, insight "NL-SBB mandatory for Federatief Datastelsel
participants per 1 Jan 2026") as the highest-leverage cross-app gap.

## What Changes

- Ship canonical **`conceptScheme`** and **`concept`** schemas (SKOS core
  mapped to schema.org-aligned property names per ADR-011): scheme URI,
  title, publisher, version; concept URI, prefLabel/altLabel (multilingual
  map), definition, notation, broader/narrower/related (canonical relation
  dialect `$ref` within the same register), `inScheme` reference, and NL-SBB
  conformance fields.
- Ship a **vocabulary register** definition (`lib/Settings`) that leaf apps
  can depend on, with `_rbac` public read (vocabularies are public data) and
  admin-gated writes.
- **Importer** for SKOS sources: given a SKOS RDF (Turtle/RDF-XML/JSON-LD)
  or a CSV value-list export, import/refresh a concept scheme idempotently
  (URI-keyed upsert, no duplicate concepts on re-import). Seed importers for
  TOOI value lists (informatiecategorieën, organisaties, documentsoorten)
  as bundled fixtures so a fresh install has the Woo-critical vocabularies.
- **Resolution API**: resolve a concept by URI or (scheme, notation) pair,
  and list concepts of a scheme with label search — the lookup surface
  OpenCatalogi's DCAT/DiWoo rendering and nc-vue concept-picker widgets
  consume.
- Leaf reintegration path (follow-up changes in the leaf repos, not here):
  OpenCatalogi's `TooiVocabularyService`/`DcatVocabularyService` become thin
  readers of the vocabulary register per ADR-022.

## Impact

- Affected specs: new capability `skos-concept-registers`.
- Affected code: new register/schema JSON under `lib/Settings`, a
  `VocabularyImportService`, a resolution endpoint on the existing public
  API surface, bundled TOOI fixtures, PHPUnit coverage.
- Not affected: existing leaf vocabularies keep working until each leaf's
  own consume-change lands (no breaking change in this repo).
