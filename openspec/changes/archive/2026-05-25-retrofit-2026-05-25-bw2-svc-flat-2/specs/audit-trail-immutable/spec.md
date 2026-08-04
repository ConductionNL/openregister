---
retrofit: true
---

# Audit Trail Immutable

## Why

The existing requirements establish that audit entries are immutable and
exportable. The operator-facing access surface that reads, exports, and
(for administrators) purges those entries lives in `LogService` and is
unspecified. In particular, object-scoped retrieval must keep an object's
audit trail reachable even after its register or schema is soft-deleted,
and bulk deletion is an explicit administrative-retention operation
distinct from the immutability guarantee. This change anchors that
surface.

## ADDED Requirements

### Requirement: Audit-Trail Access, Export, and Administrative Deletion Surface
The service MUST expose audit-trail retrieval scoped to a single object with register/schema membership validation that still succeeds when the register or schema has been soft-deleted, MUST count and list entries with the same filters/pagination, MUST export entries in CSV, JSON, XML, and TXT formats, and MUST support single and filtered bulk deletion as an administrative retention operation.

`LogService::getLogs()` and `LogService::count()` MUST resolve the object across all sources including soft-deleted objects, MUST validate the object belongs to the requested register/schema by comparing stored IDs while tolerating a soft-deleted (unresolvable) register or schema, and MUST restrict results to the object's UUID. `LogService::exportLogs()` MUST emit `{ content, filename, contentType }` for each of the `csv`, `json`, `xml`, and `txt` formats and MUST reject an unsupported format with `InvalidArgumentException`. `LogService::deleteLog()` MUST delete one entry by id (or surface a not-found error), and `LogService::deleteLogs()` MUST delete a filter/id-selected set and MUST report `{ deleted, failed, total }`. Deletion is an administrative retention action and does not relax the per-entry immutability guarantee enforced elsewhere in this capability.

#### Scenario: Audit trail readable after register soft-delete
- **GIVEN** an object whose register has been soft-deleted
- **WHEN** the object-scoped log retrieval runs
- **THEN** the object's audit entries MUST still be returned, scoped by the object UUID

#### Scenario: Export rejects an unsupported format
- **GIVEN** an export request for an unknown format
- **WHEN** the export runs
- **THEN** an `InvalidArgumentException` MUST be raised

#### Scenario: Bulk deletion reports per-item outcome
- **GIVEN** a filter selecting several audit entries
- **WHEN** the bulk deletion runs
- **THEN** the result MUST report the deleted, failed, and total counts
