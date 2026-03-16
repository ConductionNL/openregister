# ZGW API Mapping

## Purpose
Exposes Dutch ZGW (Zaakgericht Werken) compliant API endpoints from OpenRegister, serving data stored in English-language schemas through bidirectional property and value mapping. The mapping engine (currently in OpenConnector) is moved into OpenRegister as a core capability. Mapping configuration for ZGW is stored in Procest.

## Context
Procest stores case management data in OpenRegister using English property names (e.g., `case`, `status`, `deadline`). Dutch municipalities require ZGW-compliant APIs with Dutch property names and values (e.g., `zaak`, `status`, `uiterlijkeEinddatumAfdoening`). Rather than maintaining dual schemas, we use a mapping engine to translate on-the-fly.

The mapping engine (Twig-based property mapping, value casting, dot-notation) currently lives in OpenConnector. This spec moves it into OpenRegister as a core capability, since mapping is fundamental to how OpenRegister serves data through different API profiles. OpenConnector can then depend on OpenRegister's mapping engine rather than owning it.

The ZGW standard defines 5 APIs:
- **Zaken API** (Cases)
- **Catalogi API** (Case type catalog)
- **Besluiten API** (Decisions)
- **Documenten API** (Documents)
- **Autorisaties API** (Authorization)

All API endpoints are served by OpenRegister. Procest only stores the mapping configuration and ZGW-specific metadata.

## Requirements

### Requirement: Mapping Engine in OpenRegister
The Twig-based mapping engine (property mapping, value casting, dot-notation, unset, passThrough) MUST be moved from OpenConnector into OpenRegister as a core service.

#### Scenario: Mapping engine as OpenRegister service
- GIVEN the mapping engine currently lives in OpenConnector as `MappingService`
- WHEN it is moved to OpenRegister
- THEN OpenRegister provides `MappingService` with the same capabilities (Twig templates, dot-notation, casting, unset, passThrough)
- AND OpenRegister stores `Mapping` entities in its own database
- AND OpenConnector can depend on OpenRegister's mapping engine (removing its own copy)

#### Scenario: Mapping entity
- GIVEN the Mapping entity in OpenConnector has: name, mapping, unset, cast, passThrough
- WHEN moved to OpenRegister
- THEN the same schema is preserved
- AND mappings can be referenced by UUID or slug
- AND import/export of mappings is supported

#### Scenario: Twig runtime functions
- GIVEN the MappingRuntime in OpenConnector provides: `executeMapping()`, `generateUuid()`, `callSource()`, `getFiles()`
- WHEN moved to OpenRegister
- THEN the same Twig functions are available
- AND additional functions can be added (e.g., `zgw_enum()` for value mapping)

### Requirement: ZGW API Routes in OpenRegister
OpenRegister MUST expose ZGW-compliant API routes.

#### Scenario: List zaken (cases)
- GIVEN ZGW mapping is configured for the "procest" register
- WHEN a client calls `GET /index.php/apps/openregister/api/zgw/zaken/v1/zaken/`
- THEN OpenRegister queries the "case" schema in the "procest" register
- AND applies the outbound mapping (English -> Dutch)
- AND returns ZGW-compliant JSON with Dutch property names

#### Scenario: Create zaak (case)
- GIVEN ZGW mapping is configured
- WHEN a client POSTs to `/index.php/apps/openregister/api/zgw/zaken/v1/zaken/`
- THEN OpenRegister applies the inbound mapping (Dutch -> English)
- AND creates the object in the "case" schema
- AND returns the created object with outbound mapping applied

#### Scenario: ZGW URL pattern
- GIVEN the ZGW standard defines paths like `/zaken/v1/zaken/{uuid}`
- WHEN OpenRegister registers routes
- THEN routes MUST follow: `/api/zgw/{zgwApi}/v1/{resource}/{uuid?}`
- AND support standard ZGW query parameters (`status`, `zaaktype`, `bronorganisatie`, etc.)

### Requirement: Mapping Configuration in Procest
Procest MUST store the ZGW mapping definitions that OpenRegister uses.

