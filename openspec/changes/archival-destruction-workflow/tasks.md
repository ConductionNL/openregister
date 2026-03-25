---
status: in-progress
---

# Tasks

## Task 1: Database migration for selection_lists and destruction_lists tables
Create migration `Version1Date20260325120000` with two new tables.
- [ ] Create `oc_openregister_selection_lists` table (id, uuid, category, retention_years, action, description, schema_overrides, organisation, created, updated)
- [ ] Create `oc_openregister_destruction_lists` table (id, uuid, name, status, objects, approved_by, approved_at, notes, organisation, created, updated)

**Spec ref:** design.md#database-migration
**Files:** lib/Migration/Version1Date20260325120000.php

## Task 2: SelectionList entity and mapper
Create the SelectionList entity and its QBMapper.
- [ ] Create `SelectionList` entity with all fields, types, jsonSerialize
- [ ] Create `SelectionListMapper` with findByCategory(), findByUuid(), findAll()

**Spec ref:** design.md#entities
**Files:** lib/Db/SelectionList.php, lib/Db/SelectionListMapper.php

## Task 3: DestructionList entity and mapper
Create the DestructionList entity and its QBMapper.
- [ ] Create `DestructionList` entity with all fields, types, jsonSerialize
- [ ] Create `DestructionListMapper` with findByStatus(), findByUuid(), findAll()

**Spec ref:** design.md#entities
**Files:** lib/Db/DestructionList.php, lib/Db/DestructionListMapper.php

## Task 4: ArchivalService
Implement the core archival business logic service.
- [ ] Implement setRetentionMetadata() with validation of enum values
- [ ] Implement calculateArchivalDate() using SelectionList retention years
- [ ] Implement generateDestructionList() querying eligible objects
- [ ] Implement approveDestructionList() with object deletion and audit trail
- [ ] Implement rejectFromDestructionList() with date extension
- [ ] Implement findObjectsDueForDestruction()

**Spec ref:** design.md#service, specs/archivering-vernietiging/spec.md
**Files:** lib/Service/ArchivalService.php

## Task 5: ArchivalController with API routes
Create the controller and register routes.
- [ ] Implement selection list CRUD endpoints
- [ ] Implement retention metadata endpoints (GET/PUT on objects)
- [ ] Implement destruction list endpoints (generate, list, get, approve, reject)
- [ ] Register all routes in appinfo/routes.php

**Spec ref:** design.md#controller
**Files:** lib/Controller/ArchivalController.php, appinfo/routes.php

## Task 6: DestructionCheckJob background job
Implement the daily background job for destruction scanning.
- [ ] Create DestructionCheckJob extending TimedJob (86400s interval)
- [ ] Query objects due for destruction via ArchivalService
- [ ] Generate destruction list if objects found
- [ ] Register job in Application.php

**Spec ref:** design.md#background-job, specs/archivering-vernietiging/spec.md#background-destruction-check
**Files:** lib/BackgroundJob/DestructionCheckJob.php, lib/Db/Application.php

## Task 7: Unit tests for ArchivalService
Write comprehensive unit tests for the service layer.
- [ ] Test setRetentionMetadata() with valid and invalid data (3+ tests)
- [ ] Test calculateArchivalDate() with various scenarios (3+ tests)
- [ ] Test generateDestructionList() (2+ tests)
- [ ] Test approveDestructionList() with audit trail verification (2+ tests)
- [ ] Test rejectFromDestructionList() (2+ tests)

**Spec ref:** specs/archivering-vernietiging/spec.md
**Files:** tests/Unit/Service/ArchivalServiceTest.php

## Task 8: Unit tests for DestructionCheckJob and entities
Write unit tests for background job and entity classes.
- [ ] Test DestructionCheckJob run() with and without eligible objects (2+ tests)
- [ ] Test SelectionList entity serialization and field types (2+ tests)
- [ ] Test DestructionList entity serialization and status transitions (2+ tests)

**Spec ref:** specs/archivering-vernietiging/spec.md
**Files:** tests/Unit/BackgroundJob/DestructionCheckJobTest.php, tests/Unit/Db/SelectionListTest.php, tests/Unit/Db/DestructionListTest.php

## Task 9: Unit tests for ArchivalController
Write controller unit tests.
- [ ] Test selection list CRUD responses (3+ tests)
- [ ] Test retention metadata endpoints (2+ tests)
- [ ] Test destruction list workflow endpoints (3+ tests)

**Spec ref:** specs/archivering-vernietiging/spec.md
**Files:** tests/Unit/Controller/ArchivalControllerTest.php
