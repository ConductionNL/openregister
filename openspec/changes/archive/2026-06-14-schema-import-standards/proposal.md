# Schema Import from Standards (Schema.org + GGM)

## Why

The README promises, under Features, "**Schema Import** — Import
schemas from Schema.org, OpenAPI, and GGM standards" and lists
Schema.org as a supported data standard under "Standards &
Compliance". The 2026-06-11 feature re-evaluation
(`FEATURE-REEVALUATION-2026-06-11/openregister.md`) rated the
Schema.org/GGM half of that promise a **Medium-severity MISSING**
feature: `schemas#upload` / `schemas#uploadUpdate` exist and ingest
JSON Schema (and the OpenAPI path is touched by `openapi-generation`),
but Schema.org and GGM import are unreferenced in code, specs, and
changes — and the re-evaluation's recommendation 2 calls for closing
exactly this README-vs-spec drift before the 12 June production notice
lifts. The just-proposed `json-ld-output` change explicitly lists
"Schema *import from* Schema.org/GGM" as its out-of-scope sibling gap.

Beyond promise-keeping, this is the on-ramp that makes OR's
standards story real for its actual buyers:

- **Schema.org** is the lingua franca of the open-data ecosystem OR
  claims to connect to. Importing `schema:Person` should yield a
  working register schema in one action — and, now that `json-ld-output`
  derives `@context` from schema configuration, an imported Schema.org
  type can arrive with its vocabulary mapping **pre-filled**, so the
  object's JSON-LD serialization is Schema.org-conformant with zero
  manual mapping.
- **GGM (Gemeentelijk Gegevensmodel)** is the VNG-maintained municipal
  data model. Dutch municipalities — the fleet's primary audience
  (opencatalogi, procest, softwarecatalog all sit on OR) — standardise
  on GGM objecttypes; hand-transcribing them into JSON Schema is
  error-prone busywork that a register platform should do, preserving
  the Dutch definitions and the provenance trail that
  informatiebeheerders need to demonstrate standards alignment.

## What Changes

- **Schema.org type import.** A new import path accepts a Schema.org
  type reference (e.g. `https://schema.org/Person` or `Person`) and
  produces a register schema: properties derived from the type's
  declared properties (direct properties by default; ancestors'
  properties opt-in), Schema.org datatypes mapped to JSON Schema
  types/formats, descriptions carried over, and the
  `configuration.jsonld` mapping block (per `json-ld-output`)
  pre-filled with `@vocab: https://schema.org/`, the class IRI, and
  per-property term mappings. The import is **selective**: the caller
  may restrict to a property subset (Schema.org types are huge;
  `Person` alone has ~60 properties).
- **GGM objecttype import.** A new import path accepts a GGM objecttype
  from the published GGM definitions (bundled snapshot and/or uploaded
  GGM export file), producing a register schema with Dutch
  titles/definitions preserved, GGM attribute types mapped to JSON
  Schema, and enumeration (referentielijst) values carried into
  `enum` where the source defines them.
- **Dialect detection + explicit dialect parameter on upload.** The
  existing `schemas#upload` / `schemas#uploadUpdate` ingestion gains a
  `dialect` parameter (`json-schema` | `openapi` | `schema.org` |
  `ggm`) with detection as fallback; undetectable input fails with 422
  instead of being mis-imported as JSON Schema.
- **Import provenance + update-from-source.** Imported schemas record
  their source (dialect, identifier, source version, importedAt) in
  schema configuration; a re-import against the same source produces a
  classified diff preview (reusing the structural diff from
  `schema-versioning-and-object-migration` when present) and applies
  updates without silently destroying local property customisations —
  locally-modified properties are conflict-reported, not overwritten.
- New capability spec `specs/schema-import/spec.md`.

## Problem

1. **Published promise, no implementation** — README advertises
   Schema.org and GGM import; a municipal evaluator who tries it finds
   only raw JSON Schema upload.
2. **Standards alignment is manual today** — modelling a GGM
   objecttype or Schema.org type means hand-writing JSON Schema,
   losing definitions, getting types subtly wrong, and leaving no
   provenance trail to the standard.
3. **The JSON-LD story is half-connected** — `json-ld-output` lets
   schemas declare Schema.org vocabulary mappings, but every mapping
   must be authored by hand; importing *from* Schema.org should
   produce that mapping for free.

## Proposed Solution

- `lib/Service/SchemaImport/SchemaOrgImporter.php` — consumes the
  Schema.org vocabulary definition (bundled versioned snapshot of the
  official `schemaorg-current-https` release file; optional re-fetch
  by admins), resolves a type, maps properties/datatypes, emits the
  schema array + `configuration.jsonld` block.
- `lib/Service/SchemaImport/GgmImporter.php` — consumes GGM objecttype
  definitions (bundled versioned snapshot of the published GGM release;
  upload of a GGM export also accepted), maps attribuutsoorten to JSON
  Schema, preserves Dutch metadata.
- `lib/Service/SchemaImport/DialectDetector.php` — shared sniffing for
  the upload path (`$schema` key → JSON Schema; `openapi`/`components`
  → OAS; `@context`/Schema.org IRIs → schema.org; GGM export markers →
  ggm).
- Provenance stored under `Schema.configuration.importSource`;
  re-import flows through the standard schema-update path (and thus
  the breaking-change gate, when `schema-versioning-and-object-migration`
  lands).
- API: discovery endpoints to browse importable Schema.org types / GGM
  objecttypes (name search over the bundled snapshots), and import
  endpoints accepting type id + options (property subset, target
  register).

## Out of scope

- **OpenAPI import** — already served by the existing upload path and
  the `openapi-generation` spec's surface; no change here beyond
  dialect detection labelling it.
- **Live federation with schema.org / GGM upstreams** (auto-tracking
  releases) — snapshots are versioned and admin-refreshable;
  continuous sync is not a goal.
- **Full RDF/OWL reasoning** — property resolution is the pragmatic
  Schema.org JSON release file, not an ontology reasoner; `rangeIncludes`
  with multiple types maps to the most permissive JSON type.
- **Other dialects** (DCAT, SKOS, ZGW informatiemodel, NEN2660) — the
  importer interface is pluggable; additional dialects are follow-up
  changes.
- **Object data import** — this is schema/model import only; data
  import stays `data-import-export`.
- A visual mapping/curation UI beyond property-subset selection —
  follow-up once the importers exist.

## See also

- `FEATURE-REEVALUATION-2026-06-11/openregister.md` — MISSING row 3
  ("Schema import from Schema.org and GGM standards", Medium) and
  recommendation 2.
- `README.md` ("Schema Import" feature bullet; "Data standard: JSON
  Schema, JSON-LD, Schema.org") — the promise this change backs.
- `openspec/changes/json-ld-output/` — the output-side sibling; its
  `configuration.jsonld` mapping block is what Schema.org import
  pre-fills.
- `openspec/changes/schema-versioning-and-object-migration/` —
  structural diff + breaking-change gate reused by update-from-source.
- `lib/Controller/SchemasController.php` (`upload`/`uploadUpdate`) +
  `lib/Service/UploadService.php` — the ingestion path gaining
  dialects.
- Schema.org releases (`schemaorg-current-https` JSON-LD release
  file); GGM — VNG Gemeentelijk Gegevensmodel publications; hydra
  ADR-011 (schema standards: schema.org, DCAT).
