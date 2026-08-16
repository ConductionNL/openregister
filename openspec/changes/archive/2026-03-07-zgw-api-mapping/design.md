# Design: zgw-api-mapping

## Architecture Overview

The ZGW API mapping system spans three projects. OpenRegister owns the generic mapping engine (MappingService, Mapping entity, MappingRuntime). Procest owns all ZGW-specific code: the ZgwController, ZgwPaginationHelper, mapping configuration, default mappings, and admin UI. OpenConnector removes its mapping engine and depends on OpenRegister's.

**Key principle**: OpenRegister is a generic abstraction layer. ZGW-specific logic belongs in Procest, which consumes OpenRegister's services via cross-app DI loading.

```
                        ZGW Client
                            |
                  GET /apps/procest/api/zgw/zaken/v1/zaken/
                            |
                            v
┌────────────────────────────────────────────────────────┐
│                      Procest                           │
│                                                        │
│  ┌──────────────────┐    ┌──────────────────────┐     │
│  │ ZgwController     │───>│ ZgwMappingService    │     │
│  │                   │    │ (mapping config)     │     │
│  │ Route dispatch    │    │ IAppConfig storage   │     │
│  │ Pagination        │    └──────────────────────┘     │
│  │ Query mapping     │                                 │
│  └───────┬───────────┘    ┌──────────────────────┐     │
│          │                │ ZgwPaginationHelper   │     │
│          │                │ HAL-style pagination  │     │
│          │                └──────────────────────┘     │
│          │                                             │
│          │  cross-app DI: \OC::$server->get()          │
│          v                                             │
│  ┌──────────────────────────────────────────────┐     │
│  │ Admin Settings: ZGW Mapping Tab               │     │
│  │ - List/edit all 12 resource mappings          │     │
│  │ - Property mapping editor (Twig)              │     │
│  │ - Value mapping editor                        │     │
│  └──────────────────────────────────────────────┘     │
│                                                        │
│  ┌──────────────────────────────────────────────┐     │
│  │ Default Mappings (repair step / migration)    │     │
│  │ - Pre-configured for all 12 ZGW resources     │     │
│  └──────────────────────────────────────────────┘     │
└────────────────────────────────────────────────────────┘
          │ loads services at runtime
          v
┌───────────────────────────────────────────────────────┐
│                    OpenRegister                        │
│                                                        │
│  ┌──────────────┐    ┌──────────────────────┐         │
│  │ ObjectService │    │    MappingService     │        │
│  │ (existing)    │    │  (moved from OC)      │        │
│  │ CRUD on       │    │  - executeMapping()   │        │
│  │ English data  │    │  - Twig templates     │        │
│  └───────────────┘    │  - dot-notation       │        │
│                       │  - cast / unset       │        │
│                       │  - passThrough        │        │
│                       └──────────┬────────────┘        │
│                                  │                     │
│                                  v                     │
│                       ┌──────────────────────┐         │
│                       │   MappingRuntime      │        │
│                       │  (Twig extensions)    │        │
│                       │  - zgw_enum filter    │        │
│                       │  - generateUuid()     │        │
│                       │  - callSource()       │        │
│                       └──────────────────────┘         │
│                                                        │
│  ┌──────────────────────────────────────────────┐     │
│  │ Mapping entity + MappingMapper (DB table)     │     │
│  │ oc_openregister_mappings                      │     │
│  │ - id, uuid, name, slug, mapping (JSON),       │     │
│  │   unset (JSON), cast (JSON), passThrough      │     │
│  └───────────────────────────────────────────────┘     │
└───────────────────────────────────────────────────────┘
```

## Moving the Mapping Engine from OpenConnector to OpenRegister

### Classes to Move

| OpenConnector Source | OpenRegister Destination |
|---------------------|-------------------------|
| `OCA\OpenConnector\Db\Mapping` | `OCA\OpenRegister\Db\Mapping` |
| `OCA\OpenConnector\Db\MappingMapper` | `OCA\OpenRegister\Db\MappingMapper` |
| `OCA\OpenConnector\Service\MappingService` | `OCA\OpenRegister\Service\MappingService` |
| `OCA\OpenConnector\Twig\MappingRuntime` | `OCA\OpenRegister\Twig\MappingRuntime` |

### Mapping Entity Schema

