## MODIFIED Requirements

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
- **AND** if a selectielijst mapping exists for the schema's `archive.classificatie`, the system MUST apply the selectielijst entry's `bewaartermijn` and `archiefnominatie` automatically

#### Scenario: Archival metadata defaults from schema archive configuration
- **GIVEN** schema `vergunning-aanvraag` has `archive.defaultNominatie` set to `bewaren` and `archive.defaultBewaartermijn` set to `P20Y`
- **WHEN** a new object is created in this schema without explicit archival metadata
- **THEN** `archiefnominatie` MUST be set to `bewaren`
- **AND** `bewaartermijn` MUST be set to `P20Y`
- **AND** `archiefactiedatum` MUST be calculated as the object's creation date plus 20 years
- **AND** the afleidingswijze used for calculation MUST be determined by `archive.afleidingswijze` (defaulting to creation date if not configured)

#### Scenario: Archival metadata validation on update
- **GIVEN** an object with `archiefstatus` set to `vernietigd`
- **WHEN** a user attempts to update the object's data
- **THEN** the system MUST reject the update with HTTP 409 Conflict
- **AND** the response MUST indicate that destroyed objects cannot be modified
- **AND** the same restriction MUST apply to objects with `archiefstatus` `overgebracht`

#### Scenario: Archival metadata exposed in API responses
- **GIVEN** an object `zaak-123` with archival metadata populated
- **WHEN** the object is retrieved via `GET /api/objects/{register}/{schema}/{id}`
- **THEN** the response MUST include the `retention` field containing all MDTO archival metadata
- **AND** the `retention` field MUST be filterable in search queries (e.g., `retention.archiefnominatie=vernietigen`)
- **AND** the `retention.legalHold.active` field MUST also be filterable

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
- **AND** the selectielijst register and schema MUST be configured in retention settings

#### Scenario: Import VNG selectielijst
- **GIVEN** the VNG publishes an updated selectielijst for gemeenten
- **WHEN** an admin imports the selectielijst via CSV or JSON upload using the existing data import mechanism
- **THEN** all categories MUST be created as objects in the selectielijst register
- **AND** existing categories MUST be updated (not duplicated) based on their `categorie` code
- **AND** the import MUST log how many entries were created, updated, and skipped

#### Scenario: Override selectielijst per schema
- **GIVEN** a default retention of 10 years for selectielijst category `A1`
- **AND** schema `vertrouwelijk-dossier` requires 20 years retention due to organisational policy
- **WHEN** the admin configures a schema-level override in the schema's `archive` property with `bewaartermijnOverride` and `overrideReason`
- **THEN** objects in `vertrouwelijk-dossier` MUST use the 20-year retention period
- **AND** the override MUST be recorded in the audit trail with the reason for deviation

#### Scenario: Selectielijst version management
- **GIVEN** the VNG publishes a new version of the selectielijst (e.g., 2025 edition replacing 2020 edition)
- **WHEN** the admin activates the new selectielijst version
- **THEN** existing objects MUST retain their original selectielijst reference (no retroactive changes)
- **AND** new objects MUST use the new selectielijst version
- **AND** the admin MUST be able to run a report showing objects under the old vs. new selectielijst

### Requirement: Destruction MUST follow a multi-step approval workflow
Destruction of objects MUST NOT occur automatically. A destruction list MUST be reviewed and approved by at least one authorized archivist before any objects are permanently deleted, conforming to Archiefbesluit 1995 Articles 6-8.

#### Scenario: Approve destruction list (full approval)
- **GIVEN** a destruction list with 15 objects and status `in_review`
- **WHEN** an archivist in the `archivaris` Nextcloud group approves the entire list
- **THEN** the destruction list status MUST change to `approved`
- **AND** the system MUST permanently delete all 15 objects via a `DestructionExecutionJob` (QueuedJob) processing in batches to avoid timeouts
- **AND** an audit trail entry MUST be created for each deletion with action `archival.destroyed`
- **AND** the audit trail entry MUST record: destruction list UUID, approving archivist, timestamp, selectielijst category
- **AND** the destruction list itself MUST be retained permanently as an archival record (verklaring van vernietiging)

#### Scenario: Partially reject destruction list
- **GIVEN** a destruction list with 15 objects
- **WHEN** the archivist removes 3 objects from the list (marking them as `uitgezonderd`) and approves the remaining 12
- **THEN** only the 12 approved objects MUST be destroyed
- **AND** the 3 excluded objects MUST have their `archiefactiedatum` extended by the configured extension period (default: P1Y)
- **AND** the exclusion reason MUST be recorded for each excluded object
- **AND** the destruction list MUST record both the approved and excluded objects

#### Scenario: Reject entire destruction list
- **GIVEN** a destruction list with 15 objects
- **WHEN** the archivist rejects the entire list
- **THEN** no objects MUST be destroyed
- **AND** the destruction list status MUST change to `rejected`
- **AND** the archivist MUST provide a reason for rejection
- **AND** all objects on the list MUST have their `archiefactiedatum` extended by the configured extension period

#### Scenario: Two-step approval for sensitive schemas
- **GIVEN** schema `bezwaarschriften` is configured with `archive.requireDualApproval` set to `true`
- **AND** a destruction list contains objects from this schema
- **WHEN** the first archivist approves the list
- **THEN** the status MUST change to `awaiting_second_approval`
- **AND** a second archivist (different from the first) MUST approve before destruction proceeds

#### Scenario: Destruction certificate generation (verklaring van vernietiging)
- **GIVEN** a destruction list has been fully approved and all objects destroyed
- **WHEN** the destruction process completes
- **THEN** the system MUST generate a destruction certificate as an immutable register object containing:
  - Date of destruction
  - Approving archivist(s)
  - Number of objects destroyed, grouped by schema and selectielijst category
  - Reference to the selectielijst used
  - Statement of compliance with Archiefwet 1995
- **AND** the certificate MUST be stored in the configured archival register

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
- **AND** a legal hold is placed on `zaak-456` after the destruction list was created but before execution
- **WHEN** the DestructionExecutionJob processes the destruction list
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
- **AND** the operation MUST be executed via QueuedJob to avoid timeouts
- **AND** a single audit trail entry MUST summarize the bulk operation
