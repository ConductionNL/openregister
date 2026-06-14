# Design: Schema Import from Standards (Schema.org + GGM)

## Reuse analysis

- `SchemasController::upload()/uploadUpdate()` + `UploadService` — the
  existing ingestion path; importers slot in behind a dialect switch,
  the create/update plumbing stays.
- `Schema.configuration` (free JSON column) — provenance block
  (`importSource`) and the pre-filled `jsonld` mapping block, both
  round-tripped by the existing schemas API; no migration.
- `json-ld-output` change — defines the `configuration.jsonld`
  contract (`@vocab`, `type`, `properties` term map) that
  `SchemaOrgImporter` pre-fills; output-side conformance comes free.
- `schema-versioning-and-object-migration` change — structural diff +
  changelog + breaking-change gate; update-from-source rides the same
  shared schema-update path, so the gate applies without extra code
  here. Soft dependency: without it, update-from-source still works
  but with today's unconditional patch bump.
- hydra ADR-011 (schema standards) — org-wide direction this
  implements.

## Key decisions

### Bundled, versioned snapshots — no runtime fetching

Both vocabularies ship as data files in the app
(`lib/Resources/schemaorg/<version>.json`,
`lib/Resources/ggm/<version>.json`):

- deterministic imports (a schema imported on two instances is
  identical);
- no egress requirement / SSRF surface in the import path;
- admin-triggered snapshot refresh is a separate, explicit action
  (admin settings), not part of import.

Schema.org source: the official `schemaorg-current-https` JSON-LD
release file (types, properties, `domainIncludes`, `rangeIncludes`,
descriptions — everything needed without a reasoner). GGM source: the
published GGM release in its machine-readable export form; the importer
consumes a normalised intermediate JSON we generate from the VNG
publication (committed alongside the snapshot, generation script in
`tools/`), so EAP/UML parsing never happens at runtime.

### Importer interface is pluggable

```php
interface SchemaDialectImporter {
    public function dialect(): string;                          // 'schema.org' | 'ggm' | ...
    public function discover(string $query): array;             // candidates from snapshot
    public function import(string $reference, ImportOptions $o): ImportedSchema;
}
```

`ImportedSchema` = schema array (title, description, properties) +
`configuration` fragments (`jsonld`, `importSource`). Registered via DI
tagging so DCAT/SKOS/ZGW dialects are follow-up changes, not refactors
(mirrors the recipient-resolver pattern in the notification engine).

### Type-mapping tables are normative in the spec

Schema.org: `Text→string`, `Number→number`, `Integer→integer`,
`Boolean→boolean`, `Date→date`, `DateTime→date-time`, `Time→time`,
`URL→uri`; object ranges → `string`/`format: uri` (a reference, never a
recursive import); multi-range → most permissive member. GGM: tekst,
geheel getal, decimaal, boolean, datum, datumtijd per the spec table;
referentielijst → `enum` when values are present in the snapshot.
Direct-properties-by-default + explicit subset keeps `Person`-sized
types usable (~60 props would otherwise produce unusable schemas).

### Dialect detection is conservative

`DialectDetector` checks unambiguous markers only (`$schema` key;
`openapi` + `components`; `@context` containing `schema.org`; GGM
export root markers). Anything ambiguous → 422 listing supported
dialects + the `dialect` parameter. Explicit `dialect` always wins.
This *changes* one existing behaviour deliberately: arbitrary JSON that
isn't recognisably JSON Schema no longer falls through to a junk
import — the 422 is the fix, flagged in the upload tests.

### Update-from-source: three-way merge, conflicts explicit

Stored per import: the **imported baseline** (the property definitions
as the importer produced them) inside `importSource`. On re-import:

| property state | action |
|---|---|
| unchanged locally, changed in source | apply source |
| added locally | keep |
| modified locally, unchanged in source | keep local |
| modified locally AND changed in source | conflict → per-property confirmation |
| removed in source | report as removal (breaking → gated) |

The baseline makes "modified locally" decidable without guessing.
Apply step goes through the shared schema-update service path →
version bump/changelog/breaking gate when the sibling change is
present.

## API sketch

| Verb | Route | Purpose |
|---|---|---|
| GET | `/api/schema-import/{dialect}/types?q=` | discovery over snapshot |
| GET | `/api/schema-import/{dialect}/snapshot` | snapshot version info |
| POST | `/api/schema-import/{dialect}` | import (reference, options: propertySubset, includeAncestors, targetRegister) |
| POST | `/api/schemas/{id}/reimport` | update-from-source (preview / confirm with per-property conflict resolutions) |

Admin-gated (same authority as schema creation); explicit auth posture
on every route (gates 5/14/29). `schemas#upload`/`uploadUpdate` gain
the optional `dialect` parameter in place.

## Risks

- **Snapshot staleness** — versions are visible in discovery + admin
  settings; refreshing is an explicit admin action; provenance records
  which snapshot produced each schema.
- **GGM machine-readable form variance** — mitigated by the committed
  normalised intermediate + generation script; importer consumes only
  the intermediate.
- **Mapping disputes** (e.g. multi-range collapse) — tables are
  normative in the spec; deviations are spec changes, not silent code
  drift.
- **Upload 422 behaviour change** — narrow (only previously-junk
  input); called out in tasks for release notes.