```php
/**
 * @method string getName()
 * @method array getMapping()      // JSON: { "targetProp": "sourceProp or {{ twig }}" }
 * @method array getUnset()        // JSON: array of properties to remove after mapping
 * @method array getCast()         // JSON: { "property": "type" } for type casting
 * @method bool getPassThrough()   // Whether unmapped properties pass through
 */
class Mapping extends Entity {
    protected ?string $uuid = null;
    protected ?string $name = null;
    protected ?string $slug = null;
    protected ?array $mapping = [];
    protected ?array $unset = [];
    protected ?array $cast = [];
    protected ?bool $passThrough = false;
}
```

### Database Migration

A new migration in OpenRegister creates the `oc_openregister_mappings` table:

| Column | Type | Description |
|--------|------|-------------|
| `id` | int | Auto-increment primary key |
| `uuid` | string(36) | UUID identifier |
| `name` | string(255) | Human-readable mapping name |
| `slug` | string(255) | URL-safe identifier |
| `mapping` | text (JSON) | Property mapping definitions |
| `unset` | text (JSON) | Properties to remove post-mapping |
| `cast` | text (JSON) | Type casting definitions |
| `pass_through` | boolean | Whether unmapped props pass through |
| `created` | datetime | Creation timestamp |
| `updated` | datetime | Last update timestamp |

## ZGW Controller Design

### ZgwController (in Procest)

A single controller in Procest handles all ZGW API requests. It dynamically loads OpenRegister's ObjectService and MappingService via `\OC::$server->get()` at construction time, with graceful fallback if OpenRegister is unavailable (returns 503).

```php
class ZgwController extends ApiController {

    // Loaded via cross-app DI: \OC::$server->get('OCA\OpenRegister\Service\...')
    private ?object $openRegisterMappingService;
    private ?object $openRegisterObjectService;

    /**
     * Route: /apps/procest/api/zgw/{zgwApi}/v1/{resource}
     * Methods: GET, POST
     */
    public function index(string $zgwApi, string $resource): JSONResponse {
        // 1. Look up ZgwMapping config from ZgwMappingService
        // 2. Resolve sourceRegister and sourceSchema
        // 3. For GET: query ObjectService, apply outbound mapping, wrap in ZGW pagination
        // 4. For POST: apply inbound mapping, create via ObjectService, return outbound-mapped result
    }

    /**
     * Route: /apps/procest/api/zgw/{zgwApi}/v1/{resource}/{uuid}
     * Methods: GET, PUT, PATCH, DELETE
     */
    public function show(string $zgwApi, string $resource, string $uuid): JSONResponse {
        // 1. Look up ZgwMapping config
        // 2. For GET: fetch by UUID, apply outbound mapping
        // 3. For PUT/PATCH: apply inbound mapping, update, return outbound-mapped result
        // 4. For DELETE: delete object
    }
}
```

### Route Registration (procest/appinfo/routes.php)

```php
['name' => 'zgw#index',   'url' => '/api/zgw/{zgwApi}/v1/{resource}',        'verb' => 'GET'],
['name' => 'zgw#create',  'url' => '/api/zgw/{zgwApi}/v1/{resource}',        'verb' => 'POST'],
['name' => 'zgw#show',    'url' => '/api/zgw/{zgwApi}/v1/{resource}/{uuid}', 'verb' => 'GET'],
['name' => 'zgw#update',  'url' => '/api/zgw/{zgwApi}/v1/{resource}/{uuid}', 'verb' => 'PUT'],
['name' => 'zgw#patch',   'url' => '/api/zgw/{zgwApi}/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
['name' => 'zgw#destroy', 'url' => '/api/zgw/{zgwApi}/v1/{resource}/{uuid}', 'verb' => 'DELETE'],
```

### ZGW API to Resource Routing

The `zgwApi` path segment determines which API group is being accessed:

| zgwApi | ZGW API | Resources |
|--------|---------|-----------|
| `zaken` | Zaken API | `zaken`, `statussen`, `resultaten`, `rollen` |
| `catalogi` | Catalogi API | `zaaktypen`, `statustypen`, `resultaattypen`, `roltypen`, `eigenschappen`, `informatieobjecttypen` |
| `besluiten` | Besluiten API | `besluiten`, `besluittypen` |
| `documenten` | Documenten API | (future) |

## Bidirectional Mapping Flow

### Outbound (English to Dutch) -- API Response

```
OpenRegister Object (English)
    |
    v
MappingService::executeMapping($object, $outboundMapping)
    |
    | Twig renders each target property:
    |   "zaaktype" => "{{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }}"
    |   "omschrijving" => "description"  (simple property copy)
    |   "vertrouwelijkheidaanduiding" => "{{ confidentiality | zgw_enum('confidentiality') }}"
    |
    v
ZGW-compliant JSON (Dutch)
```

