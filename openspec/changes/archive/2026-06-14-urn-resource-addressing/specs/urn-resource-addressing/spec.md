---
status: draft
---
# URN Resource Addressing

## Purpose

Implement bidirectional URN-URL mapping for system-independent resource identification, enabling Dutch government organisations to address register objects across multi-vendor environments without coupling to specific system URLs or database identifiers. Every register object MUST support a URN identifier following RFC 8141 syntax that can be resolved to an API URL and vice versa, ensuring stable addressing across system migrations, domain changes, and federated deployments. This spec covers URN format definition, resolution APIs, cross-instance federation, NL government identifier mapping, event integration, and human-readable aliases.

**Source**: Gap identified in cross-platform analysis; part of Dutch government standards ecosystem (VNG Common Ground, NL GOV API Design Rules).

**Cross-references**: deep-link-registry (URL template resolution for consuming apps), referential-integrity (URN-based cross-references in `$ref` properties), data-sync-harvesting (URN stability across federated sync sources).

## ADDED Requirements

### Requirement: Objects MUST have auto-generated URN identifiers following RFC 8141 syntax

Every register object MUST have an auto-generated URN following the pattern `urn:{organisation}:{system}:{component}:{resource}:{uuid}` where each segment maps to register and schema metadata. The URN MUST conform to RFC 8141 (Uniform Resource Names) syntax rules: the NID (Namespace Identifier) is the organisation slug, and the NSS (Namespace Specific String) encodes the system, component (register slug), resource (schema slug), and object UUID. Characters in each segment MUST be limited to RFC 8141 allowed characters: unreserved characters (A-Z, a-z, 0-9, `-`, `.`, `_`, `~`) and percent-encoded characters. The URN MUST be generated at object creation time and stored persistently on the `ObjectEntity`.

#### Scenario: Auto-generate URN on object creation
- **GIVEN** a register `zaken` with organisation `gemeente-utrecht` and system `openregister`
- **AND** schema `meldingen` in that register
- **WHEN** a new melding object with UUID `550e8400-e29b-41d4-a716-446655440000` is created
- **THEN** a URN MUST be generated: `urn:gemeente-utrecht:openregister:zaken:meldingen:550e8400-e29b-41d4-a716-446655440000`
- **AND** the URN MUST be stored on the `ObjectEntity.urn` field
- **AND** the URN MUST be returned in the `@self` metadata block of API responses

#### Scenario: Reject invalid URN segment characters
- **GIVEN** a register with organisation name `gemeente utrecht` (contains a space)
- **WHEN** a new object is created
- **THEN** the system MUST sanitize the organisation name to `gemeente-utrecht` (replacing spaces with hyphens)
- **AND** the resulting URN MUST contain only RFC 8141 allowed characters
- **AND** if sanitization is not possible (e.g., all invalid characters), the system MUST reject the operation with a 422 error

#### Scenario: URN includes version-independent base
- **GIVEN** a register object with version `3.2.1`
- **WHEN** the URN is generated
- **THEN** the base URN MUST NOT include the version number
- **AND** the base URN `urn:gemeente-utrecht:openregister:zaken:meldingen:{uuid}` MUST remain stable across all versions of the object

#### Scenario: URN uniqueness enforcement
- **GIVEN** object A already exists with URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **WHEN** an attempt is made to create or import another object with the same URN
- **THEN** the system MUST reject the operation with a 409 Conflict response
- **AND** the error message MUST include the conflicting URN and the existing object's UUID

#### Scenario: URN persists through object updates
- **GIVEN** an existing object with URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **WHEN** the object's data properties are updated (name, description, custom fields)
- **THEN** the URN MUST remain unchanged
- **AND** the `@self.urn` field in the response MUST match the original URN

### Requirement: Register-level URN pattern configuration

Administrators MUST be able to configure URN patterns at the register level. The register entity MUST store the organisation identifier, system name (defaults to `openregister`), and an optional custom component override. This configuration determines the URN prefix for all objects in that register. The configuration MUST be stored in the `Register` entity metadata (via `IAppConfig` or register properties) and MUST be editable through the admin UI and API.

#### Scenario: Configure URN pattern per register
- **GIVEN** the admin configures register `producten` with:
  - Organisation: `gemeente-utrecht`
  - System: `openregister`
  - Custom component: `pdc`
- **WHEN** objects are created in this register
- **THEN** all objects MUST use URN pattern: `urn:gemeente-utrecht:openregister:pdc:{schema-slug}:{uuid}`

#### Scenario: Default URN configuration when not explicitly set
- **GIVEN** a register `zaken` without explicit URN configuration
- **WHEN** an object is created
- **THEN** the system MUST use defaults: organisation from register's `organisation` field, system `openregister`, component from register's `slug` field
- **AND** the resulting URN pattern MUST be `urn:{register.organisation}:openregister:{register.slug}:{schema.slug}:{object.uuid}`

