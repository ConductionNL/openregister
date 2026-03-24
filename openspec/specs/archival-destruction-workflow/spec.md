---
status: implemented
---

# Archival Destruction Workflow

## Purpose

Implement a NEN 15489 compliant destruction workflow for register objects, providing automated destruction scheduling via background jobs, multi-step approval workflows with destruction lists, legal hold management, destruction certificate generation, and archiefactiedatum calculation using configurable afleidingswijzen. This capability builds on the archivering-vernietiging spec and integrates with the immutable audit trail and deletion audit trail for legally required evidence trails.

## Requirements



### Requirement: The system MUST provide a DestructionCheckJob that generates destruction lists from eligible objects

A Nextcloud `TimedJob` MUST scan for objects that have reached their `archiefactiedatum` with `archiefnominatie` set to `vernietigen` and generate destruction lists as register objects for archivist review.

#### Scenario: Daily destruction check generates a destruction list
- **GIVEN** 15 objects have `retention.archiefactiedatum` before today and `retention.archiefnominatie` set to `vernietigen`
- **AND** their `retention.archiefstatus` is `nog_te_archiveren`
- **AND** none of these objects have an active legal hold (`retention.legalHold.active` is not `true`)
- **WHEN** the `DestructionCheckJob` runs on its scheduled interval
- **THEN** the system MUST create a destruction list object in the archival register containing references to all 15 eligible objects
- **AND** each entry MUST include: object UUID, title, schema name, register name, `archiefactiedatum`, selectielijst category
- **AND** the destruction list MUST have status `in_review`
- **AND** an `INotification` MUST be sent to users with the archivist role

#### Scenario: Objects with active legal holds are excluded from destruction lists
- **GIVEN** 10 objects are eligible for destruction based on `archiefactiedatum`
- **AND** 3 of those objects have `retention.legalHold.active` set to `true`
- **WHEN** the `DestructionCheckJob` generates the destruction list
- **THEN** only the 7 objects without legal holds MUST be included in the destruction list
- **AND** the 3 held objects MUST NOT appear on the list

#### Scenario: Objects already on an existing destruction list are not duplicated
- **GIVEN** 10 objects are eligible for destruction
- **AND** a destruction list containing 8 of these objects already exists with status `in_review`
- **WHEN** the `DestructionCheckJob` runs again
- **THEN** only the 2 objects not already on an existing destruction list MUST be added to a new list
- **AND** the existing list MUST NOT be modified

#### Scenario: Soft-deleted objects are included but flagged
- **GIVEN** 3 of the eligible objects have already been soft-deleted (have a `deleted` field set)
- **WHEN** the `DestructionCheckJob` generates the destruction list
- **THEN** the soft-deleted objects MUST be included in the destruction list
- **AND** they MUST be marked with `alreadySoftDeleted: true` in the list entry

### Requirement: The system MUST provide API endpoints for destruction list management

An `ArchivalController` MUST expose REST endpoints under `/api/archival/` for listing, viewing, approving, and rejecting destruction lists.

#### Scenario: List destruction lists with status filter
- **GIVEN** 3 destruction lists exist: 1 with status `in_review`, 1 with `approved`, 1 with `rejected`
- **WHEN** `GET /api/archival/destruction-lists?status=in_review` is called by an authenticated archivist
- **THEN** the response MUST return only the 1 destruction list with status `in_review`
- **AND** each list item MUST include: UUID, status, creation date, object count, creator

#### Scenario: Get destruction list detail
- **GIVEN** a destruction list `dl-001` with 15 objects
- **WHEN** `GET /api/archival/destruction-lists/dl-001` is called
- **THEN** the response MUST include the full list of objects with their archival metadata
- **AND** each object entry MUST include: UUID, title, schema, register, `archiefactiedatum`, selectielijst category, legal hold status

#### Scenario: Unauthorized user cannot access destruction list endpoints
- **GIVEN** a user without the archivist role
- **WHEN** they call any `/api/archival/destruction-lists` endpoint
- **THEN** the system MUST return HTTP 403 Forbidden

### Requirement: Destruction MUST follow a multi-step approval workflow with full, partial, and rejection paths

Destruction lists MUST support full approval, partial approval (excluding specific objects), and full rejection, each with mandatory audit trail entries.

#### Scenario: Full approval triggers batch destruction
- **GIVEN** a destruction list `dl-001` with 15 objects and status `in_review`
- **WHEN** an archivist calls `POST /api/archival/destruction-lists/dl-001/approve` with `{ "action": "approve_all" }`
- **THEN** the destruction list status MUST change to `approved`
- **AND** a `DestructionExecutionJob` MUST be queued to permanently delete all 15 objects
- **AND** an audit trail entry MUST be created with action `archival.destruction_approved` recording the approving archivist and timestamp

