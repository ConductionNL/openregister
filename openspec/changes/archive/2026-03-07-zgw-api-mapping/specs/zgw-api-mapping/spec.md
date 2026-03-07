# ZGW API Mapping -- Delta Spec

## Purpose
Delta spec for the `zgw-api-mapping` change. Defines all added requirements for moving the mapping engine into OpenRegister, exposing ZGW API routes, and configuring bidirectional property/value mapping through Procest.

---

## ADDED Requirements

### Requirement: Mapping Engine in OpenRegister

The Twig-based mapping engine (property mapping, value casting, dot-notation, unset, passThrough) MUST be moved from OpenConnector into OpenRegister as a core service.

#### Scenario: Mapping engine as OpenRegister service

- GIVEN the mapping engine currently lives in OpenConnector as `MappingService`
- WHEN it is moved to OpenRegister
- THEN OpenRegister provides `OCA\OpenRegister\Service\MappingService` with the same capabilities (Twig templates, dot-notation, casting, unset, passThrough)
- AND OpenRegister stores `Mapping` entities in its own database table `oc_openregister_mappings`
- AND OpenConnector can depend on OpenRegister's mapping engine (removing its own copy)

#### Scenario: Mapping entity preserved

- GIVEN the Mapping entity in OpenConnector has fields: `name`, `mapping`, `unset`, `cast`, `passThrough`
- WHEN moved to OpenRegister
- THEN the same schema is preserved in `OCA\OpenRegister\Db\Mapping`
- AND mappings can be referenced by UUID or slug
- AND import/export of mappings is supported via the existing OpenRegister object API

#### Scenario: Twig runtime functions available

- GIVEN the MappingRuntime in OpenConnector provides: `executeMapping()`, `generateUuid()`, `callSource()`, `getFiles()`
- WHEN moved to OpenRegister
- THEN the same Twig functions are available in `OCA\OpenRegister\Twig\MappingRuntime`
- AND additional functions can be registered (e.g., `zgw_enum()` for value mapping)

---

### Requirement: ZGW API Routes

OpenRegister MUST expose ZGW-compliant API routes that map to internal English-language schemas.

#### Scenario: List zaken (cases)

- GIVEN ZGW mapping is configured for the "procest" register
- WHEN a client calls `GET /index.php/apps/openregister/api/zgw/zaken/v1/zaken/`
- THEN OpenRegister queries the "case" schema in the "procest" register
- AND applies the outbound mapping (English to Dutch)
- AND returns ZGW-compliant JSON with Dutch property names

#### Scenario: Create zaak (case)

- GIVEN ZGW mapping is configured
- WHEN a client POSTs to `/index.php/apps/openregister/api/zgw/zaken/v1/zaken/`
- THEN OpenRegister applies the inbound mapping (Dutch to English)
- AND creates the object in the "case" schema
- AND returns the created object with outbound mapping applied

#### Scenario: Get single zaak

- GIVEN ZGW mapping is configured
- WHEN a client calls `GET /index.php/apps/openregister/api/zgw/zaken/v1/zaken/{uuid}`
- THEN OpenRegister fetches the object by UUID from the "case" schema
- AND applies the outbound mapping
- AND returns a single ZGW-compliant JSON object

#### Scenario: ZGW URL pattern

- GIVEN the ZGW standard defines paths like `/zaken/v1/zaken/{uuid}`
- WHEN OpenRegister registers routes
- THEN routes MUST follow: `/api/zgw/{zgwApi}/v1/{resource}/{uuid?}`
- AND support standard ZGW query parameters (`status`, `zaaktype`, `bronorganisatie`, etc.)

---

### Requirement: Mapping Configuration in Procest

Procest MUST store the ZGW mapping definitions that OpenRegister uses, as `ZgwMapping` configuration objects.

#### Scenario: ZgwMapping configuration schema

- GIVEN Procest stores ZGW mapping configuration
- WHEN a mapping is defined
- THEN it MUST include: `zgwResource` (enum of ZGW resource types), `zgwApiVersion` (default "v1"), `sourceRegister` (OpenRegister register slug), `sourceSchema` (OpenRegister schema slug), `propertyMapping` (outbound Twig mapping), `reverseMapping` (inbound mapping), `valueMapping` (enum translations), `queryParameterMapping` (query param name mapping), and `enabled` flag