#### Scenario: Update URN configuration does not change existing URNs
- **GIVEN** register `zaken` has 500 objects with URNs using organisation `gemeente-utrecht`
- **WHEN** the admin changes the organisation to `gemeente-amersfoort`
- **THEN** existing objects MUST retain their original URNs
- **AND** only new objects MUST use the updated organisation
- **AND** the admin MUST receive a warning that existing URNs will not be retroactively changed

### Requirement: The system MUST provide a URN resolution API endpoint

A dedicated resolution endpoint MUST translate URNs to the corresponding API URLs and object metadata. The endpoint MUST be registered in `routes.php` as `GET /api/urn/resolve` and accept a `urn` query parameter. The response MUST include the resolved URL (generated via `IURLGenerator::linkToRouteAbsolute()`), object UUID, register slug, schema slug, and object existence status. For external URN mappings, the endpoint MUST also check the `UrnMapping` table.

#### Scenario: Resolve internal URN to URL and metadata
- **GIVEN** a URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **AND** the corresponding object exists in the database
- **WHEN** `GET /api/urn/resolve?urn=urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123` is called
- **THEN** the response MUST return HTTP 200 with:
  ```json
  {
    "url": "https://gemeente-utrecht.nl/index.php/apps/openregister/api/objects/zaken/meldingen/abc-123",
    "urn": "urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123",
    "objectUuid": "abc-123",
    "register": "zaken",
    "schema": "meldingen",
    "organisation": "gemeente-utrecht",
    "exists": true
  }
  ```

#### Scenario: Resolve non-existent URN
- **GIVEN** a URN `urn:gemeente-utrecht:openregister:zaken:meldingen:does-not-exist`
- **AND** no object or external mapping matches this URN
- **WHEN** the resolution endpoint is queried
- **THEN** the response MUST return HTTP 404 with:
  ```json
  {
    "error": "URN not found",
    "urn": "urn:gemeente-utrecht:openregister:zaken:meldingen:does-not-exist",
    "suggestion": "Verify the URN format and ensure the resource exists"
  }
  ```

#### Scenario: Resolve URN with malformed syntax
- **GIVEN** a URN `not-a-valid-urn`
- **WHEN** the resolution endpoint is queried
- **THEN** the response MUST return HTTP 400 with a descriptive error indicating the URN does not conform to RFC 8141 syntax
- **AND** the error MUST specify which part of the URN is invalid

#### Scenario: Resolve external URN via mapping table
- **GIVEN** an external URN mapping exists for `urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789` pointing to `https://zaaksysteem.gemeente-utrecht.nl/api/zaken/xyz-789`
- **WHEN** the resolution endpoint is queried with this URN
- **THEN** the response MUST return the mapped URL with `"external": true` and `"exists": null` (existence not verified for external resources)

#### Scenario: Resolution endpoint supports content negotiation
- **GIVEN** a valid URN for an existing object
- **WHEN** the resolution endpoint is called with `Accept: text/uri-list`
- **THEN** the response MUST return only the resolved URL as plain text
- **AND** when called with `Accept: application/json` (default), the full metadata response is returned

### Requirement: The system MUST provide reverse URL-to-URN resolution

A reverse resolution endpoint MUST translate API URLs back to URN identifiers. The endpoint MUST be registered as `GET /api/urn/reverse` and accept a `url` query parameter. The reverse resolver MUST parse the URL to extract register slug, schema slug, and object UUID, then construct the corresponding URN using the register's URN configuration.

#### Scenario: Reverse resolve URL to URN
- **GIVEN** object `abc-123` exists in register `zaken`, schema `meldingen`
- **AND** the register has organisation `gemeente-utrecht`
- **WHEN** `GET /api/urn/reverse?url=https://gemeente-utrecht.nl/index.php/apps/openregister/api/objects/zaken/meldingen/abc-123` is called
- **THEN** the response MUST return:
  ```json
  {
    "urn": "urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123",
    "url": "https://gemeente-utrecht.nl/index.php/apps/openregister/api/objects/zaken/meldingen/abc-123"
  }
  ```

#### Scenario: Reverse resolve non-OpenRegister URL
- **GIVEN** a URL `https://example.com/some-other-api/resource/123`
- **AND** no external URN mapping exists for this URL
- **WHEN** the reverse endpoint is queried
- **THEN** the response MUST return HTTP 404 with a message indicating no URN mapping exists for the given URL

#### Scenario: Reverse resolve external mapped URL
- **GIVEN** an external URN mapping for URL `https://zaaksysteem.gemeente-utrecht.nl/api/zaken/xyz-789`
- **WHEN** the reverse endpoint is queried with this URL
- **THEN** the response MUST return the mapped URN: `urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789`

### Requirement: URN mapping tables MUST support external resources

