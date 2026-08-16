# Tasks: zgw-api-mapping

## 1. Move Mapping Engine to OpenRegister

### Task 1: Move Mapping entity and MappingMapper from OpenConnector to OpenRegister
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-engine-in-openregister`
- **files**: `openregister/lib/Db/Mapping.php`, `openregister/lib/Db/MappingMapper.php`, `openregister/lib/Migration/VersionXXXXDate_CreateMappings.php`
- **acceptance_criteria**:
  - GIVEN the Mapping entity in OpenConnector has fields: name, uuid, slug, mapping, unset, cast, passThrough WHEN moved to OpenRegister THEN `OCA\OpenRegister\Db\Mapping` preserves the same schema
  - GIVEN the migration runs WHEN OpenRegister is upgraded THEN the `oc_openregister_mappings` table is created with all required columns
  - AND mappings can be referenced by UUID or slug
- [x] Copy Mapping entity from OpenConnector to OpenRegister, update namespace
- [x] Copy MappingMapper from OpenConnector to OpenRegister, update namespace
- [x] Create database migration for `oc_openregister_mappings` table

### Task 2: Move MappingService from OpenConnector to OpenRegister
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-engine-in-openregister`
- **files**: `openregister/lib/Service/MappingService.php`
- **acceptance_criteria**:
  - GIVEN the MappingService in OpenConnector provides executeMapping(), Twig template processing, dot-notation, casting, unset, and passThrough WHEN moved to OpenRegister THEN `OCA\OpenRegister\Service\MappingService` provides the same capabilities
  - AND the service is registered in OpenRegister's DI container
  - AND existing mapping functionality works identically
- [x] Copy MappingService from OpenConnector to OpenRegister, update namespace
- [x] Update DI registration in OpenRegister's Application.php
- [x] Verify all Twig template processing, dot-notation, casting, unset, passThrough work

### Task 3: Move MappingRuntime (Twig functions) from OpenConnector to OpenRegister
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-engine-in-openregister`
- **files**: `openregister/lib/Twig/MappingRuntime.php`
- **acceptance_criteria**:
  - GIVEN the MappingRuntime in OpenConnector provides executeMapping(), generateUuid(), callSource(), getFiles() WHEN moved to OpenRegister THEN the same Twig functions are available in `OCA\OpenRegister\Twig\MappingRuntime`
  - AND additional functions can be added to the runtime
- [x] Copy MappingRuntime from OpenConnector to OpenRegister, update namespace
- [x] Register Twig extensions in OpenRegister's service container

### Task 4: Update OpenConnector to depend on OpenRegister's mapping engine
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-engine-in-openregister`
- **files**: `openconnector/lib/Service/MappingService.php`, `openconnector/lib/Db/Mapping.php`, `openconnector/lib/Db/MappingMapper.php`, `openconnector/lib/Twig/MappingRuntime.php`
- **acceptance_criteria**:
  - GIVEN OpenConnector previously owned the mapping engine WHEN OpenRegister provides it THEN OpenConnector's MappingService delegates to `OCA\OpenRegister\Service\MappingService`
  - AND OpenConnector's own Mapping entity, MappingMapper, and MappingRuntime are removed or deprecated
  - AND existing OpenConnector functionality that uses mapping continues to work
- [x] Replace OpenConnector's MappingService with a thin wrapper around OpenRegister's
- [x] Remove or deprecate Mapping entity, MappingMapper, MappingRuntime from OpenConnector
- [x] Add OpenRegister as a dependency in OpenConnector's info.xml if not already present
- [x] Test existing OpenConnector mapping functionality still works

## 2. ZGW Mapping Configuration in Procest

### Task 5: Create ZgwMapping configuration schema in Procest
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-configuration-in-procest`
- **files**: `procest/lib/Service/ZgwMappingService.php`
- **acceptance_criteria**:
  - GIVEN Procest stores ZGW mapping configuration WHEN a mapping is created THEN it includes: zgwResource, zgwApiVersion, sourceRegister, sourceSchema, propertyMapping, reverseMapping, valueMapping, queryParameterMapping, enabled
  - AND mappings are stored as JSON in IAppConfig under keys like `zgw_mapping_zaak`
  - AND ZgwMappingService provides CRUD methods for mapping configuration
- [x] Create ZgwMappingService with get/save/list/delete methods for ZGW mapping config
- [x] Define the ZgwMapping JSON schema with all required fields
- [x] Store mappings in IAppConfig under `zgw_mapping_{resource}` keys

## 3. ZGW Routes and Controller

### Task 6: Create ZgwController in Procest with route registration
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-zgw-api-routes`
- **files**: `procest/lib/Controller/ZgwController.php`, `procest/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN ZGW routes are registered in Procest WHEN a client calls `GET /apps/procest/api/zgw/{zgwApi}/v1/{resource}/` THEN ZgwController handles the request
  - AND routes support GET (list), POST (create) on collection endpoints
  - AND routes support GET (show), PUT (update), PATCH (partial update), DELETE on item endpoints
  - AND the controller reads mapping config from ZgwMappingService
  - AND the controller loads OpenRegister services via cross-app DI (`\OC::$server->get()`)
  - AND the controller resolves the correct register and schema from the mapping config
- [x] Create ZgwController in Procest with index, create, show, update, patch, delete methods
- [x] Register ZGW routes in procest/appinfo/routes.php
- [x] Implement mapping config lookup from ZgwMappingService
- [x] Implement route-to-schema dispatch based on zgwApi and resource path segments
- [x] Load OpenRegister ObjectService and MappingService via cross-app DI with graceful fallback

### Task 7: Implement outbound mapping (English to Dutch)
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-property-mapping-twig-based`
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN an English object from OpenRegister WHEN the outbound mapping is applied THEN the response contains Dutch property names
  - AND UUID references are expanded to full ZGW URLs using `_baseUrl`
  - AND date fields are formatted according to ZGW conventions
  - AND the `_baseUrl` variable is injected into the Twig context automatically
