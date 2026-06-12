# Tasks: Schema Import from Standards (Schema.org + GGM)

## Phase 1 — Importer framework + dialect detection

- [ ] 1.1 Add `lib/Service/SchemaImport/SchemaDialectImporter.php` interface (`dialect()`, `discover()`, `import()`), `ImportOptions` / `ImportedSchema` value objects, and DI tag registration for pluggable dialects.
- [ ] 1.2 Add `lib/Service/SchemaImport/DialectDetector.php` (conservative markers: `$schema` → json-schema; `openapi`+`components` → openapi; `@context` w/ schema.org → schema.org; GGM export root markers → ggm; ambiguous → null).
- [ ] 1.3 Wire the optional `dialect` parameter into `SchemasController::upload()/uploadUpdate()` + `UploadService`: explicit dialect wins, detection as fallback, undetectable input → HTTP 422 structured error listing supported dialects; json-schema/openapi paths byte-for-byte unchanged.
- [ ] 1.4 Unit tests: detector matrix (each dialect, ambiguous, explicit override), upload 422 path, regression tests proving existing JSON Schema + OAS uploads unchanged.

## Phase 2 — Schema.org importer

- [ ] 2.1 Bundle the `schemaorg-current-https` release file as a versioned snapshot under `lib/Resources/schemaorg/` with a loader (lazy parse + cache).
- [ ] 2.2 Implement `SchemaOrgImporter`: type resolution by IRI/bare name (404 on unknown), direct properties by default, `includeAncestors` option (walk `rdfs:subClassOf`), `propertySubset` option (unknown requested properties reported back), datatype mapping table (Text/Number/Integer/Boolean/Date/DateTime/Time/URL; object ranges → string+`format: uri`; multi-range → most permissive), descriptions carried over.
- [ ] 2.3 Pre-fill `configuration.jsonld` (`@vocab: https://schema.org/`, type = class IRI, per-property term map) per the `json-ld-output` contract, plus `configuration.importSource` (dialect, reference, snapshot version, importedAt, imported baseline property definitions).
- [ ] 2.4 Unit tests: Person subset import, ancestor opt-in, each datatype mapping, multi-range collapse, unknown type 404, jsonld block shape, baseline recorded.

## Phase 3 — GGM importer

- [ ] 3.1 Add `tools/generate-ggm-snapshot` (build-time script) converting the published GGM release into the normalised intermediate JSON; commit snapshot under `lib/Resources/ggm/` with version metadata.
- [ ] 3.2 Implement `GgmImporter`: objecttype import with Dutch name/definition preserved as title/description (schema + per-attribute), attribute type mapping (tekst/geheel getal/decimaal/boolean/datum/datumtijd), referentielijst → `enum` when values present, relations → single reference property (no recursive import), provenance with GGM release version.
- [ ] 3.3 Accept an uploaded GGM export file as an alternative source (admin-only), normalised through the same intermediate; provenance points at the upload.
- [ ] 3.4 Unit tests: metadata preservation, mapping table, enum import, relation-as-reference, upload-vs-snapshot equivalence.

## Phase 4 — Discovery + import API

- [ ] 4.1 Add `lib/Controller/SchemaImportController.php`: `GET /api/schema-import/{dialect}/types?q=` (name search over snapshot: id, label, description, parent, snapshot version), `GET /api/schema-import/{dialect}/snapshot`, `POST /api/schema-import/{dialect}` (reference + options + target register). Admin-gated, explicit auth posture, routes in `appinfo/routes.php` (gates 5/14/29).
- [ ] 4.2 Admin settings surface: bundled snapshot versions visible; explicit snapshot-refresh action (separate from import; no egress during import).
- [ ] 4.3 Unit/integration tests: discovery search both dialects, import end-to-end creating a schema in a register, authorization (non-admin 403).

## Phase 5 — Provenance + update-from-source

- [ ] 5.1 Implement `POST /api/schemas/{id}/reimport`: re-run the source import, three-way merge against the stored imported baseline (apply source-only changes; keep local additions; keep local-only modifications; conflicts = locally-modified AND source-changed → per-property confirmation required; source removals reported), preview mode returning the classified diff before any apply.
- [ ] 5.2 Apply path flows through the shared schema-update service (version bump + changelog + breaking-change acknowledgement gate when `schema-versioning-and-object-migration` is present; document the soft dependency and the degraded pre-gate behaviour).
- [ ] 5.3 Expose provenance via the schemas API read shape.
- [ ] 5.4 Unit tests: each merge-table row, conflict confirmation flow, preview non-mutation, provenance read.

## Phase 6 — Spec, e2e, docs, promise alignment

- [ ] 6.1 Sync `specs/schema-import/spec.md` into `openspec/specs/` on archive.
- [ ] 6.2 Newman collection `tests/integration/openregister-schema-import.postman_collection.json`: discovery, Schema.org import + JSON-LD round-trip (import Person subset → create object → `Accept: application/ld+json` → assert schema.org terms), GGM import, upload dialect 422, reimport preview/conflict; wire into `tests/newman/run-all.sh`.
- [ ] 6.3 Minimal UI: "Import from standard" action in the schema-create flow (dialect picker → type search → property-subset selection); Playwright e2e for the happy path.
- [ ] 6.4 Docs page "Importing schemas from standards" (Schema.org, GGM, dialect parameter, provenance, update-from-source + conflicts); align README wording with what ships; note the upload-422 behaviour change in release notes. Bump `appinfo/info.xml` `<version>`.
