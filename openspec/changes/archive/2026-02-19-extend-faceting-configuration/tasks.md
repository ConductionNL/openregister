# Tasks: extend-faceting-configuration

## 1. Backend: FacetHandler config normalization

### Task 1.1: Add facetable config normalization in getFacetableFieldsFromSchemas
- **spec_ref**: `specs/faceting-configuration/spec.md#requirement-facetable-config-object-support`
- **files**: `openregister/lib/Service/Object/FacetHandler.php`
- **acceptance_criteria**:
  - GIVEN a schema property has `"facetable": true` WHEN FacetHandler normalizes the config THEN it treats it as `{ aggregated: true, title: null, description: null, order: null }`
  - GIVEN a schema property has `"facetable": { "aggregated": false, "title": "Organisatie Type" }` WHEN FacetHandler normalizes the config THEN it preserves the specified values and defaults missing fields to `null`
  - GIVEN a schema property has `"facetable": false` WHEN FacetHandler discovers facetable fields THEN the property does not appear in facet results
- [x] Implement: Update `getFacetableFieldsFromSchemas()` to handle both boolean and object `facetable` values, normalizing to a config array with `aggregated`, `title`, `description`, `order` keys
- [x] Test: Verify backward compatibility with `facetable: true` and new config object format

## 2. Backend: Non-aggregated facet isolation

### Task 2.1: Implement non-aggregated facet logic in transformFacetsToStandardFormat
- **spec_ref**: `specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation`
- **files**: `openregister/lib/Service/Object/FacetHandler.php`
- **acceptance_criteria**:
  - GIVEN schema "Organisatie" has `type` with `aggregated: false` and schema "Product" has `type` with `aggregated: true` WHEN facets are calculated across both schemas THEN the response contains two separate entries: one non-aggregated for Organisatie and one aggregated for Product
  - GIVEN a non-aggregated property WHEN the facet response key is generated THEN it uses a unique key (not the raw property name) to avoid collisions
- [x] Implement: In `getFacetableFieldsFromSchemas()`, pass schema ID and facet config per field. In `transformFacetsToStandardFormat()`, split non-aggregated fields into separate facet entries keyed by schema context
- [x] Test: API call with multiple schemas having same property name returns distinct facet entries

### Task 2.2: Include schema ID in non-aggregated facet response
- **spec_ref**: `specs/faceting-configuration/spec.md#requirement-schema-id-in-non-aggregated-facet-response`
- **files**: `openregister/lib/Service/Object/FacetHandler.php`
- **acceptance_criteria**:
  - GIVEN a non-aggregated facet WHEN the response is built THEN the facet entry includes `"schema": <schemaId>`
  - GIVEN an aggregated facet WHEN the response is built THEN the facet entry does NOT include a `schema` field
- [x] Implement: In `buildFacetEntry()`, add optional `schema` parameter; set it for non-aggregated facets only
- [x] Test: Verify API response includes `schema` field only for non-aggregated facets

## 3. Backend: Custom title, description, and order

### Task 3.1: Apply custom title, description, and order from facet config
- **spec_ref**: `specs/faceting-configuration/spec.md#requirement-custom-facet-title-in-response`
- **files**: `openregister/lib/Service/Object/FacetHandler.php`
- **acceptance_criteria**:
  - GIVEN a property with config title "Organisatie Type" WHEN the facet response is built THEN `title` is "Organisatie Type" (not auto-generated)
  - GIVEN a property with config description "Filter by org type" WHEN the facet response is built THEN `description` is "Filter by org type"
  - GIVEN a property with config order 2 WHEN the facet response is built THEN `order` is 2 (not auto-incremented)
  - GIVEN a property with `facetable: true` (no config) WHEN the facet response is built THEN title, description, and order fall back to current auto-generated behavior
- [x] Implement: In `transformFacetsToStandardFormat()`, check for config values before falling back to auto-generated values
- [x] Test: Verify custom values appear in API response and defaults work for boolean facetable

## 4. Frontend: Schema editor faceting configuration UI