### Inbound (Dutch to English) -- API Request

```
ZGW POST Body (Dutch)
    |
    v
MappingService::executeMapping($body, $reverseMapping)
    |
    | Reverse Twig renders each target property:
    |   "caseType" => "{{ zaaktype | zgw_extract_uuid }}"
    |   "description" => "omschrijving"
    |   "confidentiality" => "{{ vertrouwelijkheidaanduiding | zgw_enum_reverse('confidentiality') }}"
    |
    v
OpenRegister Object (English)
```

### The `_baseUrl` Variable

Every outbound mapping has access to `_baseUrl`, which is computed as:

```
https://{host}/index.php/apps/procest/api/zgw
```

This is injected into the Twig context automatically by Procest's ZgwController.

## Value Mapping -- zgw_enum Twig Filter

### Implementation

```php
class MappingRuntime extends AbstractRuntime {

    /**
     * Translates an enum value using the value mapping table.
     * Usage in Twig: {{ value | zgw_enum('fieldName') }}
     */
    public function zgwEnum(string $value, string $fieldName, array $valueMappings): string {
        if (isset($valueMappings[$fieldName][$value])) {
            return $valueMappings[$fieldName][$value];
        }
        return $value; // Return unchanged if no mapping found
    }

    /**
     * Reverse enum lookup for inbound mapping.
     * Usage in Twig: {{ value | zgw_enum_reverse('fieldName') }}
     */
    public function zgwEnumReverse(string $value, string $fieldName, array $valueMappings): string {
        $flipped = array_flip($valueMappings[$fieldName] ?? []);
        return $flipped[$value] ?? $value;
    }

    /**
     * Extracts UUID from a ZGW URL reference.
     * Usage in Twig: {{ url | zgw_extract_uuid }}
     */
    public function zgwExtractUuid(string $url): string {
        $parts = explode('/', rtrim($url, '/'));
        return end($parts);
    }
}
```

### Value Mapping Configuration Example

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
  },
  "status_explanation": {
    "open": "open",
    "in_progress": "in_behandeling",
    "completed": "afgehandeld",
    "closed": "gesloten"
  }
}
```

## ZGW Pagination Wrapper

### ZgwPaginationHelper

Wraps OpenRegister's default pagination into ZGW's HAL-style format:

```php
class ZgwPaginationHelper {

    public function wrapResults(
        array $mappedObjects,
        int $totalCount,
        int $page,
        int $pageSize,
        string $baseUrl,
        array $queryParams
    ): array {
        $totalPages = (int) ceil($totalCount / $pageSize);
        $queryString = http_build_query(array_diff_key($queryParams, ['page' => 1]));

        return [
            'count'    => $totalCount,
            'next'     => $page < $totalPages
                ? $baseUrl . '?' . $queryString . '&page=' . ($page + 1)
                : null,
            'previous' => $page > 1
                ? $baseUrl . '?' . $queryString . '&page=' . ($page - 1)
                : null,
            'results'  => $mappedObjects,
        ];
    }
}
```

## ZGW Query Parameter Mapping

### How It Works

Each ZgwMapping configuration includes a `queryParameterMapping` that translates Dutch ZGW query parameters to English OpenRegister filter fields:

```json
{
  "zaaktype": { "field": "caseType", "extractUuid": true },
  "status": { "field": "status", "extractUuid": true },
  "bronorganisatie": { "field": "sourceOrganization" },
  "startdatum": { "field": "dateCreated" },
  "startdatum__gte": { "field": "dateCreated", "operator": ">=" },
  "startdatum__lte": { "field": "dateCreated", "operator": "<=" }
}
```

The `extractUuid` flag indicates that the query value is a full ZGW URL and the UUID should be extracted before filtering.

## Procest Configuration Storage

### ZgwMapping in IAppConfig

Procest stores ZGW mapping definitions as JSON in Nextcloud's `IAppConfig`:

```
Key:   zgw_mapping_{zgwResource}
Value: (JSON) { zgwResource, sourceRegister, sourceSchema, propertyMapping, reverseMapping, valueMapping, queryParameterMapping, enabled }
```

Example keys:
- `zgw_mapping_zaak`
- `zgw_mapping_zaaktype`
- `zgw_mapping_status`
- `zgw_mapping_statustype`
- etc. (12 total)

### How Procest Reads Mapping Config

Procest's ZgwController reads mapping config directly via its own ZgwMappingService:

```php
$mappingConfig = $this->zgwMappingService->getMapping($resourceKey);
```

ZgwMappingService wraps IAppConfig, reading keys like `zgw_mapping_zaak` from Procest's app config namespace. This keeps all ZGW-specific configuration within Procest.

## Procest Admin UI -- ZGW Mapping Tab

### Component: ZgwMappingSettings.vue

Added as a new tab in the existing Procest admin settings page:

- **Mapping List**: Table showing all 12 ZGW resources with columns for resource name, source schema, enabled status
- **Mapping Editor** (modal/sidebar): Edit property mapping (Twig template textarea), reverse mapping, value mapping (key-value editor), query parameter mapping
- **Reset to Defaults**: Button to restore default mappings for a resource

## File Structure

### OpenRegister -- New/Modified Files (Generic Mapping Engine Only)

```
openregister/lib/
├── Db/
│   ├── Mapping.php                    (NEW - moved from OC)
│   └── MappingMapper.php              (NEW - moved from OC)
├── Service/
│   └── MappingService.php             (NEW - moved from OC)
├── Twig/
│   └── MappingRuntime.php             (NEW - moved from OC, extended with zgw_enum filters)
└── Migration/
    └── VersionXXXXDate_CreateMappings.php (NEW)