#### Scenario: Partial approval excludes specific objects
- **GIVEN** a destruction list `dl-001` with 15 objects
- **WHEN** the archivist calls `POST /api/archival/destruction-lists/dl-001/approve` with `{ "action": "approve_partial", "excluded": ["obj-3", "obj-7", "obj-12"], "exclusionReasons": { "obj-3": "Lopend bezwaar", "obj-7": "Nader onderzoek", "obj-12": "Verkeerde classificatie" } }`
- **THEN** 12 objects MUST be approved for destruction
- **AND** the 3 excluded objects MUST have their `retention.archiefactiedatum` extended by a configurable period (default: 1 year)
- **AND** the exclusion reason MUST be stored per object in `retention.exclusionHistory[]`
- **AND** the destruction list MUST record both approved and excluded objects with their status

#### Scenario: Full rejection with mandatory reason
- **GIVEN** a destruction list `dl-001` with 15 objects
- **WHEN** the archivist calls `POST /api/archival/destruction-lists/dl-001/reject` with `{ "reason": "Selectielijst niet actueel, herclassificatie nodig" }`
- **THEN** no objects MUST be destroyed
- **AND** the destruction list status MUST change to `rejected`
- **AND** all objects on the list MUST have their `retention.archiefactiedatum` extended by a configurable period
- **AND** the rejection reason MUST be recorded in the destruction list and audit trail

#### Scenario: Two-step approval for sensitive schemas
- **GIVEN** schema `bezwaarschriften` is configured with `archive.requireDualApproval: true`
- **AND** a destruction list contains objects from this schema
- **WHEN** the first archivist approves the list
- **THEN** the status MUST change to `awaiting_second_approval`
- **AND** a second archivist (different user from the first) MUST approve before destruction proceeds
- **AND** if the same archivist attempts the second approval, the system MUST return HTTP 409 Conflict

### Requirement: The DestructionExecutionJob MUST permanently delete approved objects in batches

A `QueuedJob` MUST process approved destruction lists by permanently deleting objects, their associated files, and creating audit trail entries.

#### Scenario: Batch destruction of approved objects
- **GIVEN** a destruction list `dl-001` is approved with 150 objects
- **WHEN** the `DestructionExecutionJob` runs
- **THEN** objects MUST be deleted in batches of 100 (configurable)
- **AND** for each object, `DeleteObject::delete()` MUST be called with `permanent: true`
- **AND** an audit trail entry MUST be created for each deletion with action `archival.destroyed`
- **AND** the audit trail entry MUST record: destruction list UUID, approving archivist, timestamp, selectielijst category

#### Scenario: File attachments permanently deleted during destruction
- **GIVEN** object `zaak-789` on an approved destruction list has 3 files stored in Nextcloud Files
- **WHEN** the `DestructionExecutionJob` processes `zaak-789`
- **THEN** all 3 associated files MUST be permanently deleted from Nextcloud Files storage
- **AND** the files MUST NOT be recoverable from Nextcloud's trash
- **AND** each file deletion MUST be logged in the audit trail with action `archival.file_destroyed`

#### Scenario: Legal hold placed between approval and execution halts object
- **GIVEN** destruction list `dl-001` is approved containing object `zaak-456`
- **AND** a legal hold is placed on `zaak-456` after approval but before `DestructionExecutionJob` runs
- **WHEN** the `DestructionExecutionJob` processes `zaak-456`
- **THEN** `zaak-456` MUST be skipped (not destroyed)
- **AND** the destruction list MUST be updated to note the skipped object with reason `legal_hold_placed_after_approval`
- **AND** all other objects on the list without holds MUST still be destroyed

#### Scenario: Cascade destruction follows referential integrity rules
- **GIVEN** schema `zaakdossier` has property `documenten` referencing `zaakdocument` with `onDelete: CASCADE`
- **AND** zaakdossier `zaak-789` on an approved destruction list has 5 linked zaakdocumenten
- **WHEN** the `DestructionExecutionJob` destroys `zaak-789`
- **THEN** all 5 zaakdocumenten MUST also be permanently destroyed
- **AND** each cascaded destruction MUST produce an audit trail entry with action `archival.cascade_destroyed`

### Requirement: The system MUST generate destruction certificates upon completed destruction

After all objects on an approved destruction list are destroyed, the system MUST generate an immutable destruction certificate (verklaring van vernietiging).

#### Scenario: Destruction certificate generated after full destruction
- **GIVEN** all 15 objects on destruction list `dl-001` have been permanently destroyed
- **WHEN** the `DestructionExecutionJob` completes
- **THEN** the system MUST create a destruction certificate as an immutable register object containing:
  - Date of destruction
  - Approving archivist(s) (including second approver if dual-approval)
  - Number of objects destroyed, grouped by schema and selectielijst category
  - Reference to the selectielijst version used
  - Total number of associated files destroyed
  - Statement of compliance with Archiefwet 1995
- **AND** the certificate MUST NOT be editable or deletable through any API endpoint
- **AND** the destruction list status MUST change to `completed`

#### Scenario: Destruction certificate for partial completion
- **GIVEN** destruction list `dl-001` had 15 objects approved but 2 were skipped due to legal holds
- **WHEN** the `DestructionExecutionJob` completes
- **THEN** the destruction certificate MUST record 13 objects destroyed and 2 skipped
- **AND** the skipped objects MUST be listed with their skip reason

