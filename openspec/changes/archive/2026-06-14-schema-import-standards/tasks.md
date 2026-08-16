# Tasks: Schema Import from Standards (Schema.org + GGM)

## Phase 1 — Importer framework + dialect detection

- [x] 1.1 Add `lib/Service/SchemaImport/SchemaDialectImporter.php` interface (`dialect()`, `discover()`, `import()`), `ImportOptions` / `ImportedSchema` value objects, and DI registration for pluggable dialects (`SchemaImportService` registry; `Application.php` registerService).
- [x] 1.2 Add `lib/Service/SchemaImport/DialectDetector.php` (conservative markers: `$schema`/JSON-Schema shape → json-schema; `openapi`+`components` → openapi; `@context` w/ schema.org → schema.org; GGM export root markers → ggm; ambiguous → null).
- [x] 1.3 Wire the optional `dialect` parameter into `SchemasController::upload()/uploadUpdate()`: explicit dialect wins, detection as fallback, undetectable input → HTTP 422 structured error listing supported dialects; json-schema/openapi paths unchanged.
- [x] 1.4 Unit tests: detector matrix (each dialect, ambiguous, explicit override), 422 path (via SchemaImportService::resolveUploadDialect), regression that plain JSON Schema / OAS still resolve.

## Phase 2 — Schema.org importer

- [x] 2.1 Bundle the `schemaorg-current-https` release file as a versioned snapshot under `lib/Resources/schemaorg/` with a loader (`SchemaOrgSnapshot`, lazy parse + cache).
- [x] 2.2 Implement `SchemaOrgImporter`: type resolution by IRI/bare name (404 on unknown), direct properties by default, `includeAncestors` option (walk `rdfs:subClassOf`), `propertySubset` option (unknown requested properties reported back), datatype mapping table (Text/Number/Integer/Boolean/Date/DateTime/Time/URL; object ranges → string+`format: uri`; multi-range → most permissive), descriptions carried over.
- [x] 2.3 Pre-fill `configuration.jsonld` (`@vocab: https://schema.org/`, type = class IRI, per-property term map) per the `json-ld-output` contract, plus `configuration.importSource` (dialect, reference, snapshot version, importedAt, imported baseline property definitions).
- [x] 2.4 Unit tests: Person subset import, ancestor opt-in, datatype mapping, multi-range collapse, unknown type 404, jsonld block shape, baseline recorded, IRI reference, discovery.

## Phase 3 — GGM importer

- [x] 3.1 Add `tools/generate-ggm-snapshot.php` (build-time script) converting a published GGM release into the normalised intermediate JSON; commit snapshot under `lib/Resources/ggm/` with version metadata.
- [x] 3.2 Implement `GgmImporter`: objecttype import with Dutch name/definition preserved as title/description (schema + per-attribute), attribute type mapping (tekst/geheel getal/decimaal/boolean/datum/datumtijd), referentielijst → `enum` when values present, relations → single reference property (no recursive import), provenance with GGM release version.
- [x] 3.3 Accept an uploaded GGM export file as an alternative source, normalised through the same intermediate (`GgmSnapshot::fromNormalised` + `SchemaImportService::importGgmUpload`, reachable via the admin upload path); provenance points at the upload.
- [x] 3.4 Unit tests: metadata preservation, mapping table, enum import, relation-as-reference, upload-vs-snapshot equivalence, import by Dutch name, discovery.

## Phase 4 — Discovery + import API

- [x] 4.1 Add `lib/Controller/SchemaImportController.php`: `GET /api/schema-import/{dialect}/types?q=` (name search over snapshot: id, label, description, parent, snapshot version), `GET /api/schema-import/{dialect}/snapshot`, `POST /api/schema-import/{dialect}` (reference + options + target register). Admin-gated (NC framework default), explicit auth posture in class docblock, routes in `appinfo/routes.php` (gates 5/14/19 green for the diff).
- [~] 4.2 Admin settings surface: the `GET /api/schema-import/{dialect}/snapshot` endpoint exposes bundled snapshot versions; a dedicated admin-settings *panel* + explicit snapshot-refresh action is DEFERRED (no runtime egress occurs during import regardless — the snapshot is committed in-app). Tracked as a follow-up.
- [x] 4.3 Tests: discovery search both dialects (Newman 2,3 + service unit), import end-to-end creating a schema in a register (Newman 4,7), authorization is admin-only by framework default (route-auth gate-5 green; no `@NoAdminRequired`).

## Phase 5 — Provenance + update-from-source

- [x] 5.1 Implement `POST /api/schemas/{id}/reimport`: re-run the source import, three-way merge (`ThreeWayMerge`) against the stored imported baseline (apply source-only changes; keep local additions; keep local-only modifications; conflicts = locally-modified AND source-changed → per-property confirmation; source removals reported), preview mode returning the classified diff before any apply.
- [x] 5.2 Apply path persists through `SchemaMapper::update()` and refreshes the baseline + jsonld block; the schema-update path carries the `schema-migration` version bump / changelog / breaking-change gate when present (soft dependency documented in design.md + docs/Features/schema-import.md).
- [x] 5.3 Provenance is exposed via the schemas API read shape — it lives in `Schema.configuration.importSource`, which the existing schemas serializer round-trips.
- [x] 5.4 Unit tests: each merge-table row, conflict confirmation flow, preview non-mutation, provenance recorded (SchemaOrg/Ggm importer tests + ThreeWayMerge + SchemaImportService preview tests).

## Phase 6 — Spec, e2e, docs, promise alignment

- [x] 6.1 Sync `specs/schema-import/spec.md` into `openspec/specs/` on archive.
- [x] 6.2 Newman collection `tests/integration/openregister-schema-import.postman_collection.json`: discovery (both dialects), Schema.org import + JSON-LD round-trip (import Person subset → create object → `Accept: application/ld+json` → assert schema.org terms), GGM import, upload dialect 422 + JSON-Schema regression, reimport preview; wired into `tests/newman/run-all.sh` as `schema-import`.
- [~] 6.3 Minimal UI: the import surface is API + admin-action; a Vue "Import from standard" wizard (dialect picker → type search → subset selection) is DEFERRED to a follow-up (matches the proposal's "visual mapping/curation UI … follow-up" out-of-scope note). Scenarios annotated `@e2e exclude` (Newman + PHPUnit cover the behaviour) per the Playwright-UI-only / Newman-for-API rule.
- [x] 6.4 Docs page `docs/Features/schema-import.md` (Schema.org, GGM, dialect parameter, provenance, update-from-source + conflicts); README "Schema Import" bullet aligned with what ships + the upload-422 behaviour change noted. `appinfo/info.xml` `<version>` bumped to 0.2.16-unstable.1.