### Task 4.1: Replace facetable checkbox with configurable toggle in EditSchemaProperty.vue
- **spec_ref**: `specs/faceting-configuration/spec.md#requirement-schema-editor-faceting-configuration-ui`
- **files**: `openregister/src/modals/schema/EditSchemaProperty.vue`
- **acceptance_criteria**:
  - GIVEN a user enables the "Facetable" toggle WHEN the form renders THEN additional config fields appear: "Aggregated" toggle (default checked), "Facet Title" text field, "Facet Description" text field, "Facet Order" number field
  - GIVEN the "Facetable" toggle is unchecked WHEN the form renders THEN config fields are hidden
  - GIVEN a user enables facetable and leaves defaults (aggregated checked, fields empty) WHEN saving THEN the property is saved with `"facetable": true`
  - GIVEN a user enables facetable and unchecks aggregated with title "Organisatie Type" WHEN saving THEN the property is saved with `"facetable": { "aggregated": false, "title": "Organisatie Type", "description": null, "order": null }`
- [x] Implement: Add conditional config section below facetable toggle with NcCheckboxRadioSwitch for aggregated, NcTextField for title/description, NcInputField for order. Update save logic to serialize as boolean or object.
- [x] Test: Visually test with Playwright — open schema editor, toggle facetable, verify config fields appear/hide, save and verify stored value

### Task 4.2: Load existing faceting config when editing properties
- **spec_ref**: `specs/faceting-configuration/spec.md#requirement-schema-editor-faceting-configuration-ui`
- **files**: `openregister/src/modals/schema/EditSchemaProperty.vue`
- **acceptance_criteria**:
  - GIVEN a property has `"facetable": { "aggregated": false, "title": "Organisatie Type" }` WHEN the edit modal opens THEN the facetable toggle is checked, aggregated is unchecked, and title field shows "Organisatie Type"
  - GIVEN a property has `"facetable": true` WHEN the edit modal opens THEN the facetable toggle is checked and all config fields show defaults
- [x] Implement: In `initializeSchemaItem()`, handle both boolean and object `facetable` values, populating the config fields accordingly
- [x] Test: Visually test with Playwright — edit a property with existing config, verify fields are populated correctly

## 5. Frontend: Tilburg WOO UI _schema parameter

### Task 5.1: Add _schema query parameter for non-aggregated facet selection
- **spec_ref**: `specs/faceting-configuration/spec.md#requirement-frontend-_schema-parameter-for-non-aggregated-facets`
- **files**: `tilburg-woo-ui/src/molecules/con-facets-filters/con-facets-filters.js`
- **acceptance_criteria**:
  - GIVEN a facet with `schema: 42` and `queryParameter: "type"` WHEN user checks bucket "leverancier" THEN URL includes `type=leverancier&_schema=42`
  - GIVEN query includes `type=leverancier&_schema=42` WHEN user unchecks "leverancier" THEN both `type` and `_schema` are removed
  - GIVEN a facet without `schema` field WHEN user checks a bucket THEN `_schema` is NOT added to URL
- [x] Implement: In the facet selection handler, check for `schema` field on the facet data; if present, add/remove `_schema` alongside the facet value
- [x] Test: Visually test with Playwright — select non-aggregated facet, verify URL params, deselect, verify cleanup

### Task 5.2: Update facet ordering to use order field
- **spec_ref**: `specs/faceting-configuration/spec.md#requirement-frontend-facet-ordering-by-order-field`
- **files**: `tilburg-woo-ui/src/molecules/con-facets-filters/con-facets-filters.js`
- **acceptance_criteria**:
  - GIVEN facets with order values 1, 5, 10 WHEN rendered in the sidebar THEN they appear in ascending order: 1, 5, 10
- [x] Implement: Change the `sortedFacets` sorting logic to sort by `order` field (numeric ascending) instead of alphabetical title
- [x] Test: Verify facets appear in order specified by the backend

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing: create schema with non-aggregated facet, verify distinct facet in API response
- [x] Manual testing: verify schema editor UI shows/hides config fields
- [x] Manual testing: verify tilburg-woo-ui adds _schema param for non-aggregated facets
- [x] Playwright visual tests pass for schema editor modal
- [x] Code review against spec requirements