The system MUST support registering URN-URL mappings for resources that live outside of OpenRegister. External mappings MUST be stored in a dedicated `UrnMapping` entity with fields: `urn` (indexed, unique), `url`, `label`, `sourceSystem`, `metadata` (JSON), `createdAt`, and `updatedAt`. The entity MUST follow Nextcloud's Entity/Mapper pattern and be managed via a `UrnMappingMapper`.

#### Scenario: Register external URN mapping via API
- **GIVEN** an external system hosts resource `urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789`
- **WHEN** `POST /api/urn/mappings` is called with:
  ```json
  {
    "urn": "urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789",
    "url": "https://zaaksysteem.gemeente-utrecht.nl/api/zaken/xyz-789",
    "label": "Zaak XYZ-789 - Omgevingsvergunning",
    "sourceSystem": "zaaksysteem"
  }
  ```
- **THEN** the mapping MUST be persisted in the `urn_mappings` table
- **AND** the mapping MUST be queryable via the resolution endpoint

#### Scenario: Bulk import external mappings from CSV
- **GIVEN** a CSV file with 1000 URN-URL pairs from an external system with columns: `urn`, `url`, `label`, `sourceSystem`
- **WHEN** the admin uploads via `POST /api/urn/mappings/import`
- **THEN** the import MUST be processed as a `QueuedJob` to avoid HTTP timeout
- **AND** the response MUST return a job ID for status tracking
- **AND** duplicates MUST be detected (by URN) and reported in the job result
- **AND** the job result MUST include counts: `created`, `skipped`, `errors`

#### Scenario: Delete external URN mapping
- **GIVEN** an external mapping for `urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789`
- **WHEN** `DELETE /api/urn/mappings/{id}` is called
- **THEN** the mapping MUST be removed from the database
- **AND** subsequent resolution of this URN MUST return 404

#### Scenario: List all external mappings with filtering
- **GIVEN** 50 external URN mappings from 3 different source systems
- **WHEN** `GET /api/urn/mappings?sourceSystem=zaaksysteem&_limit=20` is called
- **THEN** the response MUST return only mappings from `zaaksysteem`, paginated to 20 results
- **AND** the response MUST include standard `_page`, `_pages`, `_total` pagination metadata

### Requirement: URNs MUST be stable across system migrations

URN identifiers MUST remain valid even if the underlying URL, domain, or system infrastructure changes. The URN is the permanent identifier; the URL is the current location. The system MUST support updating URL mappings without changing URNs. Old URLs SHOULD return HTTP 301 redirects to new URLs when the redirect mapping is configured.

#### Scenario: Update URL for existing URN after domain migration
- **GIVEN** a URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123` mapped to `https://old-server.nl/index.php/apps/openregister/api/objects/zaken/meldingen/abc-123`
- **WHEN** the system migrates to `https://new-server.nl`
- **THEN** the URN MUST remain unchanged: `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **AND** the resolution endpoint MUST return the new URL automatically (via `IURLGenerator::linkToRouteAbsolute()` which uses the current server configuration)
- **AND** the `@self.urn` field on objects MUST remain identical

#### Scenario: URN survives register slug change
- **GIVEN** register `zaken` is renamed to `zaakregistratie` (slug change)
- **AND** 200 objects exist with URNs containing `zaken` as the component segment
- **WHEN** the slug change is saved
- **THEN** all existing URNs MUST remain unchanged (the URN was assigned at creation time)
- **AND** new objects MUST use the new slug `zaakregistratie` in their URNs
- **AND** both old and new URNs MUST be resolvable

#### Scenario: Export URN-URL mapping for migration
- **GIVEN** a register with 10,000 objects, each with a URN
- **WHEN** `GET /api/urn/export?register=zaken&format=csv` is called
- **THEN** the response MUST stream a CSV file with columns: `urn`, `url`, `objectUuid`, `register`, `schema`, `created`
- **AND** the export MUST complete without memory exhaustion (streamed output)

### Requirement: API responses MUST include URN in `@self` metadata

All API responses that return objects MUST include the URN in the `@self` metadata block. The `@self` block already contains `id` (UUID), `slug`, `register`, and `schema`; the URN MUST be added as an additional field. This applies to single object responses, collection responses, and search results. The URN provides a system-independent identifier alongside the URL-dependent `id`.

#### Scenario: Single object response includes URN
- **GIVEN** an object with URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **WHEN** `GET /api/objects/zaken/meldingen/abc-123` is called
- **THEN** the response `@self` block MUST include:
  ```json
  {
    "@self": {
      "id": "abc-123",
      "urn": "urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123",
      "slug": "melding-fietspad",
      "register": "zaken",
      "schema": "meldingen"
    }
  }
  ```

#### Scenario: Collection response includes URN per object
- **GIVEN** a collection of 25 objects in register `zaken`, schema `meldingen`
- **WHEN** `GET /api/objects/zaken/meldingen` is called
- **THEN** each object in the `results` array MUST include `@self.urn`
- **AND** the URN MUST be unique per object in the response

#### Scenario: Search results include URN
- **GIVEN** a unified search query matches 5 objects across 3 schemas
- **WHEN** the search results are returned (via `ObjectsProvider`)
- **THEN** each search result MUST include the URN in its metadata
- **AND** if the deep-link-registry has a URL template for the schema, the search result URL MUST use the deep link while the URN remains in metadata

### Requirement: Schema properties MUST support a URN reference type

The schema property type system MUST support a `urn` property type for cross-system linking. When a property is defined as type `urn`, the system MUST validate that the value conforms to RFC 8141 URN syntax. The UI MUST attempt to resolve the URN and display the resource name (if resolvable) with a clickable link to the resolved URL.

#### Scenario: Define a URN reference property on a schema
- **GIVEN** schema `vergunningen` with property definition:
  ```json
  {
    "bronZaak": {
      "type": "urn",
      "title": "Bron zaak",
      "description": "URN referentie naar de oorspronkelijke zaak"
    }
  }
  ```
- **WHEN** the schema is saved
- **THEN** the property MUST accept URN values and reject non-URN strings

#### Scenario: Validate URN format on property save
- **GIVEN** schema `vergunningen` with property `bronZaak` of type `urn`
- **WHEN** the user sets `bronZaak` to `not-a-urn`
- **THEN** the system MUST reject the value with a validation error: "Value must be a valid URN (RFC 8141)"
- **AND** the object MUST NOT be saved

#### Scenario: Resolve URN reference in UI display
- **GIVEN** an object with `bronZaak` set to `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **AND** the URN resolves to an object named "Melding fietspad Heidelberglaan"
- **WHEN** the object is displayed in the UI
- **THEN** the `bronZaak` field MUST display "Melding fietspad Heidelberglaan" as a clickable link
- **AND** the link MUST point to the deep-link-resolved URL if one exists, otherwise to the OpenRegister object detail view

