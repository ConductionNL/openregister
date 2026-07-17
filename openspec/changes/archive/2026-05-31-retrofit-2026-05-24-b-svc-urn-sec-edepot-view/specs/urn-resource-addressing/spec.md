# URN Resource Addressing — Shipped UrnService surface

> Reverse-spec delta. The canonical `urn-resource-addressing` spec is marked **Not implemented** and describes an aspirational design (organisation-as-NID, a persisted `urn` column, `/api/urn/*` endpoints, a `UrnMapping` table, federation, NL-gov identifier mapping). The requirements below document ONLY the behaviour that actually ships in `lib/Service/UrnService.php` and deliberately do not adopt the aspirational surface.

## ADDED Requirements

### Requirement: URNs MUST be constructed on demand in the shipped `urn:nl-or:{instance}:{register}:{schema}:{uuid}` shape

`UrnService` MUST construct RFC 8141 URNs using a fixed informal NID `nl-or` (per RFC 8141 §5.1) followed by four colon-separated segments: an instance slug, the register slug, the schema slug, and the object UUID. URNs MUST be built on demand and MUST NOT be persisted on the object or auto-generated at object-creation time.

- `build(registerSlug, schemaSlug, uuid)` MUST emit `urn:nl-or:{instance}:{register}:{schema}:{uuid}` with the register slug, schema slug, and uuid lower-cased (URN comparison is case-insensitive per RFC 8141 §3; emitting one canonical case avoids cache mismatches).
- `buildForObject(ObjectEntity)` MUST return `null` when the object lacks a UUID, or when the register or schema reference cannot be resolved to a slug; otherwise it MUST resolve the register/schema slugs and delegate to `build()`.
- `getInstanceSlug()` MUST resolve the instance slug in order: (1) the `openregister.urn_instance` app-config value when non-empty, (2) the sanitised host portion of the absolute base URL, (3) the literal `localhost` as a final fallback. Sanitisation MUST lower-case and replace runs of non-alphanumeric characters with single hyphens, trimming leading/trailing hyphens.

#### Scenario: Build a URN from explicit parts
- **GIVEN** register slug `decidesk`, schema slug `meeting`, and uuid `1C1C970F-D50C-4943-8128-78999E240EEC`
- **WHEN** `build()` is called
- **THEN** the result MUST be `urn:nl-or:{instance}:decidesk:meeting:1c1c970f-d50c-4943-8128-78999e240eec` with all three trailing segments lower-cased
- **AND** the `{instance}` segment MUST equal the value returned by `getInstanceSlug()`

#### Scenario: Build for an object with incomplete identity returns null
- **GIVEN** an `ObjectEntity` with a null or empty UUID, OR whose register/schema reference does not resolve to a slug
- **WHEN** `buildForObject()` is called
- **THEN** the method MUST return `null` rather than emitting a partial URN

#### Scenario: Instance slug honours config override then host then localhost
- **GIVEN** `openregister.urn_instance` is set to `My Stable ID`
- **WHEN** `getInstanceSlug()` is called
- **THEN** it MUST return the sanitised slug `my-stable-id`
- **AND** when the config value is empty it MUST fall back to the sanitised host of the base URL, and to `localhost` when no host can be derived

### Requirement: The shipped resolver MUST map URN↔URL in-memory for the local instance only

`UrnService` MUST provide in-memory, on-demand resolution between URNs and OpenRegister API URLs for the local instance. Cross-instance / federated resolution is explicitly out of scope of the shipped service and MUST return `null`. There is no `/api/urn/*` HTTP endpoint and no external `UrnMapping` table in the shipped surface.

- `parse(urn)` MUST return `['instance','register','schema','uuid']` only when the string matches the anchored RFC 8141 URN regex AND its NID equals `nl-or` AND the NSS has at least four colon-separated parts; otherwise it MUST return `null`.
- `resolveUrl(urn)` MUST return `null` when the URN does not parse, when its instance segment differs from the local `getInstanceSlug()`, or when the register/schema cannot be looked up; otherwise it MUST return the absolute API URL `/apps/openregister/api/objects/{register-slug}/{schema-slug}/{uuid}` (each path segment `rawurlencode`d) via `IURLGenerator`.
- `urnFromUrl(url)` MUST recognise the three URL shapes the Smart Picker `ObjectReferenceProvider` accepts — hash-routed (`#/registers/{id}/schemas/{id}/objects/{uuid}`), API (`/api/objects/{ref}/{ref}/{uuid}`), and direct (`/objects/{ref}/{ref}/{uuid}`) — resolve numeric ids or slugs to canonical slugs, and return the constructed URN, or `null` when the URL does not match or the register/schema does not resolve.
- `resolveBulk(urns)` MUST return a `{urn => url|null}` map preserving input order, skipping non-string / empty entries, by delegating each entry to `resolveUrl()`. The shipped method imposes no per-request URN cap.

#### Scenario: Parse rejects a foreign NID
- **GIVEN** a string `urn:isbn:0451450523`
- **WHEN** `parse()` is called
- **THEN** it MUST return `null` because the NID is not `nl-or`
- **AND** a well-formed `urn:nl-or:{instance}:{register}:{schema}:{uuid}` MUST parse into its four named parts

#### Scenario: Resolve a local URN to its API URL
- **GIVEN** a URN whose instance segment equals the local instance slug and whose register/schema resolve
- **WHEN** `resolveUrl()` is called
- **THEN** the result MUST be the absolute `/apps/openregister/api/objects/{register}/{schema}/{uuid}` URL with `rawurlencode`d segments

#### Scenario: Cross-instance or unresolvable URN returns null
- **GIVEN** a URN whose instance segment differs from the local instance slug, OR whose register/schema cannot be found
- **WHEN** `resolveUrl()` is called
- **THEN** it MUST return `null` (federation is out of scope for the shipped service)

#### Scenario: Reverse-resolve a Smart Picker URL
- **GIVEN** an OpenRegister object URL in the hash-routed, API, or direct shape
- **WHEN** `urnFromUrl()` is called and the register/schema resolve
- **THEN** the method MUST return the canonical `urn:nl-or:{instance}:{register-slug}:{schema-slug}:{uuid}` URN
- **AND** a non-OpenRegister URL MUST yield `null`

#### Scenario: Bulk resolve preserves order and maps misses to null
- **GIVEN** a list mixing resolvable and unresolvable URNs plus a non-string entry
- **WHEN** `resolveBulk()` is called
- **THEN** the result MUST be a `{urn => url|null}` map where resolvable URNs map to their URL and unresolvable ones map to `null`
- **AND** the non-string / empty entry MUST be skipped (absent from the map)

## Notes

- The shipped shape diverges from the canonical aspirational spec: NID is the fixed informal `nl-or` (not the organisation slug); the instance segment derives from host/config (not register `organisation`); URNs are not persisted on `ObjectEntity` nor surfaced in `@self`; there is no resolution endpoint, no `UrnMapping` table, no schema `urn` property type, and no federation. Those remain genuinely unimplemented and are intentionally NOT specced here.
- `findRegister`/`findSchema` call the mappers with `_rbac: false, _multitenancy: false`, so URN resolution confirms register/schema *existence* without tenant scoping. Object-level RBAC still applies when the resolved URL is subsequently fetched; only the existence/buildability signal is unscoped.