```

### Procest -- New/Modified Files (ZGW-Specific Code)

```
procest/lib/
├── Controller/
│   └── ZgwController.php             (NEW - ZGW API controller, loads OR services via DI)
├── Service/
│   ├── ZgwMappingService.php          (NEW - manages mapping config in IAppConfig)
│   └── ZgwPaginationHelper.php        (NEW - HAL-style pagination wrapper)
├── Repair/
│   └── LoadDefaultZgwMappings.php     (NEW - repair step for defaults)
└── Migration/
    (none - uses IAppConfig, no DB changes)

procest/appinfo/
└── routes.php                         (MODIFIED - add 6 ZGW API routes)

procest/src/
└── views/
    └── settings/
        └── ZgwMappingSettings.vue     (NEW - admin UI tab)
```

### OpenConnector -- Modified Files

```
openconnector/lib/
├── Db/
│   ├── Mapping.php                    (REMOVED - use OR's)
│   └── MappingMapper.php              (REMOVED - use OR's)
├── Service/
│   └── MappingService.php             (MODIFIED - delegate to OR's MappingService)
└── Twig/
    └── MappingRuntime.php             (REMOVED - use OR's)
```

## Security Considerations

- **Authentication**: ZGW endpoints use Nextcloud session auth by default. API token auth can be added later for machine-to-machine access.
- **CSRF**: Standard Nextcloud `requesttoken` protection on all state-changing endpoints.
- **Input validation**: Inbound mapped data is validated against OpenRegister schema constraints before storage.
- **Mapping injection**: Twig templates in mapping definitions are admin-only configuration. The Twig sandbox restricts available functions to prevent code execution.

## Trade-offs

### Moving mapping to OpenRegister vs. keeping in OpenConnector
**Chosen: Move to OpenRegister**
- Pro: Mapping is a data-layer concern; OpenRegister is the data layer
- Pro: Any app can use mapping, not just OpenConnector-dependent apps
- Pro: Single source of truth for data transformation
- Con: Breaking change for OpenConnector's internal API
- Con: OpenRegister grows in scope
- Mitigation: OpenConnector wraps OR's MappingService during transition

### ZGW routes in Procest vs. in OpenRegister
**Chosen: Routes in Procest** (revised from initial design)
- Pro: OpenRegister stays a generic abstraction layer with no domain-specific code
- Pro: All ZGW-specific logic (controller, pagination, config, routes) lives together in Procest
- Pro: Clear separation of concerns: generic engine vs. domain-specific API
- Con: Procest must load OpenRegister services via cross-app DI (`\OC::$server->get()`)
- Mitigation: Cross-app DI pattern already used elsewhere in the codebase; graceful 503 if OpenRegister unavailable

### IAppConfig vs. OpenRegister objects for mapping storage
**Chosen: IAppConfig in Procest**
- Pro: Simple key-value storage, no circular dependency
- Pro: Mapping config is truly configuration, not user data
- Con: No versioning or audit trail
- Mitigation: Can migrate to OpenRegister objects later if needed