#### Schema: ZgwMapping (stored as Procest configuration)
```json
{
  "type": "object",
  "required": ["zgwResource", "sourceSchema", "sourceRegister", "propertyMapping"],
  "properties": {
    "zgwResource": {
      "type": "string",
      "enum": ["zaak", "zaaktype", "status", "statustype", "resultaat", "resultaattype", "rol", "roltype", "eigenschap", "besluit", "besluittype", "informatieobjecttype"],
      "description": "ZGW resource type this mapping serves"
    },
    "zgwApiVersion": {
      "type": "string",
      "default": "v1",
      "description": "ZGW API version"
    },
    "sourceRegister": {
      "type": "string",
      "description": "OpenRegister register slug containing the source data"
    },
    "sourceSchema": {
      "type": "string",
      "description": "OpenRegister schema slug (English, e.g., 'case')"
    },
    "propertyMapping": {
      "type": "object",
      "description": "OpenRegister mapping definition (To -> From with Twig)"
    },
    "reverseMapping": {
      "type": "object",
      "description": "Reverse mapping for inbound requests (Dutch -> English)"
    },
    "valueMapping": {
      "type": "object",
      "description": "Enum/value translations (e.g., confidentiality levels)"
    },
    "queryParameterMapping": {
      "type": "object",
      "description": "Maps ZGW query parameter names to OpenRegister filter names"
    },
    "enabled": {
      "type": "boolean",
      "default": true
    }
  }
}
```

### Requirement: Property Mapping
Property mapping MUST use OpenRegister's Twig-based mapping engine.

#### Scenario: Outbound mapping (English -> Dutch)
- GIVEN a case object in OpenRegister:
```json
{
  "uuid": "abc-123",
  "caseType": "uuid-of-casetype",
  "status": "uuid-of-status",
  "deadline": "2026-06-01",
  "confidentiality": "public",
  "description": "Building permit request"
}
```
- WHEN the outbound mapping is applied:
```json
{
  "mapping": {
    "url": "{{ _baseUrl }}/zaken/v1/zaken/{{ uuid }}",
    "uuid": "uuid",
    "zaaktype": "{{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }}",
    "status": "{{ _baseUrl }}/zaken/v1/statussen/{{ status }}",
    "uiterlijkeEinddatumAfdoening": "deadline",
    "vertrouwelijkheidaanduiding": "{{ confidentiality | zgw_enum('confidentiality') }}",
    "omschrijving": "description",
    "startdatum": "{{ dateCreated | date('Y-m-d') }}",
    "registratiedatum": "{{ dateCreated | date('Y-m-d') }}"
  }
}
```
- THEN the response contains:
```json
{
  "url": "https://example.com/api/zgw/zaken/v1/zaken/abc-123",
  "uuid": "abc-123",
  "zaaktype": "https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-of-casetype",
  "status": "https://example.com/api/zgw/zaken/v1/statussen/uuid-of-status",
  "uiterlijkeEinddatumAfdoening": "2026-06-01",
  "vertrouwelijkheidaanduiding": "openbaar",
  "omschrijving": "Building permit request",
  "startdatum": "2026-03-06",
  "registratiedatum": "2026-03-06"
}
```

#### Scenario: Inbound mapping (Dutch -> English)
- GIVEN a ZGW-compliant POST body:
```json
{
  "zaaktype": "https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-of-casetype",
  "omschrijving": "New building permit",
  "vertrouwelijkheidaanduiding": "openbaar"
}
```
- WHEN the reverse mapping is applied
- THEN the object created in OpenRegister has English properties:
```json
{
  "caseType": "uuid-of-casetype",
  "description": "New building permit",
  "confidentiality": "public"
}
```

### Requirement: Value Mapping
Enum values MUST be translatable between English and Dutch.

#### Scenario: Confidentiality level mapping
- GIVEN a value mapping for confidentiality:
```json
{
  "confidentiality": {
    "public": "openbaar",
    "restricted": "beperkt_openbaar",
    "internal": "intern",
    "case_sensitive": "zaakvertrouwelijk",
    "confidential": "vertrouwelijk",
    "highly_confidential": "confidentieel",
    "secret": "geheim",
    "top_secret": "zeer_geheim"
  }
}
```
- WHEN an English value `"public"` is mapped outbound
- THEN it becomes `"openbaar"`
- AND when `"openbaar"` is mapped inbound, it becomes `"public"`

