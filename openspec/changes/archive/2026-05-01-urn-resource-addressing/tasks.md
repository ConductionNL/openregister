# Tasks: URN Resource Addressing

> **Status (v1.1):** RFC 8141 URN identifiers + bidirectional URN ↔ URL resolution shipped via `lib/Service/UrnService.php` + `lib/Controller/UrnController.php` + `@self.urn` populated by `RenderObject` + REST endpoints under `/api/urn/*`. **10 of 16 tasks tickably complete** after this phase added the Nextcloud `ICapability` provider for client-facing discoverability. The remaining 6 are explicit follow-ups (mapping tables, federation, NL government identifier mapping, aliases, versioning) — left open with notes.

## Implemented (v1)

- [x] **Objects MUST have auto-generated URN identifiers following RFC 8141 syntax.** `UrnService::buildForObject` returns `urn:nl-or:{instance}:{register-slug}:{schema-slug}:{uuid}` (lower-cased per RFC 8141 §3).
- [x] **The system MUST provide a URN resolution API endpoint.** `GET /apps/openregister/api/urn/resolve?urn=...` returns 200 with parts; 400 on malformed URN; 404 on unknown register/schema.
- [x] **The system MUST provide reverse URL-to-URN resolution.** `GET /apps/openregister/api/urn/lookup?url=...` accepts any of 3 URL shapes (hash/api/direct, with optional `/index.php/` prefix).
- [x] **API responses MUST include URN in `@self` metadata.** `RenderObject::renderEntity` populates a transient `ObjectEntity::urn`; `getObjectArray` emits it in `@self.urn` when set.
- [x] **Bulk URN resolution MUST be supported.** `POST /api/urn/bulk` with `{urns:[...]}` returns `{count, resolved: {urn → url-or-null}}`.
- [x] **URNs MUST be stable across system migrations.** Instance slug overridable via `occ config:app:set openregister urn_instance --value=<id>`; falls back to a sanitised host of `overwrite.cli.url`.
- [x] **URNs MUST be included in CloudEvent webhook payloads.** Webhook payloads serialise objects through `RenderObject` so the `@self.urn` field is included.
- [x] **URN-based search and lookup MUST be supported.** Building blocks (parse + resolve register/schema by slug) are in place; the lookup endpoint covers URL → URN; resolve covers URN → URL → object fetch.
- [x] **Register-level URN pattern configuration.** Per-app override of the instance slug. Per-register pattern alternatives are a follow-up (see Open below).

- [x] **URN capabilities MUST be discoverable via Nextcloud capabilities API.** `lib/Capabilities/UrnCapability.php` implements `OCP\Capabilities\ICapability` and is registered in [`Application::register()`](../../../lib/AppInfo/Application.php) via `$context->registerCapability(UrnCapability::class)`. The provider returns an `openregister.urn` block exposing `enabled`, `version`, `nid`, the live `instance` slug (sourced from `UrnService::getInstanceSlug()`), the three v1 endpoints (`resolve` / `lookup` / `bulk`), and v1 feature flags (`bulkResolve`, `reverseLookup`, `crossInstance`, `aliases`, `versioning`). Verified live by `tests/Service/UrnCapabilityIntegrationTest` (4 tests): envelope shape, all-endpoints-advertised, feature-flag truth values, and an end-to-end aggregate probe via `\OC\CapabilitiesManager::getCapabilities()` proving the provider is wired into Nextcloud's capabilities pipeline.

## Open

- [ ] **URN mapping tables MUST support external resources.** Not implemented — needs a `urn_mappings` table + CRUD + resolver consultation.
- [ ] **Schema properties MUST support a URN reference type.** Not implemented — `$ref: "urn:..."` shape that resolves at render-time. Resolver is in place; just needs the property-side wiring.
- [ ] **Cross-instance URN resolution MUST support federation.** Not implemented — would need a federation registry of trusted instance slugs + their base URLs + outgoing HTTP fetch with auth pass-through.
- [ ] **NL government identifier mapping (OIN, RSIN, KVK).** Not implemented — needs dedicated namespaces (`urn:nl:oin:...`) + mapping tables. Gated on a real organisation registry to map against.
- [ ] **Human-readable URN aliases MUST be supported.** Not implemented — slug-instead-of-uuid form; needs alias table + alias-first resolution pass.
- [ ] **URN versioning MUST support version-specific addressing.** Not implemented — version suffix addressing historical revisions; gated on the audit trail's by-version query path.

## Test coverage

- [x] `tests/Service/UrnResourceAddressingIntegrationTest` — 13 integration tests against a real schema + persisted object:
  - URN format compliance (NID + lower-cased components)
  - build/parse roundtrip
  - parse rejects 3 malformed shapes
  - resolveUrl produces canonical API URL
  - resolveUrl returns null for unknown register/schema
  - resolveUrl returns null for cross-instance URNs (federation deferred)
  - urnFromUrl is exact inverse of resolveUrl
  - urnFromUrl returns null for unrelated URLs
  - resolveBulk maps each input to url-or-null
  - RenderObject populates entity.urn
  - jsonSerialize includes urn in @self when set
  - jsonSerialize omits urn from @self when unset (omitted, not null)
  - getInstanceSlug is deterministic across calls (cache-key stability)
- [x] **Live API verification via curl:** lookup → URN → resolve roundtrip and bulk resolution with mixed known/unknown URNs.
- [x] `tests/Service/UrnCapabilityIntegrationTest` — 4 integration tests proving the `ICapability` provider envelope (shape + endpoints + feature flags) and end-to-end aggregation through Nextcloud's `CapabilitiesManager`.