#### Scenario: URN reference to external resource
- **GIVEN** an object with `bronZaak` set to `urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789`
- **AND** an external URN mapping exists for this URN
- **WHEN** the object is displayed
- **THEN** the field MUST display the mapping's `label` as a clickable link to the mapped URL
- **AND** an external link icon MUST indicate the resource is outside OpenRegister

### Requirement: Bulk URN resolution MUST be supported

The resolution endpoint MUST support resolving multiple URNs in a single request to avoid N+1 API calls when rendering views with many cross-references. The bulk endpoint MUST accept up to 100 URNs per request and return a map of URN to resolution result.

#### Scenario: Bulk resolve multiple URNs
- **GIVEN** 10 URNs, 8 of which resolve to existing objects and 2 are not found
- **WHEN** `POST /api/urn/resolve` is called with:
  ```json
  {
    "urns": [
      "urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123",
      "urn:gemeente-utrecht:openregister:zaken:meldingen:def-456",
      "urn:gemeente-utrecht:openregister:zaken:meldingen:not-found-1",
      "..."
    ]
  }
  ```
- **THEN** the response MUST return HTTP 200 with a map:
  ```json
  {
    "resolved": {
      "urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123": {
        "url": "https://...",
        "objectUuid": "abc-123",
        "exists": true
      }
    },
    "unresolved": [
      "urn:gemeente-utrecht:openregister:zaken:meldingen:not-found-1"
    ]
  }
  ```

#### Scenario: Bulk resolution respects rate limits
- **GIVEN** a request with 150 URNs (exceeding the 100 limit)
- **WHEN** the bulk endpoint is called
- **THEN** the response MUST return HTTP 400 with an error: "Maximum 100 URNs per request"

#### Scenario: Bulk resolution includes mixed internal and external URNs
- **GIVEN** 5 internal URNs and 3 external URN mappings
- **WHEN** bulk resolution is called
- **THEN** the response MUST resolve both internal and external URNs
- **AND** external URNs MUST be marked with `"external": true` in the result

### Requirement: URNs MUST be included in CloudEvent webhook payloads

When webhooks fire for object lifecycle events (created, updated, deleted), the CloudEvent payload MUST include the object's URN in the event data. The existing `CloudEventFormatter` MUST be extended to include the URN alongside the object UUID and other metadata. This ensures event consumers can identify resources using system-independent URNs rather than relying on URLs or internal IDs.

#### Scenario: Object creation event includes URN
- **GIVEN** a webhook is configured for `object.created` events
- **AND** a new object is created with URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **WHEN** the webhook fires
- **THEN** the CloudEvent payload `data` MUST include:
  ```json
  {
    "urn": "urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123",
    "uuid": "abc-123",
    "register": "zaken",
    "schema": "meldingen"
  }
  ```