### Requirement: The system MUST support legal holds that prevent destruction

Legal holds MUST be placeable on individual objects or all objects in a schema, preventing any destruction regardless of `archiefactiedatum`.

#### Scenario: Place legal hold on a single object
- **GIVEN** object `zaak-456` with `archiefactiedatum` in the past and `archiefnominatie` `vernietigen`
- **WHEN** an authorized user calls `POST /api/archival/legal-holds` with `{ "objectId": "zaak-456", "reason": "WOO-verzoek 2025-0142" }`
- **THEN** the object's `retention.legalHold` MUST be set to `{ "active": true, "reason": "WOO-verzoek 2025-0142", "placedBy": "<user-id>", "placedDate": "<ISO-8601>" }`
- **AND** the object MUST be excluded from all future destruction lists
- **AND** an audit trail entry MUST be created with action `archival.legal_hold_placed`

#### Scenario: Release legal hold
- **GIVEN** object `zaak-456` has an active legal hold
- **WHEN** an authorized user calls `DELETE /api/archival/legal-holds/{holdId}` with `{ "reason": "WOO-verzoek afgehandeld" }`
- **THEN** `retention.legalHold.active` MUST be set to `false`
- **AND** the hold MUST be preserved in `retention.legalHold.history[]` with release date and reason
- **AND** the object MUST become eligible for destruction again if `archiefactiedatum` has passed
- **AND** an audit trail entry MUST be created with action `archival.legal_hold_released`

#### Scenario: Bulk legal hold on schema
- **GIVEN** schema `subsidie-aanvragen` contains 200 objects
- **WHEN** an authorized user calls `POST /api/archival/legal-holds` with `{ "schemaId": "subsidie-aanvragen", "reason": "Rekenkameronderzoek 2026" }`
- **THEN** all 200 objects MUST receive a legal hold via a `QueuedJob` to avoid timeouts
- **AND** a single summary audit trail entry MUST be created for the bulk operation

#### Scenario: Legal hold prevents destruction even when on active destruction list
- **GIVEN** a destruction list containing object `zaak-456`
- **AND** a legal hold is placed on `zaak-456` after the list was created but before approval
- **WHEN** the archivist approves the destruction list
- **THEN** `zaak-456` MUST be automatically excluded from destruction
- **AND** the archivist MUST be notified that 1 object was excluded due to legal hold

### Requirement: The system MUST calculate archiefactiedatum using configurable afleidingswijzen

The `ArchiefactiedatumCalculator` service MUST support multiple derivation methods for calculating the archive action date.

#### Scenario: Calculate from case closure date (afgehandeld)
- **GIVEN** a zaakdossier mapped to selectielijst category B1 with `bewaartermijn: P5Y`
- **AND** `afleidingswijze` is set to `afgehandeld`
- **AND** the zaak is closed on 2026-03-01
- **WHEN** `ArchiefactiedatumCalculator::calculate()` is called
- **THEN** `archiefactiedatum` MUST be set to 2031-03-01 (closure date + 5 years)

#### Scenario: Calculate from a property value (eigenschap)
- **GIVEN** a vergunning with `afleidingswijze: eigenschap` pointing to property `vervaldatum`
- **AND** `vervaldatum` is 2028-06-15
- **AND** `bewaartermijn` is `P10Y`
- **WHEN** `ArchiefactiedatumCalculator::calculate()` is called
- **THEN** `archiefactiedatum` MUST be set to 2038-06-15

#### Scenario: Calculate with termijn method
- **GIVEN** a zaak with `afleidingswijze: termijn` and `procestermijn: P2Y`
- **AND** the zaak is closed on 2026-01-01
- **AND** `bewaartermijn` is `P5Y`
- **WHEN** `ArchiefactiedatumCalculator::calculate()` is called
- **THEN** the brondatum MUST be 2028-01-01 (closure + procestermijn)
- **AND** `archiefactiedatum` MUST be 2033-01-01 (brondatum + bewaartermijn)

#### Scenario: Recalculate when source property changes
- **GIVEN** a vergunning with `afleidingswijze: eigenschap` pointing to `vervaldatum`
- **AND** current `archiefactiedatum` is 2038-06-15
- **WHEN** `vervaldatum` is updated to 2030-12-31
- **THEN** `archiefactiedatum` MUST be recalculated to 2040-12-31
- **AND** the change MUST be logged in the audit trail

### Requirement: WOO-published objects MUST be flagged on destruction lists

Objects published under the Wet open overheid (WOO) MUST receive special handling during destruction workflows.

#### Scenario: WOO-published object flagged on destruction list
- **GIVEN** object `besluit-123` has been published via WOO
- **AND** `besluit-123` appears on a destruction list based on its `archiefactiedatum`
- **WHEN** the destruction list is generated
- **THEN** `besluit-123` MUST be flagged with label `woo_gepubliceerd`
- **AND** the archivist MUST explicitly confirm destruction of publicly accessible records before approval proceeds
