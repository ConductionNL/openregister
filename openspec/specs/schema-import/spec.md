# schema-import Specification

## Purpose
TBD - created by archiving change schema-import-standards. Update Purpose after archive.
## Requirements
### Requirement: Schema.org types MUST be importable as register schemas

The system MUST import a Schema.org type — referenced by IRI
(`https://schema.org/Person`) or bare name (`Person`) — from a bundled,
versioned snapshot of the official Schema.org vocabulary release,
producing a register schema where:

- each imported Schema.org property becomes a JSON Schema property with
  its description carried over;
- Schema.org datatypes map to JSON Schema types/formats (`Text` →
  `string`; `Number` → `number`; `Integer` → `integer`; `Boolean` →
  `boolean`; `Date` → `string`/`format: date`; `DateTime` →
  `string`/`format: date-time`; `URL` → `string`/`format: uri`;
  object-typed ranges default to `string`/`format: uri` references;
  multi-type ranges map to the most permissive of their members);
- the type's **direct** properties are imported by default; properties
  inherited from ancestor types are included only when requested;
- the caller MAY restrict the import to an explicit property subset,
  and the response identifies any requested properties that do not
  exist on the type;
- the schema's `configuration.jsonld` block (per `json-ld-output`) is
  pre-filled with `@vocab: https://schema.org/`, the class IRI as the
  type mapping, and per-property term mappings — so JSON-LD output of
  the schema's objects is Schema.org-conformant without manual mapping;
- an unknown type reference fails with HTTP 404 naming the reference.

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Import schema:Person with a property subset
- GIVEN the bundled Schema.org snapshot
- WHEN `Person` is imported with the property subset `["givenName", "familyName", "email", "birthDate"]`
- THEN a schema is created with exactly those four properties
- AND `birthDate` has type `string` with `format: date`, and `email` carries the Schema.org description
- AND `configuration.jsonld` maps the schema to `https://schema.org/Person` and each property to its Schema.org term

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: JSON-LD output of an imported type is Schema.org-conformant
- GIVEN a schema imported from `schema:Person`
- WHEN an object of that schema is requested with `Accept: application/ld+json`
- THEN the serialized `@type` resolves to `https://schema.org/Person`
- AND the imported properties resolve to `https://schema.org/...` terms via the pre-filled mapping

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Ancestor properties are opt-in
- GIVEN `Person` is a subtype of `Thing`
- WHEN `Person` is imported without the include-ancestors option
- THEN `Thing`-level properties such as `name` and `description` are not included
- AND importing with include-ancestors adds them

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Unknown type fails clearly
- WHEN a type `Persoon` (not in the Schema.org vocabulary) is imported
- THEN the request fails with HTTP 404 identifying the unknown reference

### Requirement: GGM objecttypes MUST be importable as register schemas

The system MUST import a GGM (Gemeentelijk Gegevensmodel) objecttype
from a bundled, versioned snapshot of a published GGM release — or from
an uploaded GGM export file — producing a register schema where:

- the objecttype's Dutch name and definition become the schema's title
  and description, and each attribuutsoort's Dutch name/definition
  become the property title/description (Dutch text preserved
  verbatim);
- GGM attribute types map to JSON Schema types/formats (tekst →
  `string`; geheel getal → `integer`; decimaal → `number`; boolean →
  `boolean`; datum → `string`/`format: date`; datumtijd →
  `string`/`format: date-time`);
- attributes bound to a referentielijst whose values are present in the
  source are imported as `enum` values;
- relations to other objecttypes are imported as reference properties
  (string identifiers), NOT recursively imported;
- the import records which GGM release version it came from.

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Import a GGM objecttype with Dutch metadata
- GIVEN the bundled GGM snapshot
- WHEN a GGM objecttype (e.g. a municipal domain objecttype) is imported
- THEN the schema's title and description are the objecttype's Dutch name and definition
- AND each attribute's type maps per the GGM mapping table

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Referentielijst becomes an enum
- GIVEN an imported objecttype with an attribute bound to a referentielijst whose values are in the source
- WHEN the import completes
- THEN the property carries those values as `enum`

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Relations become references, not recursive imports
- GIVEN an objecttype with a relation to another objecttype
- WHEN it is imported
- THEN the relation is a single reference property
- AND no second schema is created implicitly

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: GGM export upload
- GIVEN a GGM export file uploaded by an administrator
- WHEN an objecttype from that file is imported
- THEN the resulting schema is equivalent to a snapshot-based import of the same objecttype, with provenance pointing at the uploaded source

