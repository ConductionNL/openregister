## ADDED Requirements

### Requirement: Backend File Reverse-Lookup and Extraction Status

The system MUST provide a `FileSidebarService` that backs the OpenRegister tab in the
Nextcloud Files app sidebar. `getObjectsForFile(int $fileId): array` MUST find every
OpenRegister object that references a given Nextcloud file id by scanning the per-schema
magic tables of every register the current user can access (RBAC-respecting), returning
each match with its `uuid`, a derived `title`, and its register/schema identity. The scan
MUST skip registers with no schemas and magic tables that do not exist, and MUST tolerate
per-register/per-schema errors by continuing rather than failing the whole lookup.

`getExtractionStatus(int $fileId): array` MUST report the document-processing state for a
file: when no extraction chunks exist it MUST return a `none` status with zeroed counts;
otherwise it MUST return the chunk count, the extracted-at timestamp, the linked GDPR
entity count aggregated by entity type, an overall risk level, and the anonymization
status (whether anonymized, when, and the anonymized file id).

#### Scenario: Reverse-lookup finds referencing objects across accessible registers
- **GIVEN** a Nextcloud file referenced by objects in two schemas the user can access
- **WHEN** `getObjectsForFile()` is called
- **THEN** it MUST return both objects with their uuid, title, register, and schema
- **AND** registers the user cannot access MUST be excluded
- **AND** missing magic tables and per-schema errors MUST be skipped without failing the lookup

#### Scenario: Extraction status for an unprocessed file
- **GIVEN** a file with no extraction chunks
- **WHEN** `getExtractionStatus()` is called
- **THEN** it MUST return `extractionStatus: 'none'` with zeroed chunk, entity, and risk values

#### Scenario: Extraction status aggregates entities and anonymization
- **GIVEN** a file with extraction chunks and linked GDPR entity relations
- **WHEN** `getExtractionStatus()` is called
- **THEN** it MUST return the chunk count, extracted-at timestamp, entity counts grouped by type, and the anonymization status