#### Scenario: Custom Twig filter for value mapping
- GIVEN value mappings are registered
- WHEN a mapping template uses `{{ confidentiality | zgw_enum('confidentiality') }}`
- THEN the Twig filter looks up the value in the value mapping table
- AND returns the translated value

### Requirement: ZGW URL References
ZGW requires that related resources are referenced by full URLs, not UUIDs.

#### Scenario: Zaaktype reference in zaak
- GIVEN a case object with `caseType: "uuid-123"`
- WHEN mapped to ZGW format
- THEN `zaaktype` becomes a full URL: `https://{host}/api/zgw/catalogi/v1/zaaktypen/uuid-123`

#### Scenario: Resolve URL reference on inbound
- GIVEN a POST with `zaaktype: "https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-123"`
- WHEN mapped inbound
- THEN the URL is parsed and only the UUID `uuid-123` is stored as `caseType`

### Requirement: ZGW Pagination
ZGW APIs use HAL-style pagination that differs from OpenRegister's default.

#### Scenario: Paginated zaak list
- GIVEN 50 cases in the register
- WHEN `GET /api/zgw/zaken/v1/zaken/?page=2` is called
- THEN the response MUST follow ZGW pagination format:
```json
{
  "count": 50,
  "next": "https://example.com/api/zgw/zaken/v1/zaken/?page=3",
  "previous": "https://example.com/api/zgw/zaken/v1/zaken/?page=1",
  "results": [ "..." ]
}
```

### Requirement: ZGW Query Parameter Mapping
ZGW filter parameters MUST be mapped to OpenRegister query parameters.

#### Scenario: Filter zaken by zaaktype
- GIVEN a ZGW client calls `GET /api/zgw/zaken/v1/zaken/?zaaktype=https://example.com/.../uuid-123`
- WHEN the query parameter mapping resolves `zaaktype` -> `caseType`
- THEN OpenRegister filters by `caseType=uuid-123` (UUID extracted from URL)

#### Scenario: Filter by date range
- GIVEN a ZGW client calls `GET /api/zgw/zaken/v1/zaken/?startdatum__gte=2026-01-01`
- WHEN the query parameter mapping resolves `startdatum` -> `dateCreated`
- THEN OpenRegister filters by `dateCreated >= 2026-01-01`

### Requirement: ZGW Resource Mapping Table
The following ZGW resources MUST be mappable to Procest/OpenRegister schemas.

| ZGW Resource | ZGW API | Procest Schema | OpenRegister Schema |
|-------------|---------|---------------|-------------------|
| Zaak | Zaken | case | case |
| ZaakType | Catalogi | caseType | caseType |
| Status | Zaken | (inline on case) | status on case |
| StatusType | Catalogi | statusType | statusType |
| Resultaat | Zaken | result | result |
| ResultaatType | Catalogi | resultType | resultType |
| Rol | Zaken | role | role |
| RolType | Catalogi | roleType | roleType |
| Eigenschap | Catalogi | propertyDefinition | propertyDefinition |
| Besluit | Besluiten | decision | decision |
| BesluitType | Catalogi | decisionType | decisionType |
| InformatieObjectType | Catalogi | documentType | documentType |

### Requirement: Mapping Administration
Procest MUST provide an admin interface for managing ZGW mappings.

#### Scenario: Admin configures zaak mapping
- GIVEN an admin navigates to Procest settings
- WHEN they open the "ZGW API Mapping" tab
- THEN they can configure which register/schema maps to each ZGW resource
- AND they can edit property mappings (with Twig template support)
- AND they can define value mappings for enum fields

### Requirement: Default Mappings
Procest MUST ship with default mappings for all ZGW resources based on its standard schemas.

#### Scenario: Fresh install
- GIVEN Procest is installed and its schemas are initialized
- WHEN the default mappings are loaded
- THEN all 12 ZGW resources have working default mappings
- AND the ZGW API endpoints are immediately functional
- AND an admin can customize mappings if their schema differs

### Requirement: Generic Mapping Capability
The ZGW mapping layer MUST be a generic capability in OpenRegister, not ZGW-specific.