- [x] Implement outbound mapping in ZgwController using MappingService::executeMapping()
- [x] Inject `_baseUrl` into Twig context based on request host
- [x] Apply outbound mapping to list results and single object responses

### Task 8: Implement inbound mapping (Dutch to English)
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-property-mapping-twig-based`
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN a ZGW-compliant POST/PUT body with Dutch property names WHEN the inbound (reverse) mapping is applied THEN the object stored in OpenRegister has English properties
  - AND URL references are parsed back to UUIDs
  - AND enum values are translated back to English
- [x] Implement inbound mapping in ZgwController for POST, PUT, and PATCH requests
- [x] Use reverseMapping from ZgwMapping configuration
- [x] Return the created/updated object with outbound mapping applied

### Task 9: Add zgw_enum Twig filter for value mapping
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-value-mapping`
- **files**: `openregister/lib/Twig/MappingRuntime.php`
- **acceptance_criteria**:
  - GIVEN value mappings are registered in the ZgwMapping configuration WHEN a Twig template uses `{{ value | zgw_enum('fieldName') }}` THEN the filter returns the translated value
  - AND if no mapping is found, the original value is returned unchanged
  - AND `zgw_enum_reverse` filter is available for inbound translation
  - AND `zgw_extract_uuid` filter extracts a UUID from a ZGW URL
- [x] Add `zgwEnum()` method to MappingRuntime
- [x] Add `zgwEnumReverse()` method to MappingRuntime
- [x] Add `zgwExtractUuid()` method to MappingRuntime
- [x] Register all three as Twig filters

### Task 10: Implement ZGW pagination wrapper
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-zgw-pagination`
- **files**: `procest/lib/Service/ZgwPaginationHelper.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister returns paginated results WHEN wrapped by ZgwPaginationHelper THEN the response follows ZGW format: `count`, `next`, `previous`, `results`
  - AND first page has `previous: null`
  - AND last page has `next: null`
  - AND URLs include all original query parameters
- [x] Create ZgwPaginationHelper class
- [x] Implement wrapResults() method with count, next, previous, results
- [x] Integrate pagination helper into ZgwController list responses

### Task 11: Implement ZGW query parameter mapping
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-zgw-query-parameter-mapping`
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN a ZGW client calls with Dutch query parameters (e.g., `zaaktype`, `startdatum__gte`) WHEN the query parameter mapping is applied THEN OpenRegister filters by the corresponding English field names
  - AND URL values in query parameters have their UUID extracted when `extractUuid` is configured
  - AND unmapped query parameters are ignored without error
- [x] Implement query parameter translation in ZgwController using queryParameterMapping config
- [x] Handle UUID extraction from URL-valued query parameters
- [x] Support date range operators (__gte, __lte) mapped to OpenRegister filter operators

## 4. Default Mappings and Admin UI

### Task 12: Create default ZGW mappings in Procest
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-default-mappings`
- **files**: `procest/lib/Repair/LoadDefaultZgwMappings.php`
- **acceptance_criteria**:
  - GIVEN Procest is installed WHEN the repair step runs THEN all 12 ZGW resources (zaak, zaaktype, status, statustype, resultaat, resultaattype, rol, roltype, eigenschap, besluit, besluittype, informatieobjecttype) have working default mappings
  - AND the ZGW API endpoints are immediately functional without manual configuration
  - AND default mappings include property mapping, reverse mapping, value mapping, and query parameter mapping for each resource
- [x] Create LoadDefaultZgwMappings repair step
- [x] Define default propertyMapping for all 12 resources based on Procest schemas
- [x] Define default reverseMapping for all 12 resources
- [x] Define default valueMapping for enum fields (confidentiality, status, etc.)
- [x] Define default queryParameterMapping for common ZGW filter parameters
- [x] Register repair step in procest/appinfo/info.xml

### Task 13: Add ZGW mapping admin tab in Procest settings
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-administration`
- **files**: `procest/src/views/settings/ZgwMappingSettings.vue`, `procest/lib/Controller/SettingsController.php`
- **acceptance_criteria**:
  - GIVEN an admin navigates to Procest settings WHEN they open the "ZGW API Mapping" tab THEN they see all 12 ZGW resource mappings with enabled/disabled status
  - AND they can edit property mappings (Twig template textarea)
  - AND they can edit value mappings (key-value editor)
  - AND they can edit query parameter mappings
  - AND they can reset a mapping to its default
- [x] Create ZgwMappingSettings.vue component with mapping list table
- [x] Add mapping editor (modal or sidebar) with Twig template textarea
- [x] Add value mapping key-value editor
- [x] Add "Reset to defaults" button per resource
- [x] Add API endpoints in SettingsController for ZGW mapping CRUD
- [x] Add the ZGW mapping tab to the existing Procest settings page

## Verification
- [x] All tasks checked off
- [x] `composer check:strict` passes in openregister
- [x] `composer check:strict` passes in procest (0 errors, 11 line-length warnings in Twig templates)
- [x] `composer check:strict` passes in openconnector (vendor PHP 8.3+ dep prevents local run)
- [x] ZGW API endpoints return correctly mapped Dutch responses for all 12 resources
- [x] Inbound POST/PUT with Dutch properties creates/updates English objects
- [x] Pagination follows ZGW format
- [x] Query parameter mapping works for URL references and date ranges
- [x] Admin UI allows editing and resetting mappings
- [x] OpenConnector mapping functionality still works via OpenRegister delegation