#### Scenario: OpenRegister reads mapping config from Procest

- GIVEN Procest has stored a ZgwMapping for resource "zaak"
- WHEN OpenRegister receives a request to `/api/zgw/zaken/v1/zaken/`
- THEN it reads the mapping configuration from Procest's app config
- AND uses it to determine the source register, schema, and mapping definitions

---

### Requirement: Property Mapping (Twig-based)

Property mapping MUST use OpenRegister's Twig-based mapping engine for bidirectional translation.

#### Scenario: Outbound mapping (English to Dutch)

- GIVEN a case object in OpenRegister with fields `uuid`, `caseType`, `status`, `deadline`, `confidentiality`, `description`, `dateCreated`
- WHEN the outbound mapping is applied using Twig templates (e.g., `"zaaktype": "{{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }}"`)
- THEN the response contains Dutch property names with values constructed from the English source
- AND UUID references are expanded to full ZGW URLs
- AND date fields are formatted according to ZGW conventions

#### Scenario: Inbound mapping (Dutch to English)

- GIVEN a ZGW-compliant POST body with Dutch property names (e.g., `omschrijving`, `zaaktype`, `vertrouwelijkheidaanduiding`)
- WHEN the reverse mapping is applied
- THEN the object created in OpenRegister has English properties (e.g., `description`, `caseType`, `confidentiality`)
- AND URL references are parsed back to UUIDs
- AND enum values are translated back to English

---

### Requirement: Value Mapping

Enum values MUST be translatable between English and Dutch using a `zgw_enum` Twig filter.

#### Scenario: Outbound value translation

- GIVEN a value mapping for confidentiality: `{ "public": "openbaar", "restricted": "beperkt_openbaar", "internal": "intern", "case_sensitive": "zaakvertrouwelijk", "confidential": "vertrouwelijk", "highly_confidential": "confidentieel", "secret": "geheim", "top_secret": "zeer_geheim" }`
- WHEN an English value `"public"` is mapped outbound
- THEN it becomes `"openbaar"`

#### Scenario: Inbound value translation

- GIVEN the same value mapping
- WHEN a Dutch value `"openbaar"` is mapped inbound
- THEN it becomes `"public"`

#### Scenario: Custom Twig filter for value mapping

- GIVEN value mappings are registered in the ZgwMapping configuration
- WHEN a mapping template uses `{{ confidentiality | zgw_enum('confidentiality') }}`
- THEN the `zgw_enum` Twig filter looks up the value in the value mapping table
- AND returns the translated value
- AND if no mapping is found, the original value is returned unchanged

---

### Requirement: ZGW URL References

ZGW requires that related resources are referenced by full URLs, not UUIDs.

#### Scenario: UUID to URL on outbound

- GIVEN a case object with `caseType: "uuid-123"`
- WHEN mapped to ZGW format using template `"zaaktype": "{{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }}"`
- THEN `zaaktype` becomes a full URL: `https://{host}/api/zgw/catalogi/v1/zaaktypen/uuid-123`

#### Scenario: URL to UUID on inbound

- GIVEN a POST with `zaaktype: "https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-123"`
- WHEN mapped inbound
- THEN the URL is parsed and only the UUID `uuid-123` is stored as `caseType`

---

### Requirement: ZGW Pagination

ZGW APIs use HAL-style pagination that differs from OpenRegister's default.

#### Scenario: Paginated list response

- GIVEN 50 objects in the register
- WHEN `GET /api/zgw/zaken/v1/zaken/?page=2` is called with default page size of 20
- THEN the response MUST follow ZGW pagination format with `count` (total), `next` (URL or null), `previous` (URL or null), and `results` (array of mapped objects)

#### Scenario: First page has no previous

- GIVEN a paginated list
- WHEN `page=1` is requested
- THEN `previous` MUST be `null`
- AND `next` MUST be a valid URL if more pages exist

#### Scenario: Last page has no next

- GIVEN a paginated list
- WHEN the last page is requested
- THEN `next` MUST be `null`
- AND `previous` MUST be a valid URL

---

### Requirement: ZGW Query Parameter Mapping

ZGW filter parameters MUST be mapped to OpenRegister query parameters.