#### Scenario: Non-ZGW API mapping
- GIVEN the mapping infrastructure built for ZGW
- WHEN another project needs to expose a different API standard on top of English data
- THEN the same mapping engine, route registration, and configuration patterns are reusable
- AND ZGW is just one "API profile" using this generic capability

## Non-Requirements
- Full ZGW compliance certification (this is a compatibility layer, not a reference implementation)
- Autorisaties API (authorization/scopes) -- use Nextcloud's auth system
- Notificaties API (ZGW notifications) -- use OpenRegister's CloudEvents system instead
- ZGW-to-ZGW synchronization with external OpenZaak instances (separate concern)

## Dependencies
- OpenRegister mapping engine (moved from OpenConnector, Twig-based property/value mapping)
- OpenRegister API system (existing, extended with ZGW routes)
- Procest schemas (existing 12 ZGW-mapped schemas)
- Procest admin settings UI (existing, extended with mapping tab)

### Current Implementation Status

**Partially implemented.** The mapping engine is in OpenRegister, but ZGW-specific routes are not:

**Implemented (mapping engine in OpenRegister):**
- `lib/Service/MappingService.php` -- Twig-based mapping engine with `executeMapping()`, dot-notation, casting, passThrough, unset
- `lib/Twig/MappingExtension.php` -- Twig extension for mapping-specific functions
- `lib/Twig/MappingRuntime.php` -- Runtime functions available in Twig templates (e.g., `generateUuid()`, `callSource()`, `getFiles()`)
- `lib/Twig/MappingRuntimeLoader.php` -- Lazy loader for mapping runtime
- `lib/Db/MappingMapper.php` -- Mapper for Mapping entities stored in OpenRegister database
- `lib/Controller/MappingsController.php` -- CRUD API for Mapping entities

**Not implemented:**
- ZGW API routes in OpenRegister (`/api/zgw/{zgwApi}/v1/{resource}/{uuid?}`)
- ZGW-specific Twig filter (`zgw_enum()` for value mapping)
- ZGW pagination format (HAL-style `count`, `next`, `previous`, `results`)
- ZGW query parameter mapping (e.g., `zaaktype` URL -> `caseType` UUID extraction)
- ZGW URL references (auto-generating full URLs for related resources)
- Inbound mapping (Dutch -> English) for ZGW POST/PUT requests
- Default ZGW mappings shipped with Procest
- ZGW mapping administration UI in Procest
- Route registration for all 5 ZGW APIs (Zaken, Catalogi, Besluiten, Documenten, Autorisaties)

### Standards & References
- VNG ZGW API Standards (https://vng-realisatie.github.io/gemma-zaken/)
  - Zaken API v1.5.1 (https://zaken-api.vng.cloud/api/v1/schema/)
  - Catalogi API v1.3.1 (https://catalogi-api.vng.cloud/api/v1/schema/)
  - Besluiten API v1.1.0 (https://besluiten-api.vng.cloud/api/v1/schema/)
  - Documenten API v1.4.3 (https://documenten-api.vng.cloud/api/v1/schema/)
- GEMMA 2.0 reference architecture (VNG)
- NL GOV API Design Rules (https://publicatie.centrumvoorstandaarden.nl/api/adr/)
- HAL (Hypertext Application Language) -- JSON pagination format used by ZGW
- Twig Template Engine (https://twig.symfony.com/)

### Specificity Assessment
- **Specific enough to implement?** Yes -- the mapping table, route patterns, and property mapping examples are concrete and actionable.
- **Missing/ambiguous:**
  - No specification for ZGW version negotiation (what if client requests v2 but only v1 is mapped?)
  - No specification for ZGW audit trail format (audittrail resource in Zaken API)
  - No specification for ZGW expand/include query parameters
  - No specification for ZGW validation errors (must follow ZGW error response format)
  - No specification for authentication on ZGW endpoints (JWT tokens per ZGW standard?)
- **Open questions:**
  - Should ZGW endpoints require ZGW-standard JWT authentication or use Nextcloud's auth?
  - How should the Autorisaties API be handled (spec says out of scope but clients may expect it)?
  - Should ZGW compliance be validated against VNG API test platform?
  - How does this interact with the existing OpenConnector mapping engine (migration path)?
