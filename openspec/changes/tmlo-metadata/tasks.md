## 1. Database and Entity Layer

- [ ] 1.1 Create database migration to add `tmlo` JSON column to openregister_objects table
- [ ] 1.2 Add `tmlo` property to ObjectEntity with getter/setter and JSON type registration
- [ ] 1.3 Update ObjectEntity jsonSerialize and getObjectArray to include `tmlo` field

## 2. TmloService Core

- [ ] 2.1 Create TmloService with TMLO field constants, validation helpers, and DI registration
- [ ] 2.2 Implement populateDefaults() method to auto-populate TMLO metadata from schema/register config
- [ ] 2.3 Implement validateStatusTransition() method for archival status transition rules
- [ ] 2.4 Implement validateFieldValues() method for TMLO field value validation

## 3. Integration with Object Save Pipeline

- [ ] 3.1 Hook TmloService into the object save pipeline to auto-populate TMLO on create
- [ ] 3.2 Hook TmloService validation into the object save pipeline to validate TMLO on update

## 4. MDTO XML Export

- [ ] 4.1 Implement generateMdtoXml() method in TmloService for single object MDTO export
- [ ] 4.2 Implement generateBatchMdtoXml() method for batch MDTO export
- [ ] 4.3 Add export routes and controller action for MDTO XML export endpoints

## 5. Query API

- [ ] 5.1 Add TMLO query filter support to ObjectsController/ObjectService for filtering by tmlo fields
- [ ] 5.2 Implement archival status summary endpoint with controller action and route

## 6. Tests

- [ ] 6.1 Write TmloService unit tests (populateDefaults, validateStatusTransition, validateFieldValues)
- [ ] 6.2 Write MDTO XML export unit tests (single export, batch export, missing metadata)
- [ ] 6.3 Write ObjectEntity tmlo field unit tests (hydration, serialization, getter defaults)

## 7. Quality and Documentation

- [ ] 7.1 Run php -l syntax check on all new/modified files
- [ ] 7.2 Fix any PHPCS/PHPMD/PHPStan issues in new code
