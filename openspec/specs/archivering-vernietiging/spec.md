---
status: draft
---

# archivering-vernietiging Specification

## Purpose
Implement archiving and destruction lifecycle management for register objects, conforming to MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie), NEN 2082 records management, and e-Depot export standards. Objects MUST support retention schedules, automated destruction workflows, and transfer to digital archival systems.

**Tender demand**: 77% of analyzed government tenders require archiving and destruction capabilities.

## ADDED Requirements

### Requirement: Objects MUST support archival metadata (MDTO)
Each object MUST carry archival metadata fields conforming to the MDTO standard for durable access to government information.

#### Scenario: Archival metadata on object creation
- GIVEN a schema `zaakdossier` with archival metadata enabled
- WHEN a new zaakdossier object is created
- THEN the system MUST store the following archival metadata:
  - `archiefnominatie`: one of `vernietigen`, `bewaren`, `nog_niet_bepaald`
  - `archiefactiedatum`: the date on which the archival action MUST be taken
  - `archiefstatus`: one of `nog_te_archiveren`, `gearchiveerd`, `vernietigd`, `overgebracht`
  - `classificatie`: the selection list category code
- AND `archiefnominatie` MUST default to `nog_niet_bepaald` if not explicitly set

#### Scenario: Calculate archiefactiedatum from selection list
- GIVEN a zaakdossier with zaaktype `melding-openbare-ruimte` mapped to selection list category B1 (bewaartermijn: 5 jaar)
- AND the zaak is closed on 2026-03-01
- WHEN the system calculates archival dates
- THEN `archiefactiedatum` MUST be set to 2031-03-01
- AND `archiefnominatie` MUST be set to `vernietigen`

### Requirement: The system MUST support configurable selection lists (selectielijsten)
Administrators MUST be able to configure selection lists that map object types to retention periods and archival actions.

#### Scenario: Configure a selection list entry
- GIVEN an admin configuring archival rules
- WHEN they create a selection list entry with category `B1`, bewaartermijn `5 jaar`, and action `vernietigen`
- THEN all objects mapped to category B1 MUST use these retention rules

#### Scenario: Override selection list per schema
- GIVEN a default retention of 10 years for category A1
- AND schema `vertrouwelijk-dossier` requires 20 years retention
- WHEN the admin configures a schema-level override
- THEN objects in `vertrouwelijk-dossier` MUST use the 20-year retention

### Requirement: The system MUST support automated destruction workflows
Objects that have reached their archiefactiedatum with archiefnominatie `vernietigen` MUST be processed through a destruction workflow with approval steps.

#### Scenario: Generate destruction list
- GIVEN 15 objects have archiefactiedatum before today and archiefnominatie `vernietigen`
- WHEN the scheduled destruction check runs
- THEN a destruction list MUST be generated containing all 15 objects
- AND the list MUST be assigned to an archivist for review
- AND the list MUST include object title, schema, register, and archiefactiedatum

#### Scenario: Approve destruction list
- GIVEN a destruction list with 15 objects pending approval
- WHEN the archivist approves the list
- THEN the system MUST permanently delete all 15 objects
- AND an audit trail entry MUST be created for each deletion with action `archival.destroyed`
- AND the destruction list itself MUST be retained as an archival record

#### Scenario: Partially reject destruction list
- GIVEN a destruction list with 15 objects
- WHEN the archivist removes 3 objects from the list and approves the remaining 12
- THEN only the 12 approved objects MUST be destroyed
- AND the 3 excluded objects MUST have their archiefactiedatum extended or archiefnominatie changed

### Requirement: The system MUST support e-Depot export (transfer/overbrenging)
Objects with archiefnominatie `bewaren` MUST be exportable to external e-Depot systems in a standardized format.

#### Scenario: Export objects to e-Depot
- GIVEN 5 objects with archiefnominatie `bewaren` and archiefactiedatum reached
- WHEN the archivist initiates e-Depot transfer
- THEN the system MUST generate a SIP (Submission Information Package) containing:
  - Object metadata in MDTO XML format
  - Associated documents (from Nextcloud Files)
  - Structural metadata describing relationships
- AND the SIP MUST be transmitted to the configured e-Depot endpoint
- AND upon successful transfer, objects MUST be marked with archiefstatus `overgebracht`

#### Scenario: e-Depot transfer failure
- GIVEN an e-Depot transfer is initiated for 5 objects
- WHEN the e-Depot system returns an error for 2 objects
- THEN only the 3 successful objects MUST be marked as `overgebracht`
- AND the 2 failed objects MUST remain in status `nog_te_archiveren`
- AND the admin MUST be notified of the partial failure

### Requirement: NEN 2082 compliance MUST be verifiable
The system MUST support generating a NEN 2082 compliance report showing which requirements are met.

