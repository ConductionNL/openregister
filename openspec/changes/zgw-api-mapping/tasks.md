# Tasks: zgw-api-mapping

## 1. Move Mapping Engine to OpenRegister

### Task 1: Move Mapping entity and MappingMapper from OpenConnector to OpenRegister
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-engine-in-openregister`
- **files**: `openregister/lib/Db/Mapping.php`, `openregister/lib/Db/MappingMapper.php`, `openregister/lib/Migration/VersionXXXXDate_CreateMappings.php`
- **acceptance_criteria**:
  - GIVEN the Mapping entity in OpenConnector has fields: name, uuid, slug, mapping, unset, cast, passThrough WHEN moved to OpenRegister THEN `OCA\OpenRegister\Db\Mapping` preserves the same schema
  - GIVEN the migration runs WHEN OpenRegister is upgraded THEN the `oc_openregister_mappings` table is created with all required columns
  - AND mappings can be referenced by UUID or slug
- [ ] Copy Mapping entity from OpenConnector to OpenRegister, update namespace
- [ ] Copy MappingMapper from OpenConnector to OpenRegister, update namespace
- [ ] Create database migration for `oc_openregister_mappings` table

### Task 2: Move MappingService from OpenConnector to OpenRegister
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-engine-in-openregister`
- **files**: `openregister/lib/Service/MappingService.php`
- **acceptance_criteria**:
  - GIVEN the MappingService in OpenConnector provides executeMapping(), Twig template processing, dot-notation, casting, unset, and passThrough WHEN moved to OpenRegister THEN `OCA\OpenRegister\Service\MappingService` provides the same capabilities
  - AND the service is registered in OpenRegister's DI container
  - AND existing mapping functionality works identically
- [ ] Copy MappingService from OpenConnector to OpenRegister, update namespace
- [ ] Update DI registration in OpenRegister's Application.php
- [ ] Verify all Twig template processing, dot-notation, casting, unset, passThrough work

### Task 3: Move MappingRuntime (Twig functions) from OpenConnector to OpenRegister
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-engine-in-openregister`
- **files**: `openregister/lib/Twig/MappingRuntime.php`
- **acceptance_criteria**:
  - GIVEN the MappingRuntime in OpenConnector provides executeMapping(), generateUuid(), callSource(), getFiles() WHEN moved to OpenRegister THEN the same Twig functions are available in `OCA\OpenRegister\Twig\MappingRuntime`
  - AND additional functions can be added to the runtime
- [ ] Copy MappingRuntime from OpenConnector to OpenRegister, update namespace
- [ ] Register Twig extensions in OpenRegister's service container

### Task 4: Update OpenConnector to depend on OpenRegister's mapping engine
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-engine-in-openregister`
- **files**: `openconnector/lib/Service/MappingService.php`, `openconnector/lib/Db/Mapping.php`, `openconnector/lib/Db/MappingMapper.php`, `openconnector/lib/Twig/MappingRuntime.php`
- **acceptance_criteria**:
  - GIVEN OpenConnector previously owned the mapping engine WHEN OpenRegister provides it THEN OpenConnector's MappingService delegates to `OCA\OpenRegister\Service\MappingService`
  - AND OpenConnector's own Mapping entity, MappingMapper, and MappingRuntime are removed or deprecated
  - AND existing OpenConnector functionality that uses mapping continues to work
- [ ] Replace OpenConnector's MappingService with a thin wrapper around OpenRegister's
- [ ] Remove or deprecate Mapping entity, MappingMapper, MappingRuntime from OpenConnector
- [ ] Add OpenRegister as a dependency in OpenConnector's info.xml if not already present
- [ ] Test existing OpenConnector mapping functionality still works

## 2. ZGW Mapping Configuration in Procest

### Task 5: Create ZgwMapping configuration schema in Procest
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-configuration-in-procest`
- **files**: `procest/lib/Service/ZgwMappingService.php`
- **acceptance_criteria**:
  - GIVEN Procest stores ZGW mapping configuration WHEN a mapping is created THEN it includes: zgwResource, zgwApiVersion, sourceRegister, sourceSchema, propertyMapping, reverseMapping, valueMapping, queryParameterMapping, enabled
  - AND mappings are stored as JSON in IAppConfig under keys like `zgw_mapping_zaak`
  - AND ZgwMappingService provides CRUD methods for mapping configuration
- [ ] Create ZgwMappingService with get/save/list/delete methods for ZGW mapping config
- [ ] Define the ZgwMapping JSON schema with all required fields
- [ ] Store mappings in IAppConfig under `zgw_mapping_{resource}` keys

## 3. ZGW Routes and Controller

### Task 6: Create ZgwController in OpenRegister with route registration
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-zgw-api-routes`
- **files**: `openregister/lib/Controller/ZgwController.php`, `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN ZGW routes are registered WHEN a client calls `GET /api/zgw/{zgwApi}/v1/{resource}/` THEN ZgwController handles the request
  - AND routes support GET (list), POST (create) on collection endpoints
  - AND routes support GET (show), PUT (update), PATCH (partial update), DELETE on item endpoints
  - AND the controller reads mapping config from Procest's IAppConfig
  - AND the controller resolves the correct register and schema from the mapping config
- [ ] Create ZgwController with index, create, show, update, patch, delete methods
- [ ] Register ZGW routes in openregister/appinfo/routes.php
- [ ] Implement mapping config lookup from Procest IAppConfig
- [ ] Implement route-to-schema dispatch based on zgwApi and resource path segments

### Task 7: Implement outbound mapping (English to Dutch)
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-property-mapping-twig-based`
- **files**: `openregister/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN an English object from OpenRegister WHEN the outbound mapping is applied THEN the response contains Dutch property names
  - AND UUID references are expanded to full ZGW URLs using `_baseUrl`
  - AND date fields are formatted according to ZGW conventions
  - AND the `_baseUrl` variable is injected into the Twig context automatically
- [ ] Implement outbound mapping in ZgwController using MappingService::executeMapping()
- [ ] Inject `_baseUrl` into Twig context based on request host
- [ ] Apply outbound mapping to list results and single object responses

### Task 8: Implement inbound mapping (Dutch to English)
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-property-mapping-twig-based`
- **files**: `openregister/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN a ZGW-compliant POST/PUT body with Dutch property names WHEN the inbound (reverse) mapping is applied THEN the object stored in OpenRegister has English properties
  - AND URL references are parsed back to UUIDs
  - AND enum values are translated back to English
- [ ] Implement inbound mapping in ZgwController for POST, PUT, and PATCH requests
- [ ] Use reverseMapping from ZgwMapping configuration
- [ ] Return the created/updated object with outbound mapping applied

### Task 9: Add zgw_enum Twig filter for value mapping
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-value-mapping`
- **files**: `openregister/lib/Twig/MappingRuntime.php`
- **acceptance_criteria**:
  - GIVEN value mappings are registered in the ZgwMapping configuration WHEN a Twig template uses `{{ value | zgw_enum('fieldName') }}` THEN the filter returns the translated value
  - AND if no mapping is found, the original value is returned unchanged
  - AND `zgw_enum_reverse` filter is available for inbound translation
  - AND `zgw_extract_uuid` filter extracts a UUID from a ZGW URL
