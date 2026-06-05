# Tasks: OpenAPI Generation

> **Status:** `lib/Service/OasService.php` is in production. `tests/Service/OasGenerationIntegrationTest` (7 tests) verifies the generator end-to-end against a real register + schema. 11 of 16 spec tasks are tickably complete; 5 are partial / open with notes.

## Implemented

- [x] **Auto-generate OpenAPI 3.x specs from register/schema definitions.** `OasService::createOas(?string $registerId)` returns a structurally-valid OpenAPI envelope with `openapi/info/servers/paths/components`. **Verified live** by `testCreateOasReturnsValidOpenApiStructure`. Note: spec said 3.1.0; current generator emits 3.x (test asserts the `3.` prefix only).
- [x] **Schema property definitions MUST map correctly to OpenAPI types.** `string`/`integer`/`boolean` JSON-Schema types round-trip into `components.schemas[*].properties[*].type` correctly. **Verified live** by `testPropertyTypesMapToOpenApiTypes` (creates a 3-property fixture and asserts each type lands).
- [x] **OpenAPI spec MUST document CRUD endpoints accurately.** `paths` is non-empty for any register with schemas; collection + UUID-parameterised detail endpoints are documented. **Verified live** by `testCrudPathsAreDocumented`.
- [x] **Server URL MUST be absolute and instance-specific.** `servers[0].url` is built via `IURLGenerator::getAbsoluteURL('/apps/openregister/api')` so the URL adapts to the instance's base URL (overwrite.cli.url etc.). **Verified live** by `testServersUseAbsoluteInstanceSpecificUrl`.
- [x] **Multi-register specs MUST be organized with unique operation IDs and prefixed tags.** `createOas()` with no register id generates a single combined OAS; tags are scoped per register; operation IDs are register-prefixed to avoid collisions. **Verified live** indirectly by `testOasGeneratesForAllRegistersWhenIdOmitted` (no exception on multi-register generation).
- [x] **Schema names MUST be sanitized for OpenAPI compliance.** Schema slugs (which can contain hyphens) are sanitised to OpenAPI-compliant component names; the generator avoids characters that break `$ref` resolution.
- [x] **Composition keywords MUST be validated and cleaned.** `allOf`/`oneOf`/`anyOf` from the schema's JSON-Schema are passed through to the OAS but stripped of any non-OpenAPI extensions.
- [x] **The OpenAPI spec MUST be downloadable in JSON and YAML formats.** `OasController::index` (the controller wrapping `OasService`) accepts an `Accept` header negotiation between `application/json` and `application/x-yaml`; YAML conversion via `Symfony\Component\Yaml\Yaml::dump`.
- [x] **The OpenAPI spec MUST be versioned and track schema changes.** `info.version` is sourced from `$register->getVersion()`; updating the register's version regenerates the OAS with the new version.
- [x] **The spec MUST regenerate in real-time when schemas change.** `createOas()` is stateless — every call walks the current register/schema state. There's no caching layer that would serve stale specs.
- [x] **Schema property example payloads.** `components.schemas[*].example` is sourced from each property's `example` field where present.

## Open / partial

- [ ] **The OpenAPI spec MUST document authentication and RBAC authorization.** Partial — the OAS includes a top-level `securitySchemes` block (Basic auth via Nextcloud session), but per-endpoint `security` requirements that map RBAC rules onto OAS scopes are not yet generated. **Open** — depends on the `rbac-scopes` spec landing first (which generates the scope vocabulary).
- [ ] **The system MUST include example payloads in the generated spec.** Partial — schema property examples are surfaced; full request/response example payloads (illustrating an entire create or update body) aren't auto-generated. **Open** — requires fixture-derived examples or explicit annotations on schemas.
- [ ] **The system MUST serve a Swagger UI for interactive exploration.** Partial — `OasController::index` returns the OAS JSON; no in-app Swagger UI bundle is currently shipped. Users embed external Swagger UI / Redoc tooling pointing at the OAS endpoint. **Open** — UX decision whether to ship the Swagger UI assets in the app.
- [ ] **The spec MUST comply with NL API Design Rules markers.** Partial — basic structural compliance is present; specific NL ADR markers (`x-api-design-rules`, etc.) are not all wired. **Open** — separate spec `oas-validation` covers this in depth.
- [ ] **Extended endpoints MUST be controllable via whitelist.** Partial — extended endpoints (search, aggregation, calendar) are always documented; a whitelist toggle to suppress them per register isn't currently exposed. **Open** — UX feature.
- [ ] **API descriptions MUST support i18n.** Partial — descriptions today come from the register/schema text fields stored in the DB (single-language). Full i18n would require translation tables on those fields, which is out of scope for OAS generation. **Open**, gated on the `register-i18n` spec.

## Test coverage

- [x] `tests/Service/OasGenerationIntegrationTest` — 7 integration tests:
  - `testCreateOasReturnsValidOpenApiStructure` (root-level keys, OpenAPI 3.x version)
  - `testServersUseAbsoluteInstanceSpecificUrl` (URL absolute + instance-specific)
  - `testRegisterSpecificInfoTitleSurfacesRegisterTitle` (register scoping)
  - `testCrudPathsAreDocumented` (non-empty paths, collection + detail shapes)
  - `testComponentsSchemasIncludesTestSchema` (schema → components mapping)
  - `testPropertyTypesMapToOpenApiTypes` (string/integer/boolean → OAS types)
  - `testOasGeneratesForAllRegistersWhenIdOmitted` (multi-register baseline)
