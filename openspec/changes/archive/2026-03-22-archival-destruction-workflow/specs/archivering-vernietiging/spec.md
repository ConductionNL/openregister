## MODIFIED Requirements

### Requirement: The system MUST support automated destruction scheduling via background jobs

Objects that have reached their `archiefactiedatum` with `archiefnominatie` set to `vernietigen` MUST be automatically identified and queued for destruction through a background job, following the pattern used by xxllnc Zaken for batch destruction processing.

#### Scenario: Generate destruction list via background job
- **GIVEN** 15 objects have `archiefactiedatum` before today and `archiefnominatie` set to `vernietigen`
- **AND** their `archiefstatus` is `nog_te_archiveren`
- **WHEN** the `DestructionCheckJob` (extending `OCP\BackgroundJob\TimedJob`) runs on its configurable schedule (default: daily)
- **THEN** a destruction list MUST be generated as a register object containing references to all 15 objects
- **AND** the destruction list MUST include for each object: title, schema, register, UUID, `archiefactiedatum`, selectielijst category
- **AND** the destruction list MUST be assigned a status of `in_review`
- **AND** an `INotification` MUST be sent to users with the archivist role

#### Scenario: Scheduled destruction respects soft-deleted objects
- **GIVEN** 3 of the 15 eligible objects have already been soft-deleted (have a `deleted` field set)
- **WHEN** the `DestructionCheckJob` generates the destruction list
- **THEN** the soft-deleted objects MUST still be included in the destruction list
- **AND** they MUST be clearly marked as already soft-deleted in the list

#### Scenario: Prevent duplicate destruction list generation
- **GIVEN** 10 objects are eligible for destruction
- **AND** a destruction list containing 8 of these objects already exists with status `in_review`
- **WHEN** the `DestructionCheckJob` runs again
- **THEN** only the 2 objects not already on an existing destruction list MUST be added to a new list
- **AND** the existing list MUST NOT be modified

#### Scenario: Configurable destruction check schedule
- **GIVEN** an admin wants destruction checks to run weekly instead of daily
- **WHEN** the admin updates the retention settings via `PUT /api/settings/retention` with `destructionCheckInterval` set to `604800` (7 days in seconds)
- **THEN** the `DestructionCheckJob` interval MUST be updated accordingly
- **AND** the setting MUST be persisted in the app configuration via `IAppConfig`

### Requirement: Destruction MUST follow a multi-step approval workflow

Destruction of objects MUST NOT occur automatically. A destruction list MUST be reviewed and approved by at least one authorized archivist before any objects are permanently deleted, conforming to Archiefbesluit 1995 Articles 6-8.

#### Scenario: Approve destruction list (full approval)
- **GIVEN** a destruction list with 15 objects and status `in_review`
- **WHEN** an archivist with the `archivaris` role approves the entire list
- **THEN** the destruction list status MUST change to `approved`
- **AND** the system MUST permanently delete all 15 objects via a `DestructionExecutionJob` (`QueuedJob`) to avoid timeouts
- **AND** an audit trail entry MUST be created for each deletion with action `archival.destroyed`
- **AND** the audit trail entry MUST record: destruction list UUID, approving archivist, timestamp, selectielijst category
- **AND** the destruction list itself MUST be retained permanently as an archival record (verklaring van vernietiging)

#### Scenario: Partially reject destruction list
- **GIVEN** a destruction list with 15 objects
- **WHEN** the archivist removes 3 objects from the list (marking them as `uitgezonderd`) and approves the remaining 12
- **THEN** only the 12 approved objects MUST be destroyed
- **AND** the 3 excluded objects MUST have their `archiefactiedatum` extended by a configurable period (default: 1 year)
- **AND** the exclusion reason MUST be recorded for each excluded object in `retention.exclusionHistory[]`
- **AND** the destruction list MUST record both the approved and excluded objects

#### Scenario: Reject entire destruction list
- **GIVEN** a destruction list with 15 objects
- **WHEN** the archivist rejects the entire list
- **THEN** no objects MUST be destroyed
- **AND** the destruction list status MUST change to `rejected`
- **AND** the archivist MUST provide a reason for rejection
- **AND** all objects on the list MUST have their `archiefactiedatum` extended by a configurable period

#### Scenario: Two-step approval for sensitive schemas
- **GIVEN** schema `bezwaarschriften` is configured with `archive.requireDualApproval: true`
- **AND** a destruction list contains objects from this schema
- **WHEN** the first archivist approves the list
- **THEN** the status MUST change to `awaiting_second_approval`
- **AND** a second archivist (different from the first) MUST approve before destruction proceeds