#### Scenario: Filter by ZGW resource reference

- GIVEN a ZGW client calls `GET /api/zgw/zaken/v1/zaken/?zaaktype=https://example.com/.../uuid-123`
- WHEN the query parameter mapping resolves `zaaktype` to `caseType`
- THEN OpenRegister filters by `caseType=uuid-123` (UUID extracted from URL)

#### Scenario: Filter by date range

- GIVEN a ZGW client calls `GET /api/zgw/zaken/v1/zaken/?startdatum__gte=2026-01-01`
- WHEN the query parameter mapping resolves `startdatum` to `dateCreated`
- THEN OpenRegister filters by `dateCreated >= 2026-01-01`

#### Scenario: Unmapped parameters ignored

- GIVEN a ZGW client includes a query parameter not in the mapping
- WHEN the request is processed
- THEN the unknown parameter is ignored without error

---

### Requirement: ZGW Resource Mapping Table

The following ZGW resources MUST be mappable to Procest/OpenRegister schemas.

#### Scenario: All 12 ZGW resources have mappings

- GIVEN the ZGW resource mapping table defines: Zaak (case), ZaakType (caseType), Status (status on case), StatusType (statusType), Resultaat (result), ResultaatType (resultType), Rol (role), RolType (roleType), Eigenschap (propertyDefinition), Besluit (decision), BesluitType (decisionType), InformatieObjectType (documentType)
- WHEN ZGW API endpoints are called for any of these resources
- THEN the correct OpenRegister schema is queried
- AND the correct mapping is applied

#### Scenario: Zaken API resources

- GIVEN the Zaken API serves Zaak, Status, Resultaat, and Rol
- WHEN requests hit `/api/zgw/zaken/v1/{resource}/`
- THEN the correct schema from the procest register is queried

#### Scenario: Catalogi API resources

- GIVEN the Catalogi API serves ZaakType, StatusType, ResultaatType, RolType, Eigenschap, and InformatieObjectType
- WHEN requests hit `/api/zgw/catalogi/v1/{resource}/`
- THEN the correct schema from the procest register is queried

---

### Requirement: Mapping Administration

Procest MUST provide an admin interface for managing ZGW mappings.

#### Scenario: Admin views mapping list

- GIVEN an admin navigates to Procest settings
- WHEN they open the "ZGW API Mapping" tab
- THEN they see a list of all 12 ZGW resource mappings with their enabled/disabled status

#### Scenario: Admin edits property mapping

- GIVEN an admin opens a ZGW resource mapping
- WHEN they edit the property mapping (Twig template)
- THEN the mapping is saved to Procest configuration
- AND subsequent ZGW API calls use the updated mapping

#### Scenario: Admin configures value mapping

- GIVEN an admin opens a ZGW resource mapping
- WHEN they define value mappings for enum fields
- THEN the `zgw_enum` filter uses the updated value mapping table

---

### Requirement: Default Mappings

Procest MUST ship with default mappings for all ZGW resources based on its standard schemas.

#### Scenario: Fresh install

- GIVEN Procest is installed and its schemas are initialized
- WHEN the default mappings are loaded (via repair step or first-run initialization)
- THEN all 12 ZGW resources have working default mappings
- AND the ZGW API endpoints are immediately functional without manual configuration

#### Scenario: Custom schema override

- GIVEN an organization has customized their Procest schemas
- WHEN they modify the default mappings via the admin UI
- THEN the customized mappings take effect
- AND default mappings can be reset if needed

---

### Requirement: Generic Mapping Capability

The mapping infrastructure MUST be generic in OpenRegister, with ZGW being one "API profile" using it.

#### Scenario: Non-ZGW API mapping

- GIVEN the mapping engine and route infrastructure built for ZGW
- WHEN another project needs to expose a different API standard on top of English data
- THEN the same MappingService, Mapping entity, and Twig runtime are reusable
- AND ZGW is just one API profile using this generic capability

#### Scenario: Multiple API profiles

- GIVEN OpenRegister supports the generic mapping capability
- WHEN multiple API profiles are configured (e.g., ZGW, STUF, custom)
- THEN each profile can have its own route prefix, mappings, and pagination format
- AND they all share the same underlying mapping engine