#### Scenario: Generate compliance report
- GIVEN the system is configured with archival metadata, selection lists, and destruction workflows
- WHEN an admin requests a NEN 2082 compliance report
- THEN the report MUST list each NEN 2082 requirement and its implementation status
- AND the report MUST identify gaps with remediation guidance

### Current Implementation Status
- **Phase 1 IMPLEMENTED (2026-03-25):**
  - Archival metadata stored in `ObjectEntity.retention` JSON field (archiefnominatie, archiefactiedatum, archiefstatus, classificatie)
  - `SelectionList` entity and mapper for configurable retention rules (selectielijsten)
  - `DestructionList` entity and mapper with approval workflow (pending_review -> approved -> completed)
  - `ArchivalService` with validation, date calculation, destruction list generation/approval/rejection
  - `ArchivalController` with full API: selection list CRUD, retention metadata GET/PUT, destruction list endpoints
  - `DestructionCheckJob` daily background job for automated destruction scanning
  - Audit trail integration via `AuditTrailMapper.createAuditTrail()` with action `archival.destroyed`
  - Database migration `Version1Date20260325120000` creating two new tables
  - 48 unit tests across 5 test files
- **NOT YET implemented (future phases):**
  - No e-Depot export (SIP generation, MDTO XML)
  - No NEN 2082 compliance reporting
  - No integration with external archival systems

### Standards & References
- **MDTO** (Metagegevens Duurzaam Toegankelijke Overheidsinformatie) — Dutch standard for archival metadata
- **NEN 2082** — Dutch records management standard (functionality requirements for record-keeping)
- **Selectielijst gemeenten en intergemeentelijke organen** — VNG selection list for retention periods
- **e-Depot / Nationaal Archief** — SIP (Submission Information Package) format per OAIS reference model
- **Archiefwet 1995** and **Archiefbesluit 1995** — Dutch archival law
- **OAIS (ISO 14721)** — Open Archival Information System reference model
- **TMLO** (Toepassingsprofiel Metadatering Lokale Overheden) — predecessor to MDTO

### Specificity Assessment
- The spec provides good scenario coverage for the happy path but lacks detail on several implementation aspects.
- Missing: schema/entity definitions for destruction lists, selection list entries, and e-Depot configuration; API endpoint definitions; background job scheduling for automated destruction checks.
- Ambiguous: how archival metadata integrates with existing schema property definitions (separate entity vs. JSON Schema properties vs. dedicated fields on ObjectEntity).
- Open questions:
  - Which e-Depot systems should be supported initially (Nationaal Archief, regional archives)?
  - Should the destruction approval workflow use Nextcloud's built-in approval features or a custom implementation?
  - How does this interact with the existing audit trail — should archival actions create standard AuditTrail entries or a separate archival log?

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No archival metadata fields, selection lists, destruction workflows, or e-Depot export capabilities exist. The audit trail and object model provide partial foundations.