#### Scenario: Destruction certificate generation (verklaring van vernietiging)
- **GIVEN** a destruction list has been fully approved and all objects destroyed
- **WHEN** the destruction process completes
- **THEN** the system MUST generate a destruction certificate containing:
  - Date of destruction
  - Approving archivist(s)
  - Number of objects destroyed, grouped by schema and selectielijst category
  - Reference to the selectielijst used
  - Statement of compliance with Archiefwet 1995
- **AND** the certificate MUST be stored as an immutable object in the archival register

### Requirement: The system MUST support legal holds (bevriezing)

Objects under legal hold MUST be exempt from all destruction processes, regardless of their `archiefactiedatum` or `archiefnominatie`. Legal holds support litigation, WOB/WOO requests, and regulatory investigations.

#### Scenario: Place legal hold on an object
- **GIVEN** object `zaak-456` has `archiefactiedatum` of 2026-01-01 (in the past) and `archiefnominatie` `vernietigen`
- **WHEN** an authorized user places a legal hold with reason `WOO-verzoek 2025-0142`
- **THEN** the object's `retention` field MUST include `legalHold: { active: true, reason: "WOO-verzoek 2025-0142", placedBy: "user-id", placedDate: "2026-03-19T..." }`
- **AND** the object MUST be excluded from all destruction lists
- **AND** an audit trail entry MUST be created with action `archival.legal_hold_placed`

#### Scenario: Legal hold prevents destruction even when on destruction list
- **GIVEN** a destruction list containing object `zaak-456`
- **AND** a legal hold is placed on `zaak-456` after the destruction list was created but before approval
- **WHEN** the archivist approves the destruction list
- **THEN** `zaak-456` MUST be automatically excluded from destruction
- **AND** the archivist MUST be notified that 1 object was excluded due to legal hold

#### Scenario: Release legal hold
- **GIVEN** object `zaak-456` has an active legal hold
- **WHEN** an authorized user releases the legal hold with reason `WOO-verzoek afgehandeld`
- **THEN** the `legalHold.active` MUST be set to `false`
- **AND** the hold history MUST be preserved in `legalHold.history[]`
- **AND** the object MUST become eligible for destruction again if `archiefactiedatum` has passed
- **AND** an audit trail entry MUST be created with action `archival.legal_hold_released`

#### Scenario: Bulk legal hold on schema
- **GIVEN** schema `subsidie-aanvragen` contains 200 objects
- **WHEN** an authorized user places a legal hold on all objects in this schema with reason `Rekenkameronderzoek 2026`
- **THEN** all 200 objects MUST receive a legal hold
- **AND** the operation MUST be executed via `QueuedJob` to avoid timeouts
- **AND** a single audit trail entry MUST summarize the bulk operation

### Requirement: Cascading destruction MUST handle related objects

When an object is destroyed, the system MUST evaluate and handle related objects according to configurable cascade rules, integrating with the existing referential integrity system (see `deletion-audit-trail` spec).

#### Scenario: Cascade destruction to child objects
- **GIVEN** schema `zaakdossier` has a property `documenten` referencing schema `zaakdocument` with `onDelete: CASCADE`
- **AND** zaakdossier `zaak-789` has 5 linked zaakdocumenten
- **WHEN** `zaak-789` is destroyed via an approved destruction list
- **THEN** all 5 zaakdocumenten MUST also be destroyed
- **AND** each cascaded destruction MUST produce an audit trail entry with action `archival.cascade_destroyed`
- **AND** the audit trail entry MUST reference the original destruction list

#### Scenario: Cascade destruction blocked by RESTRICT
- **GIVEN** zaakdossier `zaak-789` references `klant-001` with `onDelete: RESTRICT`
- **WHEN** `zaak-789` appears on a destruction list
- **THEN** the destruction list MUST flag `zaak-789` with a warning that it has RESTRICT references
- **AND** the archivist MUST resolve the reference before approving destruction

#### Scenario: Cascade destruction with legal hold on child
- **GIVEN** zaakdossier `zaak-789` is approved for destruction
- **AND** one of its child zaakdocumenten has an active legal hold
- **WHEN** the destruction is executed
- **THEN** the system MUST halt destruction of the entire zaakdossier
- **AND** the archivist MUST be notified that destruction is blocked due to a legal hold on a child object

#### Scenario: Destruction of objects with file attachments
- **GIVEN** object `zaak-789` has 3 files stored in Nextcloud Files
- **WHEN** the object is destroyed via an approved destruction list
- **THEN** all associated files MUST also be permanently deleted from Nextcloud Files storage
- **AND** the file deletion MUST be logged in the audit trail with action `archival.file_destroyed`
- **AND** the files MUST NOT be recoverable from Nextcloud's trash
