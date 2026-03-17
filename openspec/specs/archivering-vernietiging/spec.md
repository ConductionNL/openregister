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
- **NOT implemented:** No archiving or destruction lifecycle management exists in the codebase.
  - No `archiefnominatie`, `archiefactiedatum`, `archiefstatus`, or `classificatie` fields on objects or schemas
  - No selection list (selectielijst) entity or configuration
  - No destruction list generation or approval workflow
  - No e-Depot export (SIP generation, MDTO XML)
  - No NEN 2082 compliance reporting
- **Partial foundations:**
  - `ObjectEntity` (`lib/Db/ObjectEntity.php`) supports arbitrary JSON data via the `object` property, so archival metadata could be stored as schema properties
  - `AuditTrailMapper` (`lib/Db/AuditTrailMapper.php`) already logs create/update/delete actions, which could record `archival.destroyed` events
  - `ExportService` (`lib/Db/ExportService.php`) exists for CSV/Excel export, but not for MDTO XML or SIP packages
  - Retention period tracking does not exist at any level (register, schema, or object)

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