#### Scenario: Object update event includes URN
- **GIVEN** a webhook configured for `object.updated` events
- **WHEN** an existing object is updated
- **THEN** the CloudEvent payload MUST include the unchanged URN
- **AND** the `subject` field of the CloudEvent SHOULD be set to the URN

#### Scenario: Object deletion event includes URN for traceability
- **GIVEN** a webhook configured for `object.deleted` events
- **WHEN** an object with URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123` is deleted
- **THEN** the CloudEvent payload MUST include the URN even though the object no longer exists
- **AND** event consumers MUST be able to use the URN for audit trail and cross-reference cleanup

### Requirement: Cross-instance URN resolution MUST support federation

For federated deployments where multiple OpenRegister instances sync data via harvesting (see data-sync-harvesting spec), URN resolution MUST support cross-instance lookups. When a local resolution fails, the system MUST optionally query known federated instances. Federation endpoints MUST be configurable per register and MUST follow the same resolution API contract.

#### Scenario: Resolve URN from federated instance
- **GIVEN** local instance `gemeente-utrecht.nl` cannot resolve URN `urn:gemeente-amersfoort:openregister:zaken:meldingen:xyz-789`
- **AND** `gemeente-amersfoort.nl` is configured as a federated peer in the register's sync sources
- **WHEN** `GET /api/urn/resolve?urn=...&federated=true` is called
- **THEN** the system MUST query `https://gemeente-amersfoort.nl/index.php/apps/openregister/api/urn/resolve?urn=...`
- **AND** return the remote result with `"federated": true` and `"source": "gemeente-amersfoort.nl"`
- **AND** the remote resolution MUST respect a configurable timeout (default 5 seconds)

#### Scenario: Cache federated URN resolution results
- **GIVEN** a federated URN was resolved from `gemeente-amersfoort.nl`
- **WHEN** the same URN is resolved again within 1 hour
- **THEN** the cached result MUST be returned without querying the remote instance
- **AND** the response MUST include `"cached": true` and `"cachedAt": "2026-03-19T10:00:00+01:00"`

#### Scenario: Federated resolution disabled by default
- **GIVEN** a URN that does not match any local object or mapping
- **AND** the `federated` query parameter is not set or is `false`
- **WHEN** the resolution endpoint is called
- **THEN** the system MUST NOT query any remote instances
- **AND** the response MUST return 404 with a hint: `"hint": "Try ?federated=true to search peer instances"`

### Requirement: NL government identifier mapping (OIN, RSIN, KVK)

The system MUST support mapping Dutch government identifiers (OIN - Organisatie Identificatie Nummer, RSIN - Rechtspersonen Samenwerkingsverbanden Informatienummer, KVK - Kamer van Koophandel nummer) to URN organisation segments. This enables interoperability with Dutch government registries (Handelsregister, BRP, BAG) that use these identifiers. The mapping MUST be configurable at the register level.

#### Scenario: Map OIN to URN organisation segment
- **GIVEN** register `zaken` is configured with:
  - Organisation slug: `gemeente-utrecht`
  - OIN: `00000001001299757000`
  - RSIN: `301641992`
- **WHEN** URNs are generated for objects in this register
- **THEN** the URN MUST use the organisation slug: `urn:gemeente-utrecht:openregister:zaken:{schema}:{uuid}`
- **AND** the OIN and RSIN MUST be stored as register metadata for cross-referencing
- **AND** a lookup by OIN MUST resolve to the same register (e.g., `GET /api/urn/organisations?oin=00000001001299757000`)

