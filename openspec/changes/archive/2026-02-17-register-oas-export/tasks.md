# Tasks: register-oas-export

## 1. Baseline: Capture Current OAS Output and Run Redocly Lint

### Task 1.1: Download current OAS and run Redocly lint to establish baseline
- **spec_ref**: `specs/oas-validation/spec.md#requirement-valid-openapi-31-output`
- **files**: none (investigation task)
- **acceptance_criteria**:
  - GIVEN the softwarecatalog register exists with schemas WHEN `GET /api/registers/{id}/oas` is called THEN save the output to a JSON file AND run `npx @redocly/cli lint` to identify all current errors
- [x] Install Redocly CLI locally (`npm install -g @redocly/cli`)
- [x] Download OAS for softwarecatalog register and save to file
- [x] Run `redocly lint` and document all errors/warnings

## 2. OAS Validation Fixes

### Task 2.1: Fix array query parameter items definition
- **spec_ref**: `specs/oas-validation/spec.md#requirement-valid-query-parameters`
- **files**: `lib/Service/OasService.php` (`createCommonQueryParameters`)
- **acceptance_criteria**:
  - GIVEN a schema with array-type properties WHEN OAS is generated THEN array query parameters MUST have `"items": {"type": "string"}` instead of empty `"items": {}`
- [x] Implement
- [x] Test

### Task 2.2: Fix property sanitization for strict OAS compliance
- **spec_ref**: `specs/oas-validation/spec.md#requirement-valid-property-definitions`
- **files**: `lib/Service/OasService.php` (`sanitizePropertyDefinition`)
- **acceptance_criteria**:
  - GIVEN properties with empty `allOf`/`anyOf`/`oneOf` WHEN OAS is generated THEN those empty arrays MUST be removed
  - GIVEN properties with empty `$ref` WHEN OAS is generated THEN the invalid `$ref` MUST be removed
- [x] Implement
- [x] Test

### Task 2.3: Ensure $ref references resolve to existing components
- **spec_ref**: `specs/oas-validation/spec.md#requirement-valid-schema-component-references`
- **files**: `lib/Service/OasService.php` (`validateOasIntegrity`, `validateSchemaReferences`)
- **acceptance_criteria**:
  - GIVEN schemas with titles containing spaces WHEN OAS is generated THEN `$ref` values MUST use the same sanitized name as the component key
  - GIVEN a `$ref` to a non-existent component WHEN validation runs THEN it MUST be logged and removed or fixed
- [x] Implement
- [x] Test

### Task 2.4: Verify operationId uniqueness across multi-schema registers
- **spec_ref**: `specs/oas-validation/spec.md#requirement-operationid-uniqueness`
- **files**: `lib/Service/OasService.php` (`addCrudPaths`)
- **acceptance_criteria**:
  - GIVEN a register with schemas "Module" and "Organisatie" WHEN OAS is generated THEN all operationId values MUST be unique (e.g., `getAllModule` vs `getAllOrganisatie`)
- [x] Implement
- [x] Test

### Task 2.5: Verify tags consistency
- **spec_ref**: `specs/oas-validation/spec.md#requirement-tags-reference-existing-definitions`
- **files**: `lib/Service/OasService.php` (`createOas`)
- **acceptance_criteria**:
  - GIVEN generated OAS WHEN checked THEN every tag used in operations MUST exist in the top-level `tags` array
- [x] Implement
- [x] Test

## 3. Base Template Cleanup

### Task 3.1: Remove hardcoded read/write scopes from BaseOas.json
- **spec_ref**: `specs/rbac-scopes/spec.md#requirement-base-template-cleanup`
- **files**: `lib/Service/Resources/BaseOas.json`
- **acceptance_criteria**:
  - GIVEN the base template file WHEN loaded THEN `components.securitySchemes.oauth2.flows.authorizationCode.scopes` MUST be an empty object `{}`
  - AND `basicAuth` and `oauth2` security schemes MUST still be present
- [x] Implement
- [x] Test

## 4. RBAC Group Extraction

