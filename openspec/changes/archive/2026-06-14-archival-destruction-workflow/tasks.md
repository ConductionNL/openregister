---
status: completed
---

# Tasks

## Task 1: Database migration for selection_lists and destruction_lists tables
Create migration `Version1Date20260325120000` with two new tables.
- [x] Create `oc_openregister_selection_lists` table (id, uuid, category, retention_years, action, description, schema_overrides, organisation, created, updated)
- [x] Create `oc_openregister_destruction_lists` table (id, uuid, name, status, objects, approved_by, approved_at, notes, organisation, created, updated)

**Spec ref:** design.md#database-migration
**Files:** lib/Migration/Version1Date20260325120000.php

## Task 2: SelectionList entity and mapper
Create the SelectionList entity and its QBMapper.
- [x] Create `SelectionList` entity with all fields, types, jsonSerialize
- [x] Create `SelectionListMapper` with findByCategory(), findByUuid(), findAll()

**Spec ref:** design.md#entities
**Files:** lib/Db/SelectionList.php, lib/Db/SelectionListMapper.php

## Task 3: DestructionList entity and mapper
Create the DestructionList entity and its QBMapper.
- [x] Create `DestructionList` entity with all fields, types, jsonSerialize
- [x] Create `DestructionListMapper` with findByStatus(), findByUuid(), findAll()

**Spec ref:** design.md#entities
**Files:** lib/Db/DestructionList.php, lib/Db/DestructionListMapper.php

## Task 4: ArchivalService
Implement the core archival business logic service.
- [x] Implement setRetentionMetadata() with validation of enum values
- [x] Implement calculateArchivalDate() using SelectionList retention years
- [x] Implement generateDestructionList() querying eligible objects
- [x] Implement approveDestructionList() with object deletion and audit trail
- [x] Implement rejectFromDestructionList() with date extension
- [x] Implement findObjectsDueForDestruction()

**Spec ref:** design.md#service, specs/archivering-vernietiging/spec.md
**Files:** lib/Service/ArchivalService.php

## Task 5: ArchivalController with API routes
Create the controller and register routes.
- [x] Implement selection list CRUD endpoints
- [x] Implement retention metadata endpoints (GET/PUT on objects)
- [x] Implement destruction list endpoints (generate, list, get, approve, reject)
- [x] Register all routes in appinfo/routes.php

**Spec ref:** design.md#controller
**Files:** lib/Controller/ArchivalController.php, appinfo/routes.php

## Task 6: DestructionCheckJob background job
Implement the daily background job for destruction scanning.
- [x] Create DestructionCheckJob extending TimedJob (86400s interval)
- [x] Query objects due for destruction via ArchivalService
- [x] Generate destruction list if objects found
- [x] Register job in info.xml

**Spec ref:** design.md#background-job, specs/archivering-vernietiging/spec.md#background-destruction-check
**Files:** lib/BackgroundJob/DestructionCheckJob.php, appinfo/info.xml

## Task 7: Unit tests for ArchivalService
Write comprehensive unit tests for the service layer.
- [x] Test setRetentionMetadata() with valid and invalid data (6 tests)
- [x] Test calculateArchivalDate() with various scenarios (4 tests)
- [x] Test generateDestructionList() (2 tests)
- [x] Test approveDestructionList() with audit trail verification (2 tests)
- [x] Test rejectFromDestructionList() (3 tests)

**Spec ref:** specs/archivering-vernietiging/spec.md
**Files:** tests/Unit/Service/ArchivalServiceTest.php

## Task 8: Unit tests for DestructionCheckJob and entities
Write unit tests for background job and entity classes.
- [x] Test DestructionCheckJob run() with and without eligible objects (3 tests)
- [x] Test SelectionList entity serialization and field types (6 tests)
- [x] Test DestructionList entity serialization and status transitions (6 tests)

**Spec ref:** specs/archivering-vernietiging/spec.md
**Files:** tests/Unit/BackgroundJob/DestructionCheckJobTest.php, tests/Unit/Db/SelectionListTest.php, tests/Unit/Db/DestructionListTest.php

## Task 9: Unit tests for ArchivalController
Write controller unit tests.
- [x] Test selection list CRUD responses (6 tests)
- [x] Test retention metadata endpoints (2 tests)
- [x] Test destruction list workflow endpoints (8 tests)

**Spec ref:** specs/archivering-vernietiging/spec.md
**Files:** tests/Unit/Controller/ArchivalControllerTest.php