**Nextcloud Core Interfaces**:
- `TimedJob` (`OCP\BackgroundJob\TimedJob`): Schedule a `DestructionCheckJob` that runs daily (or weekly), scanning objects where `archiefactiedatum <= today` and `archiefnominatie = vernietigen`. The job generates destruction lists for archivist review and sends notifications.
- `INotifier` / `INotification`: Send retention warnings to archivists when objects approach their `archiefactiedatum` (e.g., 30 days before). Notify on destruction list creation and e-Depot transfer results (success/partial failure).
- `AuditTrail` (OpenRegister's `AuditTrailMapper`): Log destruction actions with type `archival.destroyed`, including the destruction list reference, approving archivist, and timestamp. Log e-Depot transfers with type `archival.transferred`. These entries provide the legally required evidence trail.
- `ITrashManager` patterns: Follow Nextcloud's trash/soft-delete patterns for the destruction workflow. Objects marked for destruction enter a "pending destruction" state (similar to trash) with an approval gate before permanent deletion. This prevents accidental data loss.

**Implementation Approach**:
- Add archival metadata as schema-level configuration or dedicated properties on `ObjectEntity`. The fields `archiefnominatie`, `archiefactiedatum`, `archiefstatus`, and `classificatie` can be modeled as standard schema properties with enum validation, or as system-level fields on the object entity itself (similar to `dateCreated`/`dateModified`).
- Model selection lists (selectielijsten) as a dedicated OpenRegister schema or admin configuration. Each entry maps a classification code to a retention period and archival action. Schema-level overrides are stored as schema metadata.
- Implement the destruction workflow as a multi-step process: (1) `DestructionCheckJob` generates a destruction list as a register object; (2) Archivist reviews and approves/rejects items via the UI; (3) Approved items are permanently deleted via `ObjectService::deleteObject()` with audit logging.
- For e-Depot export, create an `EDepotExportService` that generates MDTO XML metadata and packages objects with their associated Nextcloud Files into a SIP (Submission Information Package) following the OAIS model. Transmission to the e-Depot endpoint uses OpenConnector or direct HTTP.
- Use `QueuedJob` for large-scale destruction and e-Depot transfers to avoid timeout issues.

**Dependencies on Existing OpenRegister Features**:
- `ObjectService` — CRUD and deletion of objects with audit trail logging.
- `AuditTrailMapper` — immutable logging of archival actions (destruction, transfer).
- `SchemaService` — schema property definitions for archival metadata fields.
- `ExportHandler` — foundation for e-Depot SIP package generation (needs MDTO XML extension).
- `FileService` — retrieval of associated documents for inclusion in SIP packages.
## Requirements
### Requirement: Archival metadata on objects via retention field
Objects MUST store archival metadata in the existing `retention` JSON field with MDTO-conformant keys.

#### Scenario: Set archival metadata
- GIVEN an object in register `zaakregister`
- WHEN archival metadata is set via `PUT /api/archival/objects/{id}/retention`
- THEN the `retention` field MUST contain:
  - `archiefnominatie`: one of `vernietigen`, `bewaren`, `nog_niet_bepaald`
  - `archiefactiedatum`: ISO 8601 date for the archival action
  - `archiefstatus`: one of `nog_te_archiveren`, `gearchiveerd`, `vernietigd`, `overgebracht`
  - `classificatie`: selection list category code
- AND `archiefnominatie` defaults to `nog_niet_bepaald` if not set

#### Scenario: Calculate archiefactiedatum from selection list
- GIVEN a selection list entry with category `B1`, bewaartermijn 5 years, action `vernietigen`
- AND an object with classificatie `B1` and a close date of 2026-03-01
- WHEN the system calculates archival dates
- THEN `archiefactiedatum` MUST be 2031-03-01
- AND `archiefnominatie` MUST be `vernietigen`

### Requirement: Selection list (selectielijst) CRUD
Administrators MUST be able to manage selection list entries that map categories to retention rules.

#### Scenario: CRUD selection list entries
- GIVEN an admin user
- WHEN they POST to `/api/archival/selection-lists` with `{ "category": "B1", "retentionYears": 5, "action": "vernietigen", "description": "Korte bewaartermijn" }`
- THEN a selection list entry is created
- AND it is retrievable via GET, updatable via PUT, deletable via DELETE

#### Scenario: Schema-level override
- GIVEN a default retention of 10 years for category A1
- AND a schema override setting 20 years for schema `vertrouwelijk-dossier`
- WHEN retention is calculated for objects in that schema
- THEN 20 years MUST be used instead of 10

### Requirement: Destruction list generation and approval
Objects past their archiefactiedatum with archiefnominatie `vernietigen` MUST be processable through a destruction workflow.

#### Scenario: Generate destruction list
- GIVEN 15 objects with archiefactiedatum before today and archiefnominatie `vernietigen`
- WHEN `POST /api/archival/destruction-lists/generate` is called
- THEN a destruction list MUST be created containing all 15 object references
- AND the list status is `pending_review`

#### Scenario: Approve destruction list
- GIVEN a destruction list with status `pending_review`
- WHEN an archivist calls `POST /api/archival/destruction-lists/{id}/approve`
- THEN all objects in the list MUST be permanently deleted
- AND audit trail entries with action `archival.destroyed` MUST be created
- AND the destruction list status changes to `completed`
- AND the destruction list itself is retained as an archival record

#### Scenario: Reject items from destruction list
- GIVEN a destruction list with 15 objects
- WHEN the archivist calls `POST /api/archival/destruction-lists/{id}/reject` with 3 object IDs
- THEN those 3 objects are removed from the list
- AND their archiefactiedatum is extended by the original retention period

### Requirement: Background destruction check job
A TimedJob MUST run daily to identify objects due for destruction and generate destruction lists.

#### Scenario: Scheduled destruction check
- GIVEN objects with archiefactiedatum <= today and archiefnominatie `vernietigen` and archiefstatus `nog_te_archiveren`
- WHEN the DestructionCheckJob runs
- THEN a destruction list is generated for review
- AND a notification is sent to users with archival management permissions

### Requirement: Audit trail for archival actions
All archival actions MUST be logged in the audit trail.

#### Scenario: Destruction audit trail
- GIVEN an approved destruction list
- WHEN objects are destroyed
- THEN each deletion creates an audit trail entry with:
  - action: `archival.destroyed`
  - metadata: destruction list ID, approving user, timestamp

### Requirement: Objects MUST carry MDTO-compliant archival metadata
Each object MUST carry archival metadata fields conforming to the MDTO standard (Metagegevens Duurzaam Toegankelijke Overheidsinformatie), ensuring durable accessibility and legal compliance with the Archiefwet 1995 Article 3. These fields MUST be stored in the object's `retention` property and exposed via the API.

#### Scenario: Archival metadata populated on object creation
- **GIVEN** a schema `zaakdossier` with archival metadata enabled via the schema's `archive` configuration
- **WHEN** a new zaakdossier object is created
- **THEN** the system MUST store the following archival metadata in the object's `retention` field:
  - `archiefnominatie`: one of `vernietigen`, `bewaren`, `nog_niet_bepaald`
  - `archiefactiedatum`: the ISO 8601 date on which the archival action MUST be taken
  - `archiefstatus`: one of `nog_te_archiveren`, `gearchiveerd`, `vernietigd`, `overgebracht`
  - `classificatie`: the selectielijst category code (e.g., `1.1`, `B1`)
  - `bewaartermijn`: the retention period in ISO 8601 duration format (e.g., `P5Y`, `P20Y`)
- **AND** `archiefnominatie` MUST default to `nog_niet_bepaald` if not explicitly set
- **AND** `archiefstatus` MUST default to `nog_te_archiveren`

#### Scenario: Archival metadata defaults from schema archive configuration
- **GIVEN** schema `vergunning-aanvraag` has `archive.defaultNominatie` set to `bewaren` and `archive.defaultBewaartermijn` set to `P20Y`
- **WHEN** a new object is created in this schema without explicit archival metadata
- **THEN** `archiefnominatie` MUST be set to `bewaren`
- **AND** `bewaartermijn` MUST be set to `P20Y`
- **AND** `archiefactiedatum` MUST be calculated as the object's creation date plus 20 years

#### Scenario: Archival metadata validation on update
- **GIVEN** an object with `archiefstatus` set to `vernietigd`
- **WHEN** a user attempts to update the object's data
- **THEN** the system MUST reject the update with HTTP 409 Conflict
- **AND** the response MUST indicate that destroyed objects cannot be modified

#### Scenario: Archival metadata exposed in API responses
- **GIVEN** an object `zaak-123` with archival metadata populated
- **WHEN** the object is retrieved via `GET /api/objects/{register}/{schema}/{id}`
- **THEN** the response MUST include the `retention` field containing all MDTO archival metadata
- **AND** the `retention` field MUST be filterable in search queries (e.g., `retention.archiefnominatie=vernietigen`)

#### Scenario: MDTO XML export of archival metadata
- **GIVEN** an object with complete archival metadata
- **WHEN** the object is exported in MDTO format
- **THEN** the export MUST produce valid XML conforming to the MDTO schema (version 1.0 or later)
- **AND** the XML MUST include mandatory MDTO elements: `identificatie`, `naam`, `waardering`, `bewaartermijn`, `informatiecategorie`

### Requirement: The system MUST support configurable selectielijsten (selection lists)
Administrators MUST be able to configure selectielijsten that map object types or zaaktypen to retention periods and archival actions, conforming to the Selectielijst gemeenten en intergemeentelijke organen (VNG) or custom organisational selection lists. Selectielijsten MUST be manageable as register objects within OpenRegister itself.

#### Scenario: Configure a selectielijst entry
- **GIVEN** an admin configuring archival rules in a register designated for selectielijst management
- **WHEN** they create a selectielijst entry with:
  - `categorie`: `B1`
  - `omschrijving`: `Vergunningen met beperkte looptijd`
  - `bewaartermijn`: `P5Y`
  - `archiefnominatie`: `vernietigen`
  - `bron`: `Selectielijst gemeenten 2020`
  - `toelichting`: `Na verloop van de vergunning`
- **THEN** all objects mapped to category B1 MUST use these retention rules when their `archiefactiedatum` is calculated

#### Scenario: Import VNG selectielijst
- **GIVEN** the VNG publishes an updated selectielijst for gemeenten
- **WHEN** an admin imports the selectielijst via CSV or JSON upload
- **THEN** all categories MUST be created as objects in the selectielijst register
- **AND** existing categories MUST be updated (not duplicated) based on their `categorie` code
- **AND** the import MUST log how many entries were created, updated, and skipped

#### Scenario: Override selectielijst per schema
- **GIVEN** a default retention of 10 years for selectielijst category `A1`
- **AND** schema `vertrouwelijk-dossier` requires 20 years retention due to organisational policy
- **WHEN** the admin configures a schema-level override in the schema's `archive` property
- **THEN** objects in `vertrouwelijk-dossier` MUST use the 20-year retention period
- **AND** the override MUST be recorded in the audit trail with the reason for deviation

#### Scenario: Selectielijst version management
- **GIVEN** the VNG publishes a new version of the selectielijst (e.g., 2025 edition replacing 2020 edition)
- **WHEN** the admin activates the new selectielijst version
- **THEN** existing objects MUST retain their original selectielijst reference (no retroactive changes)
- **AND** new objects MUST use the new selectielijst version
- **AND** the admin MUST be able to run a report showing objects under the old vs. new selectielijst

### Requirement: The system MUST calculate archiefactiedatum using configurable afleidingswijzen
The archiefactiedatum (archive action date) MUST be calculable from multiple derivation methods (afleidingswijzen) as defined by the ZGW API standard, supporting at minimum the methods used by OpenZaak.

#### Scenario: Calculate archiefactiedatum from case closure date (afgehandeld)
- **GIVEN** a zaakdossier with zaaktype `melding-openbare-ruimte` mapped to selectielijst category B1 (bewaartermijn: 5 jaar)
- **AND** afleidingswijze is set to `afgehandeld`
- **AND** the zaak is closed on 2026-03-01
- **WHEN** the system calculates archival dates
- **THEN** `archiefactiedatum` MUST be set to 2031-03-01 (closure date + 5 years)
- **AND** `archiefnominatie` MUST be set to `vernietigen`

#### Scenario: Calculate archiefactiedatum from a property value (eigenschap)
- **GIVEN** a vergunning with afleidingswijze `eigenschap` pointing to property `vervaldatum`
- **AND** the vergunning has `vervaldatum` set to 2028-06-15
- **AND** the selectielijst specifies bewaartermijn `P10Y`
- **WHEN** the system calculates archival dates
- **THEN** `archiefactiedatum` MUST be set to 2038-06-15 (vervaldatum + 10 years)

#### Scenario: Calculate archiefactiedatum with termijn method
- **GIVEN** a zaak with afleidingswijze `termijn` and procestermijn `P2Y`
- **AND** the zaak is closed on 2026-01-01
- **AND** the selectielijst specifies bewaartermijn `P5Y`
- **WHEN** the system calculates archival dates
- **THEN** the brondatum MUST be 2028-01-01 (closure + procestermijn)
- **AND** `archiefactiedatum` MUST be 2033-01-01 (brondatum + bewaartermijn)

#### Scenario: Recalculate archiefactiedatum when source data changes
- **GIVEN** a vergunning with afleidingswijze `eigenschap` pointing to `vervaldatum`
- **AND** current `archiefactiedatum` is 2038-06-15
- **WHEN** the `vervaldatum` property is updated to 2030-12-31
- **THEN** `archiefactiedatum` MUST be recalculated to 2040-12-31
- **AND** the change MUST be logged in the audit trail

### Requirement: The system MUST support automated destruction scheduling via background jobs
Objects that have reached their `archiefactiedatum` with `archiefnominatie` set to `vernietigen` MUST be automatically identified and queued for destruction through a background job, following the pattern used by xxllnc Zaken for batch destruction processing.

#### Scenario: Generate destruction list via background job
- **GIVEN** 15 objects have `archiefactiedatum` before today and `archiefnominatie` set to `vernietigen`
- **AND** their `archiefstatus` is `nog_te_archiveren`
- **WHEN** the `DestructionCheckJob` (extending `OCP\BackgroundJob\TimedJob`) runs on its daily schedule
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
- **WHEN** the admin updates the retention settings via `PUT /api/settings/retention`
- **THEN** the `DestructionCheckJob` interval MUST be updated accordingly
- **AND** the setting MUST be persisted in the app configuration

### Requirement: Destruction MUST follow a multi-step approval workflow
Destruction of objects MUST NOT occur automatically. A destruction list MUST be reviewed and approved by at least one authorized archivist before any objects are permanently deleted, conforming to Archiefbesluit 1995 Articles 6-8.

#### Scenario: Approve destruction list (full approval)
- **GIVEN** a destruction list with 15 objects and status `in_review`
- **WHEN** an archivist with the `archivaris` role approves the entire list
- **THEN** the destruction list status MUST change to `approved`
- **AND** the system MUST permanently delete all 15 objects using `ObjectService::deleteObject()` via a `QueuedJob` to avoid timeouts
- **AND** an audit trail entry MUST be created for each deletion with action `archival.destroyed`
- **AND** the audit trail entry MUST record: destruction list UUID, approving archivist, timestamp, selectielijst category
- **AND** the destruction list itself MUST be retained permanently as an archival record (verklaring van vernietiging)

#### Scenario: Partially reject destruction list
- **GIVEN** a destruction list with 15 objects
- **WHEN** the archivist removes 3 objects from the list (marking them as `uitgezonderd`) and approves the remaining 12
- **THEN** only the 12 approved objects MUST be destroyed
- **AND** the 3 excluded objects MUST have their `archiefactiedatum` extended by a configurable period (default: 1 year)
- **AND** the exclusion reason MUST be recorded for each excluded object
- **AND** the destruction list MUST record both the approved and excluded objects

#### Scenario: Reject entire destruction list
- **GIVEN** a destruction list with 15 objects
- **WHEN** the archivist rejects the entire list
- **THEN** no objects MUST be destroyed
- **AND** the destruction list status MUST change to `rejected`
- **AND** the archivist MUST provide a reason for rejection
- **AND** all objects on the list MUST have their `archiefactiedatum` extended by a configurable period

#### Scenario: Two-step approval for sensitive schemas
- **GIVEN** schema `bezwaarschriften` is configured to require two-step destruction approval
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

### Requirement: The system MUST support e-Depot export (overbrenging)
Objects with `archiefnominatie` set to `bewaren` that have reached their `archiefactiedatum` MUST be exportable to external e-Depot systems in a standardized SIP (Submission Information Package) format, conforming to the OAIS reference model (ISO 14721) and MDTO metadata standard.

#### Scenario: Export objects to e-Depot as SIP package
- **GIVEN** 5 objects with `archiefnominatie` `bewaren` and `archiefactiedatum` reached
- **WHEN** the archivist initiates e-Depot transfer
- **THEN** the system MUST generate a SIP (Submission Information Package) containing:
  - Object metadata in MDTO XML format per object
  - Associated documents from Nextcloud Files (original format plus PDF/A rendition if available)
  - A `mets.xml` structural metadata file describing the package hierarchy
  - A `premis.xml` preservation metadata file with fixity checksums (SHA-256)
  - A `sip-manifest.json` listing all files with checksums
- **AND** the SIP MUST be structured following the e-Depot specification of the target archive
- **AND** the SIP MUST be transmittable via the configured e-Depot endpoint (SFTP, REST API, or OpenConnector source)

#### Scenario: Successful e-Depot transfer
- **GIVEN** a SIP package for 5 objects is transmitted to the e-Depot
- **WHEN** the e-Depot confirms receipt and acceptance
- **THEN** all 5 objects MUST have their `archiefstatus` updated to `overgebracht`
- **AND** each object MUST store the e-Depot reference identifier in `retention.eDepotReferentie`
- **AND** an audit trail entry MUST be created for each object with action `archival.transferred`
- **AND** the objects MUST become read-only in OpenRegister (no further modifications allowed)

#### Scenario: e-Depot transfer failure (partial)
- **GIVEN** an e-Depot transfer is initiated for 5 objects
- **WHEN** the e-Depot system accepts 3 objects but rejects 2 (e.g., metadata validation errors)
- **THEN** only the 3 accepted objects MUST be marked as `overgebracht`
- **AND** the 2 rejected objects MUST remain in status `nog_te_archiveren`
- **AND** the rejection reasons MUST be stored per object in `retention.transferErrors[]`
- **AND** an `INotification` MUST be sent to the archivist with details of the partial failure

#### Scenario: Configure e-Depot endpoint
- **GIVEN** an admin configuring the e-Depot connection
- **WHEN** they set the e-Depot endpoint via `PUT /api/settings/edepot` with:
  - `endpointUrl`: the e-Depot API or SFTP address
  - `authenticationType`: `api_key`, `certificate`, or `oauth2`
  - `targetArchive`: identifier of the receiving archive (e.g., `regionaal-archief-leiden`)
  - `sipProfile`: the SIP profile to use (e.g., `nationaal-archief-v2`, `tresoar-v1`)
- **THEN** the configuration MUST be validated by performing a test connection
- **AND** the configuration MUST be stored securely in the app configuration

#### Scenario: e-Depot transfer via OpenConnector
- **GIVEN** an OpenConnector source is configured for the e-Depot endpoint
- **WHEN** the archivist initiates e-Depot transfer
- **THEN** the system MUST use the OpenConnector synchronization mechanism to transmit the SIP
- **AND** the transfer status MUST be tracked via OpenConnector's call log

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

### Requirement: WOO-published objects MUST have special destruction rules
Objects that have been published under the Wet open overheid (WOO) MUST follow additional rules before destruction, as public records carry extended transparency obligations.

#### Scenario: WOO-published object on destruction list
- **GIVEN** object `besluit-123` has been published via the WOO publication mechanism
- **AND** `besluit-123` appears on a destruction list based on its `archiefactiedatum`
- **WHEN** the destruction list is generated
- **THEN** `besluit-123` MUST be flagged with label `woo_gepubliceerd`
- **AND** the archivist MUST explicitly confirm that destruction of a publicly accessible record is appropriate
- **AND** the public-facing copy (if hosted externally) MUST be deregistered before destruction

#### Scenario: WOO publication extends effective retention
- **GIVEN** an object with `archiefactiedatum` of 2026-01-01 was published under WOO on 2025-12-01
- **AND** the organisation policy requires WOO-published records to remain accessible for at least 5 years from publication
- **WHEN** the `DestructionCheckJob` evaluates this object
- **THEN** the effective `archiefactiedatum` MUST be extended to 2030-12-01
- **AND** the original `archiefactiedatum` MUST be preserved in `retention.originalArchiefactiedatum`

#### Scenario: WOO-published object excluded from bulk destruction
- **GIVEN** a destruction list of 20 objects, 3 of which are WOO-published
- **WHEN** the archivist uses the "exclude WOO publications" filter
- **THEN** the 3 WOO-published objects MUST be automatically excluded from the destruction list
- **AND** their exclusion reason MUST be recorded as `woo_publicatie`

### Requirement: The system MUST provide notification before destruction
Objects approaching their `archiefactiedatum` MUST trigger notifications to relevant stakeholders, giving them time to review, extend, or apply legal holds.

#### Scenario: Pre-destruction notification (30 days)
- **GIVEN** object `zaak-100` has `archiefactiedatum` of 2026-04-18 and `archiefnominatie` `vernietigen`
- **AND** the notification lead time is configured to 30 days
- **WHEN** today is 2026-03-19
- **THEN** an `INotification` MUST be sent to users with the archivist role
- **AND** the notification MUST include: object title, schema, `archiefactiedatum`, selectielijst category
- **AND** the notification MUST link directly to the object in the OpenRegister UI

#### Scenario: Notification for objects with bewaren nominatie
- **GIVEN** object `monumentdossier-5` has `archiefactiedatum` of 2026-04-18 and `archiefnominatie` `bewaren`
- **WHEN** the pre-destruction notification period is reached
- **THEN** the notification MUST indicate that the object requires e-Depot transfer, not destruction
- **AND** the notification title MUST clearly distinguish between `vernietigen` and `bewaren` actions

#### Scenario: Configurable notification lead times per schema
- **GIVEN** schema `bezwaarschriften` requires 90 days advance notice
- **AND** the global default is 30 days
- **WHEN** the admin configures `archive.notificationLeadDays: 90` on the schema
- **THEN** objects in `bezwaarschriften` MUST receive notifications 90 days before `archiefactiedatum`

### Requirement: The system MUST support bulk archival operations
Administrators MUST be able to perform archival operations (set nominatie, update bewaartermijn, generate destruction lists) on multiple objects simultaneously.

#### Scenario: Bulk update archiefnominatie
- **GIVEN** 50 objects in schema `meldingen` currently have `archiefnominatie` set to `nog_niet_bepaald`
- **WHEN** the admin selects all 50 objects and sets `archiefnominatie` to `vernietigen` with selectielijst category `B1`
- **THEN** all 50 objects MUST be updated with the new nominatie and category
- **AND** the `archiefactiedatum` MUST be calculated for each object based on the selectielijst entry
- **AND** the bulk operation MUST be executed via `QueuedJob` if the count exceeds 100 objects
- **AND** a summary audit trail entry MUST record the bulk operation

#### Scenario: Bulk extend archiefactiedatum
- **GIVEN** 30 objects are approaching their `archiefactiedatum`
- **AND** a policy change requires extending retention by 2 years
- **WHEN** the admin selects the 30 objects and extends their `archiefactiedatum` by `P2Y`
- **THEN** all 30 objects MUST have their `archiefactiedatum` extended by 2 years
- **AND** each object MUST retain its original `archiefactiedatum` in `retention.originalArchiefactiedatum`

#### Scenario: Bulk set from selectielijst mapping
- **GIVEN** a new selectielijst mapping is configured that maps schema `vergunningen` to category `A1` (bewaren, P20Y)
- **WHEN** the admin applies the mapping to all existing objects in `vergunningen`
- **THEN** all objects MUST receive the updated archival metadata
- **AND** objects that already have a manually set `archiefnominatie` MUST NOT be overwritten (manual takes precedence)
- **AND** a report MUST show how many objects were updated vs. skipped

### Requirement: Retention period calculation MUST account for suspension and extension
When objects represent cases (zaken) that support opschorting (suspension) and verlenging (extension), the retention period calculation MUST account for the time the case was suspended.

#### Scenario: Retention with suspended case
- **GIVEN** a zaak closed on 2026-03-01 with bewaartermijn `P5Y`
- **AND** the zaak was suspended (opgeschort) for 60 days during its lifecycle
- **WHEN** the system calculates `archiefactiedatum`
- **THEN** the `archiefactiedatum` MUST be 2031-04-30 (closure date + 5 years + 60 days suspension)

#### Scenario: Retention with extended case
- **GIVEN** a zaak with doorlooptijd of 8 weeks that was extended by 4 weeks
- **AND** bewaartermijn `P1Y` with afleidingswijze `afgehandeld`
- **WHEN** the zaak is closed and the system calculates `archiefactiedatum`
- **THEN** the extension period MUST NOT affect the retention calculation (retention starts from actual closure)
- **AND** `archiefactiedatum` MUST be closure date + 1 year

#### Scenario: Manually set archiefactiedatum overrides calculation
- **GIVEN** the system calculates `archiefactiedatum` as 2031-03-01
- **WHEN** an authorized archivist manually sets `archiefactiedatum` to 2035-03-01 with reason `Verlengd op verzoek gemeentesecretaris`
- **THEN** the manual date MUST take precedence over the calculated date
- **AND** the override MUST be recorded in the audit trail with the archivist's reason

### Requirement: All destruction actions MUST produce immutable audit trail entries
Every archival lifecycle action MUST be recorded in the existing AuditTrail system (see `audit-trail-immutable` spec) with specific action types for archival operations.

#### Scenario: Audit trail for destruction
- **GIVEN** object `zaak-789` is destroyed via an approved destruction list
- **WHEN** the destruction is executed
- **THEN** an AuditTrail entry MUST be created with:
  - `action`: `archival.destroyed`
  - `objectUuid`: UUID of `zaak-789`
  - `changed`: containing `destructionListUuid`, `approvedBy`, `selectielijstCategorie`, `archiefactiedatum`
- **AND** the entry MUST be chained in the hash chain (if hash chaining is implemented)

#### Scenario: Audit trail for e-Depot transfer
- **GIVEN** object `monumentdossier-5` is transferred to the e-Depot
- **WHEN** the transfer completes successfully
- **THEN** an AuditTrail entry MUST be created with:
  - `action`: `archival.transferred`
  - `changed`: containing `eDepotReferentie`, `sipPackageId`, `targetArchive`

#### Scenario: Audit trail for legal hold
- **GIVEN** a legal hold is placed on object `zaak-456`
- **WHEN** the hold is placed
- **THEN** an AuditTrail entry MUST be created with:
  - `action`: `archival.legal_hold_placed`
  - `changed`: containing `reason`, `placedBy`, `placedDate`

#### Scenario: Audit trail for archiefnominatie change
- **GIVEN** an archivist changes the `archiefnominatie` of object `zaak-100` from `vernietigen` to `bewaren`
- **WHEN** the change is saved
- **THEN** an AuditTrail entry MUST be created with:
  - `action`: `archival.nominatie_changed`
  - `changed`: `{"archiefnominatie": {"old": "vernietigen", "new": "bewaren"}, "reason": "..."}`

#### Scenario: Audit trail retention for archival entries
- **GIVEN** an audit trail entry with action `archival.destroyed`
- **WHEN** the system evaluates audit trail retention
- **THEN** archival audit trail entries MUST have a minimum retention of 10 years, regardless of the `deleteLogRetention` setting
- **AND** audit entries for `archival.transferred` MUST be retained permanently

### Requirement: NEN-ISO 16175-1:2020 compliance MUST be verifiable
The system MUST support generating a compliance report showing which requirements of NEN-ISO 16175-1:2020 (the successor to NEN 2082) are met, enabling organisations to demonstrate archival compliance to auditors and oversight bodies.

#### Scenario: Generate compliance report
- **GIVEN** the system is configured with archival metadata, selectielijsten, and destruction workflows
- **WHEN** an admin requests a NEN-ISO 16175-1:2020 compliance report
- **THEN** the report MUST list each requirement category and its implementation status:
  - Records capture and registration
  - Records classification and retention
  - Access and security controls
  - Disposition (destruction and transfer)
  - Metadata management
  - Audit trail and accountability
- **AND** the report MUST identify gaps with remediation guidance

#### Scenario: Export compliance evidence
- **GIVEN** a compliance report has been generated
- **WHEN** the admin exports the report
- **THEN** the export MUST include supporting evidence:
  - Sample audit trail entries demonstrating immutability
  - Configuration of selectielijsten with version references
  - List of completed destruction certificates
  - e-Depot transfer confirmations
- **AND** the export format MUST be PDF or structured JSON

#### Scenario: Compliance dashboard widget
- **GIVEN** the admin navigates to the OpenRegister dashboard
- **WHEN** the archival compliance widget is displayed
- **THEN** the widget MUST show:
  - Number of objects pending destruction (overdue archiefactiedatum)
  - Number of objects pending e-Depot transfer
  - Number of active legal holds
  - Number of objects with `archiefnominatie` `nog_niet_bepaald`
  - Last destruction certificate date
  - Compliance score percentage

