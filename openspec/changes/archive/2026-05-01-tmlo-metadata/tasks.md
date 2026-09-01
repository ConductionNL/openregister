# Tasks: TMLO Metadata

> **Status:** Shipped — all 19 tasks ticked. `tmlo` JSON column added to `openregister_objects`; `ObjectEntity::getTmlo()` exposes the block; `TmloService` implements populate-defaults / status-transition / field-value validation. MDTO XML export covers single + batch + missing-metadata paths. Unit tests cover the validation primitives + XML export + entity hydration; PHPCS / PHPMD / PHPStan clean.

## 1. Database and Entity Layer

- [x] 1.1 Create database migration to add `tmlo` JSON column to openregister_objects table
- [x] 1.2 Add `tmlo` property to ObjectEntity with getter/setter and JSON type registration
- [x] 1.3 Update ObjectEntity jsonSerialize and getObjectArray to include `tmlo` field

## 2. TmloService Core

- [x] 2.1 Create TmloService with TMLO field constants, validation helpers, and DI registration
- [x] 2.2 Implement populateDefaults() method to auto-populate TMLO metadata from schema/register config
- [x] 2.3 Implement validateStatusTransition() method for archival status transition rules
- [x] 2.4 Implement validateFieldValues() method for TMLO field value validation

## 3. Integration with Object Save Pipeline

- [x] 3.1 Hook TmloService into the object save pipeline to auto-populate TMLO on create
- [x] 3.2 Hook TmloService validation into the object save pipeline to validate TMLO on update

## 4. MDTO XML Export

- [x] 4.1 Implement generateMdtoXml() method in TmloService for single object MDTO export
- [x] 4.2 Implement generateBatchMdtoXml() method for batch MDTO export
- [x] 4.3 Add export routes and controller action for MDTO XML export endpoints

## 5. Query API

- [x] 5.1 Add TMLO query filter support to ObjectsController/ObjectService for filtering by tmlo fields
- [x] 5.2 Implement archival status summary endpoint with controller action and route

## 6. Tests

- [x] 6.1 Write TmloService unit tests (populateDefaults, validateStatusTransition, validateFieldValues)
- [x] 6.2 Write MDTO XML export unit tests (single export, batch export, missing metadata)
- [x] 6.3 Write ObjectEntity tmlo field unit tests (hydration, serialization, getter defaults)

## 7. Quality and Documentation

- [x] 7.1 Run php -l syntax check on all new/modified files
- [x] 7.2 Fix any PHPCS/PHPMD/PHPStan issues in new code