#### Scenario: Resolve URN by alternative identifier
- **GIVEN** a register configured with OIN `00000001001299757000` and slug `gemeente-utrecht`
- **WHEN** an external system queries with a URN using the OIN as organisation: `urn:00000001001299757000:openregister:zaken:meldingen:abc-123`
- **THEN** the system MUST recognize the OIN and resolve it as equivalent to `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **AND** the canonical URN (using the slug) MUST be returned in the response

#### Scenario: KVK number for non-government organisations
- **GIVEN** a private organisation uses OpenRegister with KVK number `12345678`
- **AND** the register is configured with organisation slug `bedrijf-x` and KVK `12345678`
- **WHEN** a URN lookup includes `kvk=12345678`
- **THEN** the system MUST resolve it to the register owned by `bedrijf-x`

### Requirement: URN-based search and lookup MUST be supported

The system MUST support searching for objects by URN or partial URN. The existing search infrastructure (MagicMapper, ObjectsProvider) MUST be extended to index and query URN fields. This enables users to paste a URN into the search bar and find the corresponding object.

#### Scenario: Find object by exact URN
- **GIVEN** an object with URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- **WHEN** `GET /api/objects?_search=urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123` is called
- **THEN** the object MUST be returned as the sole result
- **AND** the match MUST be exact (not fuzzy)

#### Scenario: Find objects by partial URN (wildcard)
- **GIVEN** 50 objects in register `zaken`, schema `meldingen`
- **WHEN** `GET /api/objects?urn=urn:gemeente-utrecht:openregister:zaken:meldingen:*` is called
- **THEN** all 50 objects MUST be returned (matching the URN prefix)
- **AND** pagination MUST apply normally

#### Scenario: Unified search finds by URN
- **GIVEN** a user types `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123` in the Nextcloud unified search bar
- **WHEN** the `ObjectsProvider` processes the search query
- **THEN** the object MUST appear in the search results
- **AND** the deep-link-registry MUST be used for URL resolution (if a deep link is registered for the schema)

### Requirement: Human-readable URN aliases MUST be supported

Objects MUST support optional human-readable URN aliases alongside their canonical UUID-based URN. An alias replaces the UUID segment with a slug or meaningful identifier (e.g., `urn:gemeente-utrecht:openregister:pdc:producten:paspoort-aanvragen`). Aliases MUST be unique within the same register and schema scope. Both the canonical URN and the alias MUST resolve to the same object.

#### Scenario: Create object with human-readable alias
- **GIVEN** register `pdc` with schema `producten`
- **WHEN** an object is created with slug `paspoort-aanvragen`
- **THEN** two URNs MUST be resolvable:
  - Canonical: `urn:gemeente-utrecht:openregister:pdc:producten:550e8400-e29b-41d4-a716-446655440000`
  - Alias: `urn:gemeente-utrecht:openregister:pdc:producten:paspoort-aanvragen`

#### Scenario: Alias uniqueness conflict
- **GIVEN** an existing object with alias URN `urn:gemeente-utrecht:openregister:pdc:producten:paspoort-aanvragen`
- **WHEN** a second object in the same register and schema is created with slug `paspoort-aanvragen`
- **THEN** the system MUST reject the duplicate slug (existing behavior via slug uniqueness)
- **AND** the canonical UUID-based URN MUST still be generated

#### Scenario: Alias changes when slug changes
- **GIVEN** an object with slug `paspoort-aanvragen` and corresponding alias URN
- **WHEN** the slug is updated to `paspoort-verlengen`
- **THEN** the alias URN MUST change to `urn:gemeente-utrecht:openregister:pdc:producten:paspoort-verlengen`
- **AND** the canonical UUID-based URN MUST remain unchanged
- **AND** the old alias URN SHOULD return a 301 redirect to the new alias for a configurable grace period

### Requirement: URN versioning MUST support version-specific addressing

For objects that use content versioning, URNs MUST support an optional version qualifier appended as a query component (per RFC 8141 q-component). The base URN (without version) MUST always resolve to the latest version. Version-specific URNs MUST resolve to the exact version requested.

#### Scenario: Resolve version-specific URN
- **GIVEN** an object with 3 versions (1.0, 2.0, 3.0) and base URN `urn:gemeente-utrecht:openregister:pdc:producten:abc-123`
- **WHEN** `GET /api/urn/resolve?urn=urn:gemeente-utrecht:openregister:pdc:producten:abc-123?=version:2.0` is called (using RFC 8141 q-component syntax)
- **THEN** the response MUST resolve to version 2.0 of the object
- **AND** the URL MUST include the version parameter: `.../abc-123?_version=2.0`

#### Scenario: Base URN resolves to latest version
- **GIVEN** the same object with 3 versions
- **WHEN** the base URN `urn:gemeente-utrecht:openregister:pdc:producten:abc-123` is resolved (without version qualifier)
- **THEN** the response MUST resolve to version 3.0 (latest)

#### Scenario: Resolve non-existent version
- **GIVEN** an object with versions 1.0 and 2.0
- **WHEN** version `5.0` is requested via URN version qualifier
- **THEN** the response MUST return HTTP 404 with: `"error": "Version 5.0 not found for this object"`
- **AND** the response MUST include available versions: `"availableVersions": ["1.0", "2.0"]`

### Requirement: URN capabilities MUST be discoverable via Nextcloud capabilities API

The URN resolution endpoint availability, configured URN namespace, and supported features MUST be exposed via `ICapability` in Nextcloud's capabilities API (`/ocs/v2.php/cloud/capabilities`). This enables clients and federated instances to discover URN support programmatically.

#### Scenario: Capabilities response includes URN configuration
- **WHEN** `GET /ocs/v2.php/cloud/capabilities` is called
- **THEN** the response MUST include:
  ```json
  {
    "openregister": {
      "urn": {
        "supported": true,
        "resolveEndpoint": "/index.php/apps/openregister/api/urn/resolve",
        "reverseEndpoint": "/index.php/apps/openregister/api/urn/reverse",
        "bulkSupported": true,
        "federationSupported": true,
        "maxBulkUrns": 100,
        "version": "1.0"
      }
    }
  }
  ```

#### Scenario: Federated instance discovers URN support
- **GIVEN** `gemeente-amersfoort.nl` wants to check if `gemeente-utrecht.nl` supports URN resolution
- **WHEN** the capabilities endpoint is queried
- **THEN** the presence of `openregister.urn.supported: true` confirms URN resolution is available
- **AND** the `resolveEndpoint` path can be used to construct the full resolution URL

#### Scenario: Capabilities reflect disabled features
- **GIVEN** URN federation is disabled in the admin configuration
- **WHEN** capabilities are queried
- **THEN** `federationSupported` MUST be `false`
- **AND** the `federated=true` query parameter on the resolve endpoint MUST return HTTP 501 Not Implemented

## Current Implementation Status

**Not implemented.** No URN support exists in the codebase:

- No `urn` field on `ObjectEntity` (objects have `uuid`, `slug`, and `uri` fields but no `urn`)
- No URN generation logic or `UrnService`
- No URN resolution endpoint (`/api/urn/resolve`) or reverse endpoint (`/api/urn/reverse`)
- No URN mapping table or `UrnMapping` entity
- No URN property type in schema definitions
- No organisation-level URN configuration on registers (registers have `organisation` and `slug` fields that can serve as URN segments)
- No URN in `@self` metadata block (currently contains: `id`, `slug`, `name`, `description`, `uri`, `version`, `register`, `schema`, `source`)
- No URN in CloudEvent webhook payloads (current `CloudEventFormatter` includes object data but not URN)
- No URN-based search or lookup capability
- The only URN-like patterns in the codebase are unrelated (`urn:ietf:params:...` in JWT authentication)

**Existing infrastructure that supports implementation:**
- `ObjectEntity.uuid` — UUID generation already exists; URN would wrap the UUID with namespace segments
- `ObjectEntity.uri` — existing field that could hold the URN (or a new dedicated `urn` field)
- `ObjectEntity.slug` — existing slug field can serve as human-readable alias segment
- `Register.organisation` and `Register.slug` — existing fields that provide the organisation and component URN segments
- `Schema.slug` — existing field that provides the resource type URN segment
- `@self` metadata block — existing metadata structure in `ObjectEntity::getObjectArray()` at line 649
- `CloudEventFormatter` — existing webhook payload formatter that can be extended with URN
- `DeepLinkRegistryService` — existing URL resolution that can be combined with URN resolution
- `IURLGenerator` — Nextcloud URL generator for constructing the URL portion of URN-URL mappings
- `MagicMapper` — indexed lookup infrastructure for efficient URN queries

## Standards & References

- **RFC 8141** — Uniform Resource Names (URNs): Defines URN syntax (`urn:<NID>:<NSS>`), q-component for version qualifiers, r-component for resolution parameters. The OpenRegister URN uses the organisation as NID and `{system}:{component}:{resource}:{uuid}` as NSS.
- **RFC 3986** — Uniform Resource Identifier (URI) Generic Syntax: URNs are a subset of URIs. The reverse resolution (URL to URN) maps between the URI schemes.
- **RFC 2141** — URN Syntax (superseded by RFC 8141): Historical reference; RFC 8141 is the current standard.
- **NEN 3610** — Dutch geographic information standard: Uses URN-based identifiers for geo-objects (`NL.IMBAG.Pand.0599100000610021`). OpenRegister URN pattern is inspired by but not identical to NEN 3610 identifiers.
- **NL GOV API Design Rules (API-49)** — Stable identifiers for government resources: Recommends persistent URIs for government API resources. URNs provide the stability layer that API-49 requires.
- **VNG Common Ground** — Recommends URN-based resource identification for interoperability across municipal systems.
- **CloudEvents 1.0 Specification** — Event format used by OpenRegister webhooks. URNs SHOULD be included as the `subject` field of CloudEvents for cross-system event correlation.
- **OIN (Organisatie Identificatie Nummer)** — Dutch government organisation identifier (20-digit number). Used in PKIoverheid certificates and Digikoppeling.
- **RSIN (Rechtspersonen Samenwerkingsverbanden Informatienummer)** — Dutch legal entity identifier from the Handelsregister.
- **KVK (Kamer van Koophandel)** — Dutch Chamber of Commerce registration number (8-digit).
- **PURL (Persistent URL)** — Alternative approach to stable resource addressing; URNs provide stronger decoupling from transport protocol.

## Specificity Assessment

- **Specific enough to implement?** Yes — the URN pattern, segment sources, resolution API, and integration points are clearly defined.
- **Addressed in this enrichment:**
  - URN format: `urn:{register.organisation}:{system}:{register.slug}:{schema.slug}:{object.uuid}` with RFC 8141 character validation
  - URN storage: dedicated `urn` field on `ObjectEntity` (or computed from existing fields)
  - URN uniqueness: enforced at database level (unique index on `urn` column)
  - URN configuration: register-level metadata (organisation, system, custom component)
  - Mapping table schema: `UrnMapping` entity with `urn` (unique, indexed), `url`, `label`, `sourceSystem`, `metadata`, timestamps
  - Bulk resolution: `POST /api/urn/resolve` with max 100 URNs per request
  - Performance: indexed `urn` column, cached federated lookups, streamed exports
  - CloudEvent/webhook integration: URN in event `data` and `subject` fields
  - NL government identifiers: OIN, RSIN, KVK mapping to organisation segment
  - Versioning: RFC 8141 q-component for version-specific URN resolution
- **Open questions resolved:**
  - URN is stored as a dedicated column (not computed on-the-fly) for indexing and query performance
  - Federated resolution uses existing sync source configuration for peer discovery
  - URN pattern aligns with RFC 8141 using organisation slug as informal NID

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No URN generation, resolution endpoints, mapping tables, or URN property types exist. Objects have `uuid`, `slug`, and `uri` fields but no `urn` field.

**Nextcloud Core Interfaces**:
- `IURLGenerator` (`OCP\IURLGenerator`): Use `linkToRouteAbsolute()` to generate the URL portion of URN-URL mappings. Ensures correct URLs regardless of reverse proxy, subdirectory installation, or domain changes.
- `ICapability` (`OCP\Capabilities\ICapability`): Expose URN support status, resolution endpoint paths, federation support, and configured namespace via `/ocs/v2.php/cloud/capabilities`.
- `IAppConfig` (`OCP\IAppConfig`): Store URN configuration (default organisation, default system name) as app-level config. Register-level URN overrides stored as register entity properties.
- `routes.php`: Register dedicated URN endpoints: `GET /api/urn/resolve`, `GET /api/urn/reverse`, `POST /api/urn/resolve` (bulk), `GET/POST/DELETE /api/urn/mappings`, `GET /api/urn/export`, `GET /api/urn/organisations`.
- `QueuedJob` (`OCP\BackgroundJob\QueuedJob`): Process bulk URN mapping imports asynchronously to avoid HTTP timeout.
- `ICacheFactory` (`OCP\ICacheFactory`): Cache federated URN resolution results with configurable TTL.

**Implementation Approach**:
1. **`UrnService`** — Core service with methods: `generateUrn(ObjectEntity, Register, Schema): string`, `resolveUrn(string): ?array`, `reverseResolve(string): ?string`, `bulkResolve(array): array`, `validateUrn(string): bool`. Parses URN segments to identify register, schema, and UUID. Uses `ObjectService` for existence verification.
2. **`ObjectEntity` extension** — Add `urn` field (string, nullable, indexed unique). Set in `ObjectService::saveObject()` at creation time by calling `UrnService::generateUrn()`. Include in `getObjectArray()` alongside existing `@self` fields.
3. **`UrnMapping` entity** — New Nextcloud Entity with Mapper for external URN-URL pairs. Table `oc_openregister_urn_mappings` with columns: `id`, `urn` (varchar 512, unique index), `url` (text), `label` (varchar 255), `source_system` (varchar 128), `metadata` (json), `created_at`, `updated_at`.
4. **`UrnController`** — Handles resolve, reverse, bulk resolve, mapping CRUD, export, and organisation lookup endpoints. Validates URN syntax against RFC 8141 before processing.
5. **`CloudEventFormatter` extension** — Add `urn` to event `data` payload and set CloudEvent `subject` to the object URN.
6. **Schema property type** — Add `urn` to the property type system. Validation checks RFC 8141 syntax. UI resolves URN references via `UrnService` for display.
7. **Register entity extension** — Add `urnOrganisation`, `urnSystem`, `urnComponent` fields (or store in existing metadata JSON). Provide defaults from `organisation` and `slug` fields.

**Dependencies on Existing OpenRegister Features**:
- `ObjectEntity` (`lib/Db/ObjectEntity.php`) — object model where URN is generated and stored; `@self` metadata block at `getObjectArray()`.
- `ObjectService` — object retrieval for URN resolution verification and save-time URN generation.
- `Register` entity (`lib/Db/Register.php`) — `organisation` and `slug` fields provide URN segments.
- `Schema` entity (`lib/Db/Schema.php`) — `slug` field provides the resource type URN segment.
- `CloudEventFormatter` (`lib/Service/Webhook/CloudEventFormatter.php`) — webhook payload formatter to extend with URN.
- `DeepLinkRegistryService` (`lib/Service/DeepLinkRegistryService.php`) — URL resolution for search results; URN provides the stable identifier while deep links provide the display URL.
- `MagicMapper` — indexed lookup for efficient URN queries via the search infrastructure.
- Schema property type system — extension point for the `urn` property type validation.
- `Source` entity and sync configuration — federation peer discovery for cross-instance URN resolution.
