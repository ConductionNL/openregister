# Tasks: referential-integrity-on-delete

## 1. Schema Property Extension

### Task 1.1: Add onDelete validation to schema property definitions
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-ondelete-configuration-on-schema-properties`
- **files**: `openregister/lib/Db/Schema.php`
- **acceptance_criteria**:
  - GIVEN a schema property with `$ref` and `"onDelete": "CASCADE"` WHEN the schema is saved THEN the onDelete value is persisted
  - GIVEN a schema property with `"onDelete": "DESTROY"` WHEN the schema is saved THEN validation rejects with an error
  - GIVEN a schema property without `$ref` but with `onDelete` WHEN the schema is saved THEN validation rejects (onDelete only valid on relation properties)
  - GIVEN a schema property with `$ref` and no `onDelete` WHEN retrieved THEN effective behavior is NO_ACTION
  - GIVEN onDelete values in mixed case WHEN saved THEN they are stored uppercase
- [x] Implement
- [x] Test

## 2. DeletionAnalysis Value Object

### Task 2.1: Create DeletionAnalysis DTO
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-pre-flight-deletion-analysis-candelete`
- **files**: `openregister/lib/Dto/DeletionAnalysis.php`
- **acceptance_criteria**:
  - GIVEN a DeletionAnalysis WHEN constructed with deletable=true and empty blockers THEN `deletable` is true
  - GIVEN a DeletionAnalysis WHEN constructed with blockers THEN `deletable` is false
  - GIVEN a DeletionAnalysis WHEN `toArray()` is called THEN it returns a JSON-serializable array with all fields
  - GIVEN `DeletionAnalysis::empty()` WHEN called THEN it returns a deletable analysis with no targets
- [x] Implement
- [x] Test

## 3. ReferentialIntegrityService

### Task 3.1: Build relation index from schema definitions
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-efficient-schema-analysis`
- **files**: `openregister/lib/Service/Object/ReferentialIntegrityService.php`
- **acceptance_criteria**:
  - GIVEN 20 schemas where 2 have onDelete config WHEN the index is built THEN only those 2 schemas appear in the reverse index
  - GIVEN a schema with `$ref: "person-schema"` and `onDelete: CASCADE` WHEN the index is queried for "person-schema" THEN it returns the referencing schema and property
  - GIVEN multiple schemas referencing the same target WHEN the index is queried THEN all referencing schemas are returned
  - GIVEN a batch delete of 100 objects WHEN the index is built THEN it is built once and reused
  - GIVEN a schema with `onDelete: NO_ACTION` WHEN the index is built THEN it is excluded
- [x] Implement
- [x] Test

### Task 3.2: Implement graph walking algorithm (walkDeletionGraph)
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-cascade-action`, `specs/referential-integrity/spec.md#requirement-restrict-action`, `specs/referential-integrity/spec.md#requirement-pre-flight-deletion-analysis-candelete`
- **files**: `openregister/lib/Service/Object/ReferentialIntegrityService.php`
- **acceptance_criteria**:
  - GIVEN Person A → ContactDetail B (CASCADE) WHEN walkDeletionGraph(A) is called THEN B appears in cascadeTargets
  - GIVEN ServiceType T → Service S (RESTRICT) WHEN walkDeletionGraph(T) is called THEN S appears in blockers and deletable is false
  - GIVEN Person A → ContactDetail B (CASCADE) → Audit C (RESTRICT) WHEN walkDeletionGraph(A) is called THEN C appears in blockers with full chain path
  - GIVEN circular reference A → B → A (CASCADE) WHEN walkDeletionGraph(A) is called THEN it terminates without infinite recursion
  - GIVEN object with no dependents WHEN walkDeletionGraph is called THEN it returns empty deletable analysis
  - GIVEN a mix of CASCADE, SET_NULL, RESTRICT dependents WHEN walkDeletionGraph is called THEN each appears in the correct target list
- [x] Implement
- [x] Test

### Task 3.3: Implement SET_NULL and SET_DEFAULT fallback logic
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-set_null-action`, `specs/referential-integrity/spec.md#requirement-set_default-action`
- **files**: `openregister/lib/Service/Object/ReferentialIntegrityService.php`
- **acceptance_criteria**:
  - GIVEN SET_NULL on a required property WHEN analyzed THEN it falls back to RESTRICT
  - GIVEN SET_DEFAULT with no default value WHEN analyzed THEN it falls back to SET_NULL
  - GIVEN SET_DEFAULT with no default on a required property WHEN analyzed THEN it falls back to RESTRICT
  - GIVEN SET_NULL on an array property WHEN applied THEN the UUID is removed from the array (not set to null)
- [x] Implement
- [x] Test