### Requirement: Importable types MUST be discoverable

The system MUST expose discovery endpoints that list/search importable
Schema.org types and GGM objecttypes by name over the bundled
snapshots, returning for each candidate its identifier, label,
description, and (for Schema.org) its parent type. Discovery MUST also
report the snapshot version in use. Administrators MUST be able to see
which snapshot versions are bundled.

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Search Schema.org types
- WHEN the Schema.org discovery endpoint is queried with `q=person`
- THEN the results include `Person` with its IRI, description, and parent type `Thing`
- AND the response carries the Schema.org snapshot version

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Search GGM objecttypes
- WHEN the GGM discovery endpoint is queried with a Dutch term
- THEN matching objecttypes are returned with their Dutch definitions and the GGM release version

### Requirement: Schema upload MUST detect or be told its dialect and reject unidentifiable input

The system MUST treat the schema ingestion path (`schemas#upload` /
`schemas#uploadUpdate`) as dialect-aware. The path
MUST accept an optional explicit `dialect` parameter (`json-schema`,
`openapi`, `schema.org`, `ggm`). When absent, the dialect MUST be
detected: a `$schema`/JSON-Schema shape → `json-schema`; an
`openapi`/`components` document → `openapi` (existing behaviour, now
labelled); Schema.org JSON-LD markers (`@context` referencing
schema.org) → `schema.org`; GGM export markers → `ggm`. An explicit
`dialect` overrides detection. Input matching no dialect MUST fail with
HTTP 422 and a structured error instead of being mis-ingested as JSON
Schema. Existing JSON Schema and OpenAPI uploads MUST behave unchanged.

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Explicit dialect overrides detection
- GIVEN an uploaded document that detection would classify as `json-schema`
- WHEN it is uploaded with `dialect: "schema.org"`
- THEN it is processed by the Schema.org import path (and fails its validation if it is not Schema.org input)

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Undetectable input rejected
- GIVEN an uploaded JSON document matching no known dialect
- WHEN it is uploaded without an explicit dialect
- THEN the upload fails with HTTP 422 and a structured error listing the supported dialects

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Existing uploads unaffected
- GIVEN a plain JSON Schema document
- WHEN it is uploaded without a dialect parameter
- THEN it is ingested exactly as before this change

### Requirement: Imported schemas MUST record provenance and support guarded update-from-source

Every standards-imported schema MUST record, in its configuration, an
import source block: dialect, source identifier (type IRI / objecttype
id), source/snapshot version, and import timestamp. Re-importing the
same source onto an existing schema MUST:

- produce a **diff preview** (added/removed/changed properties between
  the schema's current definition and the fresh import) before
  applying;
- preserve locally added properties untouched;
- report — not silently overwrite — properties whose local definition
  was modified after import and which the new source also changes
  (conflicts require explicit per-property confirmation);
- flow through the standard schema-update path, so version bump,
  changelog, and the breaking-change acknowledgement gate (per
  `schema-migration`, when present) apply as for any other update.

Provenance MUST be visible via the schemas API.

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Provenance recorded on import
- GIVEN a schema imported from `schema:Person` (snapshot vX)
- WHEN the schema is read via the API
- THEN its configuration exposes dialect `schema.org`, source `https://schema.org/Person`, snapshot version, and import timestamp

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Update-from-source previews the diff
- GIVEN an imported schema and a newer source snapshot that adds a property
- WHEN update-from-source is requested
- THEN a diff preview lists the added property
- AND nothing is applied until the update is confirmed

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Local additions survive re-import
- GIVEN an imported schema to which a local property `internalNote` was added
- WHEN update-from-source is confirmed
- THEN `internalNote` is preserved unchanged

<!-- @e2e exclude API/admin import surface verified via Newman (openregister-schema-import.postman_collection.json) + pure PHPUnit mapping tests; UI import wizard deferred (proposal out-of-scope). -->
#### Scenario: Locally modified property conflicts are reported
- GIVEN an imported property whose constraints were locally tightened
- AND the new source also changes that property
- WHEN update-from-source is requested
- THEN the property is flagged as a conflict requiring explicit per-property confirmation
- AND it is not overwritten without that confirmation