- [ ] Add `zgwEnum()` method to MappingRuntime
- [ ] Add `zgwEnumReverse()` method to MappingRuntime
- [ ] Add `zgwExtractUuid()` method to MappingRuntime
- [ ] Register all three as Twig filters

### Task 10: Implement ZGW pagination wrapper
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-zgw-pagination`
- **files**: `openregister/lib/Service/ZgwPaginationHelper.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister returns paginated results WHEN wrapped by ZgwPaginationHelper THEN the response follows ZGW format: `count`, `next`, `previous`, `results`
  - AND first page has `previous: null`
  - AND last page has `next: null`
  - AND URLs include all original query parameters
- [ ] Create ZgwPaginationHelper class
- [ ] Implement wrapResults() method with count, next, previous, results
- [ ] Integrate pagination helper into ZgwController list responses

### Task 11: Implement ZGW query parameter mapping
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-zgw-query-parameter-mapping`
- **files**: `openregister/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN a ZGW client calls with Dutch query parameters (e.g., `zaaktype`, `startdatum__gte`) WHEN the query parameter mapping is applied THEN OpenRegister filters by the corresponding English field names
  - AND URL values in query parameters have their UUID extracted when `extractUuid` is configured
  - AND unmapped query parameters are ignored without error
- [ ] Implement query parameter translation in ZgwController using queryParameterMapping config
- [ ] Handle UUID extraction from URL-valued query parameters
- [ ] Support date range operators (__gte, __lte) mapped to OpenRegister filter operators

## 4. Default Mappings and Admin UI

### Task 12: Create default ZGW mappings in Procest
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-default-mappings`
- **files**: `procest/lib/Repair/LoadDefaultZgwMappings.php`
- **acceptance_criteria**:
  - GIVEN Procest is installed WHEN the repair step runs THEN all 12 ZGW resources (zaak, zaaktype, status, statustype, resultaat, resultaattype, rol, roltype, eigenschap, besluit, besluittype, informatieobjecttype) have working default mappings
  - AND the ZGW API endpoints are immediately functional without manual configuration
  - AND default mappings include property mapping, reverse mapping, value mapping, and query parameter mapping for each resource
- [ ] Create LoadDefaultZgwMappings repair step
- [ ] Define default propertyMapping for all 12 resources based on Procest schemas
- [ ] Define default reverseMapping for all 12 resources
- [ ] Define default valueMapping for enum fields (confidentiality, status, etc.)
- [ ] Define default queryParameterMapping for common ZGW filter parameters
- [ ] Register repair step in procest/appinfo/info.xml

### Task 13: Add ZGW mapping admin tab in Procest settings
- **spec_ref**: `openspec/changes/zgw-api-mapping/specs/zgw-api-mapping/spec.md#requirement-mapping-administration`
- **files**: `procest/src/views/settings/ZgwMappingSettings.vue`, `procest/lib/Controller/SettingsController.php`
- **acceptance_criteria**:
  - GIVEN an admin navigates to Procest settings WHEN they open the "ZGW API Mapping" tab THEN they see all 12 ZGW resource mappings with enabled/disabled status
  - AND they can edit property mappings (Twig template textarea)
  - AND they can edit value mappings (key-value editor)
  - AND they can edit query parameter mappings
  - AND they can reset a mapping to its default
- [ ] Create ZgwMappingSettings.vue component with mapping list table
- [ ] Add mapping editor (modal or sidebar) with Twig template textarea
- [ ] Add value mapping key-value editor
- [ ] Add "Reset to defaults" button per resource
- [ ] Add API endpoints in SettingsController for ZGW mapping CRUD
- [ ] Add the ZGW mapping tab to the existing Procest settings page

## Verification
- [ ] All tasks checked off
- [ ] `composer check:strict` passes in openregister
- [ ] `composer check:strict` passes in procest
- [ ] `composer check:strict` passes in openconnector
- [ ] ZGW API endpoints return correctly mapped Dutch responses for all 12 resources
- [ ] Inbound POST/PUT with Dutch properties creates/updates English objects
- [ ] Pagination follows ZGW format
- [ ] Query parameter mapping works for URL references and date ranges
- [ ] Admin UI allows editing and resetting mappings
- [ ] OpenConnector mapping functionality still works via OpenRegister delegation