### Task 3.4: Implement applyDeletionActions (execute mutations)
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-cascade-action`, `specs/referential-integrity/spec.md#requirement-set_null-action`, `specs/referential-integrity/spec.md#requirement-set_default-action`
- **files**: `openregister/lib/Service/Object/ReferentialIntegrityService.php`
- **acceptance_criteria**:
  - GIVEN a DeletionAnalysis with cascadeTargets WHEN applyDeletionActions is called THEN those objects are soft-deleted with cascade metadata
  - GIVEN cascade-deleted objects WHEN their `deleted` metadata is inspected THEN it includes `deletedBy: cascade` and `cascadeSource: <root UUID>`
  - GIVEN nullifyTargets WHEN applyDeletionActions is called THEN references are cleared (single) or removed from array (array)
  - GIVEN defaultTargets WHEN applyDeletionActions is called THEN references are set to the configured default value
  - GIVEN the execution order THEN SET_NULL and SET_DEFAULT run before CASCADE
  - GIVEN chained cascades THEN deepest objects are deleted first (bottom-up)
- [x] Implement
- [x] Test

## 4. ReferentialIntegrityException

### Task 4.1: Create ReferentialIntegrityException
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-api-error-response-format`
- **files**: `openregister/lib/Exception/ReferentialIntegrityException.php`
- **acceptance_criteria**:
  - GIVEN a DeletionAnalysis with blockers WHEN the exception is created THEN it contains the analysis
  - GIVEN the exception WHEN `getAnalysis()` is called THEN it returns the DeletionAnalysis
  - GIVEN the exception WHEN caught by the controller THEN it can produce a structured 409 response body
- [x] Implement
- [x] Test

## 5. DeleteObject Integration

### Task 5.1: Integrate ReferentialIntegrityService into DeleteObject
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-pre-flight-deletion-analysis-candelete`, `specs/referential-integrity/spec.md#requirement-integration-with-soft-delete`
- **files**: `openregister/lib/Service/Object/DeleteObject.php`
- **acceptance_criteria**:
  - GIVEN a delete request WHEN the object has dependents with onDelete config THEN canDelete() is called before any mutation
  - GIVEN canDelete returns blockers WHEN the delete proceeds THEN a ReferentialIntegrityException is thrown
  - GIVEN canDelete returns deletable WHEN the delete proceeds THEN applyDeletionActions is called before the source object is soft-deleted
  - GIVEN an object whose schema has no incoming onDelete references WHEN deleted THEN no referential integrity checks are performed (efficient skip)
  - GIVEN already soft-deleted dependents WHEN cascade is applied THEN they are skipped
- [x] Implement
- [x] Test

## 6. API Endpoint

### Task 6.1: Add can-delete API endpoint
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-pre-flight-deletion-analysis-candelete`
- **files**: `openregister/lib/Controller/ObjectsController.php`, `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `GET /api/objects/{register}/{schema}/{id}/can-delete` WHEN called with valid IDs THEN returns 200 with DeletionAnalysis JSON
  - GIVEN a deletable object WHEN can-delete is called THEN `{deletable: true, cascadeTargets: [...], blockers: []}` is returned
  - GIVEN a blocked object WHEN can-delete is called THEN `{deletable: false, blockers: [...]}` is returned
  - GIVEN an unauthorized user WHEN can-delete is called THEN 403 is returned
  - GIVEN a non-existent object WHEN can-delete is called THEN 404 is returned
- [x] Implement
- [x] Test

### Task 6.2: Update DELETE endpoint to return 409 on RESTRICT
- **spec_ref**: `specs/referential-integrity/spec.md#requirement-api-error-response-format`
- **files**: `openregister/lib/Controller/ObjectsController.php`
- **acceptance_criteria**:
  - GIVEN a DELETE request for a restricted object WHEN ReferentialIntegrityException is thrown THEN the controller returns HTTP 409 Conflict
  - GIVEN the 409 response WHEN inspected THEN it contains `error`, `message`, and `blockers` fields
  - GIVEN chained RESTRICT WHEN the 409 is returned THEN `blockers` include the full chain path
- [x] Implement
- [x] Test

## Verification
- [ ] All tasks checked off
- [ ] `composer check:strict` passes in openregister
- [ ] Simple CASCADE: delete parent → children cascade-deleted
- [ ] Chained CASCADE: A → B → C all cascade-deleted
- [ ] Simple RESTRICT: delete referenced object → 409 returned
- [ ] Chained RESTRICT: CASCADE into RESTRICT → 409 with full chain
- [ ] SET_NULL: reference cleared, object survives
- [ ] SET_NULL on required → falls back to RESTRICT
- [ ] SET_DEFAULT: reference set to default, object survives
- [ ] SET_DEFAULT without default → falls back to SET_NULL
- [ ] NO_ACTION: no checks performed
- [ ] can-delete endpoint returns correct analysis
- [ ] Circular references don't cause infinite loops
- [ ] Schemas without onDelete config are skipped (performance)
- [ ] Batch deletes reuse relation index
- [ ] Already-deleted objects are skipped in cascade
- [ ] Manual testing against spec scenarios
- [ ] Code review against spec requirements
