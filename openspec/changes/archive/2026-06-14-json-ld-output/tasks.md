# Tasks: JSON-LD Output

## Phase 1 — Context derivation

- [x] 1.1 Add `lib/Service/JsonLd/JsonLdContextService.php` with `buildSchemaContext(Schema $schema): array` and `buildRegisterContext(Register $register): array`: read the optional `configuration.jsonld` block (`@vocab`, `type`, `properties` term map), apply zero-config defaults (register-context fragment terms `{contextUrl}#{property}`, `@type` = schema slug), map JSON-Schema `format` hints to `@type` coercions (`date` → `xsd:date`, `date-time` → `xsd:dateTime`, `uri` → `@id`) and relation properties to `"@type": "@id"`, and always include the `or:` prefix (`https://openregister.app/ns#`). Request-scoped cache keyed by schema id + updated timestamp.
- [x] 1.2 Validate the `jsonld` mapping block when a schema is saved (`SchemasController` path): term values must be absolute IRIs or compact terms resolvable against the declared `@vocab`; reject invalid mappings with a 400 and a structured error. Unknown property names in the map are accepted with a warning (schemas evolve).
- [x] 1.3 Unit tests `tests/Unit/Service/JsonLd/JsonLdContextServiceTest.php`: zero-config defaults, full Schema.org mapping, partial mapping (mapped + default terms mixed), format coercions, relation property, cache hit (mapper called once).

## Phase 2 — Object serialization

- [x] 2.1 Add `lib/Service/JsonLd/JsonLdSerializer.php` with `serialize(array $renderedObject, Schema $schema, Register $register): array` and `serializeCollection(array $paginatedResult, Schema $schema, Register $register): array`: inject `@context` (per-schema context-document URL), `@id` (`ObjectEntity.uri`, fallback absolute `objects#show` route URL), `@type` (mapped class IRI or schema term); lift `@self` metadata to `or:` terms (`or:register`, `or:schema`, `or:urn`, `or:published`, `or:updated`, …) and do not emit `@self` itself; escape any data keys starting with `@`; collections become `@context` + `@graph` with pagination under `or:total` / `or:page` / `or:next`.
- [x] 2.2 Add `JsonLdSerializer::wantsJsonLd(IRequest $request): bool` implementing Accept-header negotiation (highest-q match wins; wildcards and absent header resolve to plain JSON).
- [x] 2.3 Unit tests `tests/Unit/Service/JsonLd/JsonLdSerializerTest.php`: single object happy path, missing `uri` fallback `@id`, `@`-prefixed data key escaping, collection `@graph` shape, Accept negotiation matrix (`application/ld+json`, `application/json`, `*/*`, q-weighted mixed header, absent header).

## Phase 3 — Content negotiation on read endpoints

- [x] 3.1 Wire negotiation into `ObjectsController::show()` and `ObjectsController::index()` only: after the existing render path, when `wantsJsonLd()` is true return the serialized JSON-LD with `Content-Type: application/ld+json` and `Vary: Accept`; otherwise the response stays byte-for-byte identical to today. Confirm RBAC / multitenancy / published-predicate / field-level-security behaviour is inherited unchanged (the serializer wraps the already-rendered array — no second data path).
- [x] 3.2 Leave all write verbs and other endpoints untouched; add a regression test asserting `POST/PUT/PATCH` responses are unaffected by an `Accept: application/ld+json` header.

## Phase 4 — Context document endpoints

- [x] 4.1 Add `lib/Controller/ContextsController.php` with `register(string $register)` and `schema(string $register, string $schema)` actions returning `{"@context": …}` as `application/ld+json` with `ETag` (schema/register `updated`-based) and `Cache-Control` headers; 404 for unknown register/schema slugs.
- [x] 4.2 Register the routes in `appinfo/routes.php` (`contexts#register` → `/api/contexts/{register}`, `contexts#schema` → `/api/contexts/{register}/{schema}`) with explicit auth posture: `#[NoAdminRequired]` for tenant users, plus public (`#[PublicPage]`) dereferencing when the register contains published schemas — context documents contain structure only, never object data. Verify route reachability + auth gates (hydra gates 5/14).
- [x] 4.3 Unit/integration tests: context shape for mapped + zero-config schemas, ETag conditional GET (304), unknown-slug 404, anonymous access allowed/denied per published state.

## Phase 5 — Spec + API tests

- [x] 5.1 Write `specs/json-ld-output/spec.md` (this change's delta); on archive, sync into `openspec/specs/`.
- [x] 5.2 Add Newman collection `tests/integration/openregister-jsonld.postman_collection.json`: object as JSON-LD (assert `@context`/`@id`/`@type` + `Content-Type` + `Vary`), same URL with `application/json` (assert legacy shape unchanged), collection `@graph`, context-document dereference (follow the `@context` URL from the object response), published-object + anonymous-context path, write-verb regression. Wire into `tests/newman/run-all.sh::DOMAIN_ORDER` after `crud`.
- [x] 5.3 Update `OasService` output so `objects#show`/`objects#index` document the `application/ld+json` response content type and the `/api/contexts/*` endpoints appear in the generated OAS.

## Phase 6 — Documentation + promise alignment

- [x] 6.1 Add `docs/` page "Linked Data / JSON-LD" covering: content negotiation, `@id`/URN identity, the `configuration.jsonld` mapping block with a Schema.org example, context endpoints, and the explicit read-only scope (no JSON-LD ingest).
- [x] 6.2 Align the README "Standards & Compliance" and info.xml wording with what now actually ships: JSON-LD output with derivable `@context`, Schema.org alignment **per schema mapping** (opt-in), no ingest. Bump `appinfo/info.xml` `<version>` with the release that ships this.