### Task 4.1: Add method to extract unique groups from schema authorization config
- **spec_ref**: `specs/rbac-scopes/spec.md#requirement-extract-groups-from-schema-rbac-configuration`
- **files**: `lib/Service/OasService.php`
- **acceptance_criteria**:
  - GIVEN a schema with authorization rules on properties WHEN `extractSchemaGroups($schema)` is called THEN it MUST return `['readGroups' => [...], 'updateGroups' => [...]]` with unique, deduplicated group names
  - GIVEN a schema without authorization rules WHEN called THEN it MUST return empty arrays for both
- [x] Implement
- [x] Test

### Task 4.2: Generate OAuth2 scopes from extracted groups
- **spec_ref**: `specs/rbac-scopes/spec.md#requirement-map-groups-to-oauth2-scopes`
- **files**: `lib/Service/OasService.php`
- **acceptance_criteria**:
  - GIVEN extracted groups ["admin", "redacteuren", "public"] WHEN scopes are generated THEN `components.securitySchemes.oauth2.flows.authorizationCode.scopes` MUST contain `"admin": "Full administrative access"`, `"redacteuren": "Access for redacteuren group"`, `"public": "Public (unauthenticated) access"`
- [x] Implement
- [x] Test

## 5. Per-Operation Security

### Task 5.1: Apply security requirements to CRUD operations based on RBAC groups
- **spec_ref**: `specs/rbac-scopes/spec.md#requirement-per-operation-security-requirements`
- **files**: `lib/Service/OasService.php` (`createGetCollectionOperation`, `createGetOperation`, `createPostOperation`, `createPutOperation`, `createDeleteOperation`)
- **acceptance_criteria**:
  - GIVEN a schema with read groups ["public", "redacteuren"] WHEN GET operations are generated THEN they MUST have `"security": [{"oauth2": ["public", "redacteuren"]}, {"basicAuth": []}]`
  - GIVEN a schema with update groups ["redacteuren"] WHEN POST/PUT/DELETE operations are generated THEN "admin" MUST be auto-added AND security MUST include `{"oauth2": ["redacteuren", "admin"]}`
- [x] Implement
- [x] Test

### Task 5.2: Skip per-operation security for schemas without RBAC rules
- **spec_ref**: `specs/rbac-scopes/spec.md#requirement-fallback-security-for-schemas-without-rbac`
- **files**: `lib/Service/OasService.php` (`addCrudPaths`)
- **acceptance_criteria**:
  - GIVEN a schema with no property-level authorization WHEN OAS is generated THEN operations MUST NOT have operation-level `security` fields AND global security at document root SHALL apply
  - GIVEN a mixed register (one schema with RBAC, one without) WHEN OAS is generated THEN only the RBAC schema's operations get per-operation security
- [x] Implement
- [x] Test

## 6. Integration Testing

### Task 6.1: Run Redocly lint on final OAS output
- **spec_ref**: `specs/oas-validation/spec.md#requirement-valid-openapi-31-output`
- **files**: none (validation task)
- **acceptance_criteria**:
  - GIVEN all fixes applied WHEN `GET /api/registers/{id}/oas` output is saved and linted THEN `redocly lint` MUST produce zero errors
  - AND `GET /api/registers/oas` MUST also pass with zero errors
- [x] Lint single-register OAS
- [x] Lint all-registers OAS

### Task 6.2: Test with Redocly preview to verify rendering
- **spec_ref**: `specs/oas-validation/spec.md#requirement-valid-openapi-31-output`
- **files**: none (validation task)
- **acceptance_criteria**:
  - GIVEN the linted OAS file WHEN opened in `redocly preview-docs` THEN all schemas, endpoints, and security schemes MUST render correctly AND group-based scopes MUST be visible on each operation
- [ ] Run `redocly preview-docs` and verify rendering
- [ ] Verify scopes are visible per operation in the rendered docs

## Verification
- [x] All tasks checked off
- [x] `redocly lint` passes with zero errors on single-register OAS
- [x] `redocly lint` passes with zero errors on all-registers OAS
- [x] Schemas with RBAC show per-operation security in OAS
- [x] Schemas without RBAC use global security fallback
- [ ] Manual testing against acceptance criteria
