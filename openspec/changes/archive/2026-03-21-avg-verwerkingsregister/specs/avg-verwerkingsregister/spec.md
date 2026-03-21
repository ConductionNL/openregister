---
status: draft
---

# AVG Verwerkingsregister

## Purpose
Implement GDPR Article 30 processing activity registration integrated with OpenRegister's existing person and organisation entity system. Processing activities link to schemas that contain personal data, and data subject rights (access, rectification, erasure, portability) operate through the existing ObjectService CRUD operations, filtered by the person/organisation identifiers already tracked via the MultiTenancyTrait. The verwerkingsregister itself is modeled as an OpenRegister register and schema — not a separate system — leveraging the same RBAC, audit trail, and multi-tenancy infrastructure used by all other registers. PII detection builds on the existing EntityRecognitionHandler and GdprEntity infrastructure. Retention enforcement integrates with the existing ObjectRetentionHandler and archival metadata. The system MUST maintain a structured register of verwerkingsactiviteiten with mandatory fields (purpose limitation, legal basis, data categories, data subjects, retention periods, security measures, and processor information), enforce purpose-bound access control (doelbinding) on schemas containing personal data, and provide end-to-end workflows for data subject rights including inzageverzoeken (Art 15), rectificatie (Art 16), recht op vergetelheid (Art 17), and dataportabiliteit (Art 20). Additionally, the system MUST support Data Protection Impact Assessments (DPIA, Art 35), automated PII detection and anonymization, consent tracking for processing activities, and structured export of the complete Art 30 register for the Autoriteit Persoonsgegevens (AP).

**Tender demand**: 58% of analyzed government tenders require AVG processing register capabilities. Cross-referencing with archivering-vernietiging (77%), audit-trail-immutable (56%), and auth-system (67%) shows that GDPR compliance is a prerequisite capability for nearly all Dutch government tender participation.

## Relationship to Existing Implementation
This spec integrates with and extends multiple existing OpenRegister subsystems:

- **PII detection (partially implemented)**: `EntityRecognitionHandler` already detects personal data entities using regex, Presidio, OpenAnonymiser, LLM, or hybrid methods. `GdprEntity`/`GdprEntityMapper` already store detected PII with categories and metadata. This spec extends this from detection-only to compliance-driving (linking detected PII to verwerkingsactiviteiten and triggering compliance alerts).
- **Audit trail (partially implemented)**: `AuditTrail` entity already provides immutable hash-chained entries with organisation field and confidentiality level. This spec extends audit entries with `verwerkingsactiviteit_id`, `doelbinding`, and `grondslag` fields for legally required processing evidence.
- **Access logging (partially implemented)**: `SearchTrail`/`SearchTrailMapper` already track access patterns with organisation context. This spec adds purpose-binding context to these logs.
- **Retention management (partially implemented)**: `ObjectRetentionHandler` manages retention configuration, `ObjectEntity.retention` stores archival metadata (archiefnominatie, archiefactiedatum, bewaartermijn). This spec links retention enforcement to verwerkingsactiviteit bewaartermijnen.
- **Organisation/multi-tenancy (fully implemented)**: `MagicMapper` with `MultiTenancyTrait` already enforces organisation-scoped RBAC. The verwerkingsregister inherits this isolation automatically.
- **RBAC (fully implemented)**: `PermissionHandler`, `PropertyRbacHandler`, and `MagicRbacHandler` already control who can access which data. Purpose-binding extends this with "why" in addition to "who".
- **Anonymization (partially implemented)**: `FileTextController::anonymizeDocument()` and DocuDesk anonymization pipeline provide PII replacement patterns that can be leveraged for erasure-by-anonymization.
- **What this spec adds**: Verwerkingsactiviteiten register schema, purpose-bound access control middleware, DataSubjectSearchService for cross-schema BSN search, data subject rights workflows (inzage/rectificatie/vergetelheid/portabiliteit), DPIA tracking, consent management, verwerker registration, and Art 30 register export.

## ADDED Requirements

### Requirement: The system MUST maintain a verwerkingsactiviteiten register as an OpenRegister schema
A central register of all processing activities (verwerkingsactiviteiten) MUST be maintained as a dedicated OpenRegister register and schema, conforming to GDPR Article 30(1) for controllers and Article 30(2) for processors. Each processing activity record MUST contain all fields mandated by the Autoriteit Persoonsgegevens model verwerkingsregister and the VNG model verwerkingsregister for gemeenten.

#### Scenario: Create a processing activity with all Art 30 mandatory fields
- **GIVEN** an administrator or privacy officer (FG/DPO) accesses the verwerkingsregister
- **WHEN** they create a new verwerkingsactiviteit with:
  - `naam`: `Behandeling bezwaarschrift`
  - `doel` (purpose/doelbinding): `Uitvoering wettelijke taak bezwaarschriftprocedure conform Algemene wet bestuursrecht`
  - `grondslag` (legal basis per Art 6): `Wettelijke verplichting (Art 6 lid 1 sub c AVG) — Awb art. 7:1`
  - `categorieenBetrokkenen` (data subject categories): `["bezwaarmaker", "belanghebbenden", "gemachtigden"]`
  - `categorieenPersoonsgegevens` (personal data categories): `["NAW-gegevens", "BSN", "contactgegevens", "zaakinhoud", "financiele gegevens"]`
  - `ontvangers` (recipients): `["behandelend ambtenaar", "bezwaarschriftencommissie", "rechtbank (bij beroep)"]`
  - `bewaartermijn` (retention period): `P10Y` (ISO 8601 duration, 10 years after case closure)
  - `beveiligingsmaatregelen` (security measures per Art 32): `["versleuteling in rust en transit", "toegangscontrole op basis van rollen", "audit logging", "pseudonimisering waar mogelijk"]`
  - `verwerker` (processor): `Eigen organisatie`
  - `verwerkersovereenkomst` (processor agreement reference): `null` (own organisation)
  - `doorgifte` (transfers to third countries): `Geen doorgifte buiten EER`
  - `dpiaVereist` (DPIA required): `false`
  - `status`: `actief`
- **THEN** the processing activity MUST be stored as an object in the verwerkingsactiviteiten schema
- **AND** a UUID MUST be generated for cross-referencing from audit trail entries
- **AND** the `created` and `updated` timestamps MUST be set automatically

#### Scenario: Reject processing activity without mandatory fields
- **GIVEN** an administrator attempts to create a verwerkingsactiviteit
- **WHEN** the `doel`, `grondslag`, or `categorieenBetrokkenen` fields are missing
- **THEN** the system MUST reject the creation with HTTP 400
- **AND** the response MUST list which mandatory Art 30 fields are missing
- **AND** the error message MUST reference the specific GDPR article (e.g., "Art 30 lid 1 sub b vereist het doel van de verwerking")

#### Scenario: List all processing activities with filtering
- **GIVEN** 25 verwerkingsactiviteiten exist across multiple organisational units
- **WHEN** a privacy officer queries `GET /api/objects/{register}/{schema}?grondslag=Wettelijke verplichting`
- **THEN** the system MUST return only activities with the matching legal basis
- **AND** results MUST include pagination metadata
- **AND** the query itself MUST NOT be logged as a processing activity on personal data (it queries the register, not personal data)

#### Scenario: Version processing activity changes
- **GIVEN** verwerkingsactiviteit `Behandeling bezwaarschrift` exists with `bewaartermijn: P10Y`
- **WHEN** the privacy officer updates the retention period to `P7Y` following a new selectielijst
- **THEN** the system MUST create an audit trail entry recording the change via the immutable audit trail (see `audit-trail-immutable` spec)
- **AND** the previous version MUST remain retrievable for compliance evidence
- **AND** the `updated` timestamp MUST reflect the modification date

#### Scenario: Deactivate a processing activity
- **GIVEN** verwerkingsactiviteit `Papieren correspondentie archivering` is no longer performed
- **WHEN** the privacy officer sets its `status` to `inactief`
- **THEN** the activity MUST remain in the register with status `inactief` (MUST NOT be deleted per Art 30 accountability principle)
- **AND** schemas linked to this activity MUST display a warning that the processing activity is inactive
- **AND** the deactivation MUST be recorded in the audit trail

### Requirement: Processing activities MUST be linked to schemas containing personal data
Each schema in OpenRegister that contains personal data MUST be linked to one or more verwerkingsactiviteiten, establishing the legal basis and purpose for all operations on objects in that schema. This link enforces the purpose limitation principle (doelbinding) of Art 5(1)(b) AVG.

#### Scenario: Link a schema to a processing activity
- **GIVEN** schema `inwoners` exists and verwerkingsactiviteit `Basisregistratie Personen (BRP) bijhouding` exists
- **WHEN** the administrator links schema `inwoners` to this verwerkingsactiviteit
- **THEN** the schema's configuration MUST store the verwerkingsactiviteit UUID reference
- **AND** all subsequent CRUD operations on `inwoners` objects MUST be logged with this verwerkingsactiviteit reference in the audit trail
- **AND** the schema MUST be marked as `containsPersonalData: true`

#### Scenario: Schema linked to multiple processing activities
- **GIVEN** schema `klantcontacten` is linked to both `Klachtenafhandeling` and `Dienstverlening front-office`
- **WHEN** a user accesses a klantcontact object
- **THEN** the user's role MUST be associated with at least one of the linked verwerkingsactiviteiten
- **AND** the audit trail entry MUST record which specific verwerkingsactiviteit justified the access

#### Scenario: Warn on schema without processing activity link
- **GIVEN** schema `sollicitanten` is marked as `containsPersonalData: true`
- **AND** no verwerkingsactiviteit is linked to it
- **WHEN** the admin views the schema configuration
- **THEN** the system MUST display a compliance warning: "Schema bevat persoonsgegevens maar heeft geen gekoppelde verwerkingsactiviteit (Art 30 AVG)"
- **AND** data access MUST still be permitted (warning, not blocking) to avoid disrupting operations

#### Scenario: Automatic PII detection suggests schema should be marked as personal data
- **GIVEN** schema `projecten` is NOT marked as containing personal data
- **AND** the `EntityRecognitionHandler` detects PII entities (names, emails, BSNs) in objects within this schema
- **WHEN** the PII detection confidence exceeds the configured threshold
- **THEN** the system MUST generate a notification to the privacy officer: "Schema 'projecten' bevat mogelijk persoonsgegevens — overweeg koppeling aan verwerkingsactiviteit"
- **AND** the detected entity types and counts MUST be included in the notification

### Requirement: All access to personal data MUST be logged with processing purpose
Every read, write, update, or delete operation on objects in schemas marked as containing personal data MUST produce an immutable processing log entry that records the verwerkingsactiviteit, the user, the action, and the timestamp. This implements the accountability principle (verantwoordingsplicht) of Art 5(2) AVG and aligns with the VNG Verwerkingenlogging API standard.

#### Scenario: Log data access with verwerkingsactiviteit reference
- **GIVEN** schema `inwoners` is marked as containing personal data
- **AND** it is linked to verwerkingsactiviteit `Uitvoering Wmo-aanvraag`
- **WHEN** user `medewerker-1` reads object `inwoner-123`
- **THEN** a processing log entry MUST be created in the immutable audit trail with:
  - `timestamp`: server-side UTC timestamp
  - `user`: `medewerker-1`
  - `action`: `read`
  - `objectUuid`: UUID of `inwoner-123`
  - `schemaUuid`: UUID of `inwoners` schema
  - `verwerkingsactiviteitId`: UUID of `Uitvoering Wmo-aanvraag`
  - `doelbinding`: the purpose text from the linked activity
  - `vertrouwelijkheid`: the confidentiality level of the accessed object
- **AND** the log entry MUST be hash-chained per the `audit-trail-immutable` spec

#### Scenario: Log bulk data operations
- **GIVEN** an API consumer performs a list query on schema `inwoners` returning 50 objects
- **WHEN** the query is executed
- **THEN** a single processing log entry MUST be created recording the bulk access
- **AND** the entry MUST include `objectCount: 50` and the query parameters used
- **AND** individual object UUIDs MUST be recorded if the result set is 100 or fewer objects

#### Scenario: Reject access without valid processing purpose (purpose-bound access control)
- **GIVEN** schema `inwoners` has `requirePurposeBinding: true` enabled
- **AND** user `medewerker-2` has no role linked to any verwerkingsactiviteit for `inwoners`
- **WHEN** `medewerker-2` attempts to read `inwoner-123`
- **THEN** the system MUST return HTTP 403 with body containing: `{"error": "Geen geldige verwerkingsgrondslag voor toegang tot schema 'inwoners'"}`
- **AND** the denied access attempt MUST be logged in the audit trail with action `access_denied_no_purpose`

#### Scenario: Purpose binding enforced across all access methods
- **GIVEN** schema `zaken-sociaal-domein` has `requirePurposeBinding: true`
- **WHEN** access is attempted via REST API, GraphQL, MCP, or public endpoints
- **THEN** the `PurposeBindingMiddleware` MUST intercept all access methods consistently
- **AND** the enforcement MUST occur before any data is returned to the caller

#### Scenario: Logging aligns with VNG Verwerkingenlogging API standard
- **GIVEN** the municipality uses the VNG Verwerkingenlogging API standard for cross-system logging
- **WHEN** processing log entries are created
- **THEN** the entries MUST be exportable in the VNG Verwerkingenlogging format including:
  - `actie_id` (action identifier), `verwerking_id` (processing ID), `verwerkingsactiviteit_id`
  - `vertrouwelijkheid` (confidentiality), `bewaartermijn` (retention)
  - `tijdstip`, `tijdstip_registratie`, `verwerkende_organisatie`
- **AND** a REST endpoint `GET /api/verwerkingslog/export` MUST provide this format

### Requirement: The system MUST support data subject access requests (inzageverzoek, Art 15 AVG)
A data subject MUST be able to request a complete overview of all personal data stored about them and all processing activities involving their data. The system MUST respond within the legally mandated period of one month (Art 12(3) AVG) and support identification via BSN, email, or other configured identifiers.

#### Scenario: Generate data subject access report by BSN
- **GIVEN** person with BSN `123456789` has data in schemas `inwoners`, `bezwaarschriften`, and `meldingen`
- **WHEN** an authorized user (privacy officer or the data subject via a verified portal) initiates a data subject access request for BSN `123456789`
- **THEN** the `DataSubjectSearchService` MUST search all schemas marked as `containsPersonalData: true`
- **AND** the search MUST check all string-type properties in each schema for BSN matches
- **AND** the system MUST return a report listing:
  - All objects containing references to BSN `123456789`, grouped by schema
  - The verwerkingsactiviteit and doelbinding for each schema
  - All processing log entries for those objects (who accessed what, when, why)
  - Retention periods and calculated deletion dates per object
  - Any third-party recipients (ontvangers) the data has been shared with

#### Scenario: Cross-schema search with performance safeguards
- **GIVEN** OpenRegister contains 15 schemas marked as containing personal data with a combined 500,000 objects
- **WHEN** a data subject access request is initiated for BSN `987654321`
- **THEN** the search MUST use database indexes on BSN fields where available
- **AND** the search MUST complete within 30 seconds for initial results
- **AND** if full results require longer, the system MUST return a task ID for asynchronous retrieval
- **AND** the data subject MUST be notified (via Nextcloud notification) when the report is ready

#### Scenario: Export access report as PDF and machine-readable format
- **GIVEN** a data subject access report has been generated for BSN `123456789`
- **WHEN** the user exports the report
- **THEN** the system MUST generate both:
  - A PDF document using DocuDesk PDF generation (if available), containing all processing details in human-readable Dutch
  - A JSON export conforming to the GDPR data portability format
- **AND** the export itself MUST be logged as a processing activity with doelbinding `Inzageverzoek betrokkene Art 15 AVG`
- **AND** the PDF MUST include the organisation name, date of generation, and privacy officer contact details

#### Scenario: Track inzageverzoek deadline compliance
- **GIVEN** a data subject access request was filed on 2026-01-15
- **WHEN** the one-month deadline of 2026-02-15 approaches
- **THEN** the system MUST send a reminder notification to the privacy officer 7 days before the deadline
- **AND** if the deadline passes without the request being marked as fulfilled, the system MUST escalate the notification
- **AND** the request status, filing date, and completion date MUST be tracked in the verwerkingsregister

### Requirement: The system MUST support the right to rectification (recht op rectificatie, Art 16 AVG)
Data subjects MUST be able to request correction of inaccurate personal data. The system MUST support a structured rectification workflow with before/after evidence.

#### Scenario: Process a rectification request
- **GIVEN** data subject with BSN `123456789` reports that their address in schema `inwoners` is incorrect
- **WHEN** an authorized user processes the rectification request
- **THEN** the system MUST update the address field on the matching object
- **AND** the audit trail MUST record the change with:
  - `action`: `rectification`
  - `grondslag`: `Art 16 AVG — recht op rectificatie`
  - `changed`: the old and new values
  - `requestReference`: the rectification request identifier
- **AND** the data subject MUST be notified that the rectification is complete

#### Scenario: Rectification propagation to linked systems
- **GIVEN** the rectified data in schema `inwoners` is referenced by objects in schemas `bezwaarschriften` and `meldingen` (via `$ref` or BSN lookup)
- **WHEN** the rectification is processed
- **THEN** the system MUST identify all objects referencing the corrected data
- **AND** generate a report listing which related objects may need updating
- **AND** the privacy officer MUST be notified of potential cascade rectification needs

#### Scenario: Reject rectification of factual records
- **GIVEN** a data subject requests rectification of a medical assessment conclusion in schema `keuringen`
- **WHEN** the assessment is a professional judgment, not a factual data error
- **THEN** the system MUST allow the privacy officer to reject the rectification with reason
- **AND** record the rejection with the legal basis in the audit trail
- **AND** allow the data subject's objection statement to be attached to the record

### Requirement: The system MUST support the right to erasure (recht op vergetelheid, Art 17 AVG)
Data subjects MUST be able to request deletion of their personal data, subject to legal retention obligations. The system MUST evaluate each object against its retention schedule and legal basis before erasure, and provide anonymization as an alternative where full deletion conflicts with archival obligations.

#### Scenario: Process erasure request with no retention conflict
- **GIVEN** person with BSN `123456789` requests erasure
- **AND** objects referencing this BSN in schema `meldingen` have no legal retention requirement or the retention period has expired
- **WHEN** the erasure request is processed
- **THEN** all objects in `meldingen` referencing BSN `123456789` MUST be deleted or anonymized
- **AND** an immutable audit trail entry MUST record the erasure with:
  - `action`: `erasure`
  - `grondslag`: `Art 17 AVG — recht op vergetelheid`
  - `objectCount`: number of objects affected
  - `method`: `deletion` or `anonymization`

#### Scenario: Process erasure request with retention conflict (Archiefwet)
- **GIVEN** person with BSN `123456789` requests erasure
- **AND** objects in schema `bezwaarschriften` have a 10-year legal retention period under Archiefwet/selectielijst that has not yet expired
- **WHEN** the erasure request is evaluated
- **THEN** the system MUST flag these objects as retention-blocked
- **AND** the report MUST explain which legal basis prevents erasure: "Archiefwet 1995 — selectielijst categorie 1.1, bewaartermijn tot [date]"
- **AND** processing of the retained data MUST be restricted to the archival purpose only (opslagbeperking)
- **AND** the data subject MUST be informed of the retention reason and expected deletion date

#### Scenario: Anonymization as alternative to deletion
- **GIVEN** an erasure request targets objects that must be retained for statistical purposes but no longer require identification
- **WHEN** the privacy officer chooses anonymization over deletion
- **THEN** the system MUST replace all PII fields (detected via `EntityRecognitionHandler` or manually marked) with anonymized placeholders
- **AND** the anonymized object MUST remain in the register for statistical/archival purposes
- **AND** the anonymization MUST be irreversible (no mapping table retained)
- **AND** the audit trail MUST record which fields were anonymized

#### Scenario: Erasure propagation to third-party processors
- **GIVEN** the verwerkingsactiviteit for the erased data lists a third-party verwerker `Extern ICT-bedrijf`
- **WHEN** the erasure is completed in OpenRegister
- **THEN** the system MUST generate a notification to the privacy officer listing third parties that must be informed of the erasure per Art 17(2) AVG
- **AND** the notification MUST include the verwerker name, contact details from the verwerkersovereenkomst, and the specific data that was erased

### Requirement: The system MUST support the right to data portability (recht op dataportabiliteit, Art 20 AVG)
Data subjects MUST be able to receive their personal data in a structured, commonly used, and machine-readable format, and have the right to transmit that data to another controller.

#### Scenario: Export personal data in machine-readable format
- **GIVEN** person with BSN `123456789` requests data portability
- **WHEN** the export is generated
- **THEN** the system MUST produce a JSON file containing all personal data across all schemas
- **AND** the JSON MUST use a standardized structure with schema names as keys and object arrays as values
- **AND** only data processed on the basis of consent (Art 6(1)(a)) or contract (Art 6(1)(b)) MUST be included (not data processed under legal obligation)
- **AND** the export MUST be downloadable as a ZIP archive

#### Scenario: Direct transfer to another controller
- **GIVEN** a data portability export has been generated
- **WHEN** the data subject requests transfer to another controller's system
- **THEN** the system MUST support export via API (POST to a specified endpoint) where technically feasible
- **AND** the transfer MUST be logged in the audit trail with the receiving controller's identity

#### Scenario: Exclude derived and aggregated data from portability export
- **GIVEN** schema `risicoprofielen` contains algorithmically derived risk scores based on the data subject's personal data
- **WHEN** a data portability request is processed
- **THEN** the derived risk scores MUST NOT be included in the export (Art 20 applies to data "provided by" the data subject)
- **AND** the export report MUST note which schemas were excluded and why

### Requirement: The system MUST support Data Protection Impact Assessments (DPIA, Art 35 AVG)
For processing activities that pose a high risk to data subjects' rights and freedoms, the system MUST support DPIA documentation, track DPIA status per verwerkingsactiviteit, and enforce DPIA completion before processing begins when required by Art 35 criteria or the AP's DPIA-verplichtingenlijst.

#### Scenario: Flag processing activity as DPIA-required
- **GIVEN** verwerkingsactiviteit `Geautomatiseerde besluitvorming bijstandsaanvragen` involves automated decision-making (Art 22) and processes special category data (Art 9)
- **WHEN** the privacy officer evaluates the activity
- **THEN** the system MUST flag `dpiaVereist: true` based on Art 35(3) criteria
- **AND** the system MUST prevent the verwerkingsactiviteit status from being set to `actief` until a DPIA is completed and linked

#### Scenario: Document DPIA within the verwerkingsregister
- **GIVEN** verwerkingsactiviteit `Cameratoezicht openbare ruimte` requires a DPIA
- **WHEN** the privacy officer completes the DPIA
- **THEN** the DPIA record MUST be stored as a linked object containing:
  - `beschrijving`: systematic description of processing operations
  - `noodzakelijkheid`: assessment of necessity and proportionality
  - `risicobeoordeling`: risk assessment for data subjects
  - `maatregelen`: planned mitigating measures
  - `adviesFG`: DPO advice and whether it was followed
  - `consultatieDatum`: date of AP consultation (if applicable, per Art 36)
  - `status`: `concept`, `afgerond`, `herzien_nodig`
- **AND** the DPIA MUST be linked to the verwerkingsactiviteit

#### Scenario: DPIA review trigger on significant change
- **GIVEN** verwerkingsactiviteit `Fraudedetectie` has a completed DPIA
- **WHEN** the data categories are expanded to include `strafrechtelijke gegevens` (Art 10 AVG)
- **THEN** the system MUST set the DPIA status to `herzien_nodig`
- **AND** notify the privacy officer that the DPIA must be reviewed due to a material change
- **AND** the verwerkingsactiviteit MUST display a warning until the DPIA review is completed

### Requirement: The system MUST track consent as a legal basis for processing (Art 6(1)(a) and Art 7 AVG)
When processing is based on consent, the system MUST record, manage, and prove consent per data subject, per processing purpose, with the ability to withdraw consent at any time.

#### Scenario: Record consent for a specific processing activity
- **GIVEN** verwerkingsactiviteit `Nieuwsbriefverzending` has `grondslag: Toestemming (Art 6 lid 1 sub a AVG)`
- **WHEN** data subject with BSN `123456789` gives consent via an intake form
- **THEN** a consent record MUST be created linking:
  - `betrokkene`: BSN `123456789`
  - `verwerkingsactiviteitId`: UUID of `Nieuwsbriefverzending`
  - `consentDatum`: timestamp of consent
  - `consentMethode`: `digitaal formulier` (with reference to the form submission)
  - `status`: `verleend`
- **AND** the consent record MUST be stored in a dedicated consent schema

#### Scenario: Withdraw consent and cease processing
- **GIVEN** data subject with BSN `123456789` withdraws consent for `Nieuwsbriefverzending`
- **WHEN** the withdrawal is processed
- **THEN** the consent record status MUST be updated to `ingetrokken` with the withdrawal timestamp
- **AND** all future processing under this verwerkingsactiviteit for this data subject MUST be blocked
- **AND** existing data processed under the withdrawn consent MUST be evaluated for deletion (unless another legal basis applies)
- **AND** the withdrawal MUST be as easy as giving consent (Art 7(3) AVG)

#### Scenario: Prove consent for AP audit
- **GIVEN** the Autoriteit Persoonsgegevens requests proof of consent for verwerkingsactiviteit `Klanttevredenheidsonderzoek`
- **WHEN** the privacy officer queries consent records for this activity
- **THEN** the system MUST return all consent records with:
  - Who consented (betrokkene identifier)
  - When they consented (timestamp)
  - What they consented to (verwerkingsactiviteit details)
  - How consent was obtained (methode and evidence)
  - Current status (verleend/ingetrokken)
- **AND** the consent records MUST be immutable (withdrawal creates a new record, does not modify the original)

### Requirement: Third-party processors (verwerkers) MUST be registered with verwerkersovereenkomst tracking
All third parties that process personal data on behalf of the organisation MUST be registered in the verwerkingsregister with their processor agreement details, conforming to Art 28 AVG.

#### Scenario: Register a third-party processor
- **GIVEN** the organisation uses `CloudHosting B.V.` for document storage
- **WHEN** the privacy officer registers the processor
- **THEN** the verwerker record MUST include:
  - `naam`: `CloudHosting B.V.`
  - `kvkNummer`: `12345678`
  - `contactpersoon`: `privacy@cloudhosting.nl`
  - `verwerkersovereenkomstDatum`: `2025-03-01`
  - `verwerkersovereenkomstVerloopt`: `2027-03-01`
  - `subverwerkers`: `["AWS EU-West", "Backup B.V."]`
  - `doorgifteDetails`: `Servers in EU, geen doorgifte buiten EER`
  - `beveiligingsCertificering`: `ISO 27001, SOC 2 Type II`

#### Scenario: Alert on expiring processor agreement
- **GIVEN** verwerker `CloudHosting B.V.` has a verwerkersovereenkomst expiring on `2027-03-01`
- **WHEN** the current date is within 90 days of expiration
- **THEN** the system MUST send a notification to the privacy officer
- **AND** the verwerker record MUST display a warning indicator in the UI

#### Scenario: Link processor to processing activities
- **GIVEN** verwerker `CloudHosting B.V.` is registered
- **WHEN** verwerkingsactiviteit `Documentopslag en -verwerking` lists this verwerker
- **THEN** the Art 30 export MUST include the processor details alongside the processing activity
- **AND** if the processor is deactivated, all linked verwerkingsactiviteiten MUST display a compliance warning

### Requirement: The Art 30 register MUST be exportable for the Autoriteit Persoonsgegevens
The complete verwerkingsregister MUST be exportable in formats suitable for AP supervision, internal audit, and FG/DPO reporting. The export MUST conform to the VNG model verwerkingsregister template structure.

#### Scenario: Export complete Art 30 register as structured document
- **GIVEN** 25 verwerkingsactiviteiten are defined with linked schemas, verwerkers, and DPIAs
- **WHEN** the privacy officer triggers `GET /api/verwerkingsregister/export?format=pdf`
- **THEN** the system MUST generate a PDF document (via DocuDesk if available) listing all activities with:
  - Naam, doel (doelbinding), grondslag, categorieën persoonsgegevens, categorieën betrokkenen
  - Ontvangers, bewaartermijn, beveiligingsmaatregelen, verwerkerinformatie
  - DPIA status per activity, doorgifte details
  - Date of generation, organisation name, FG/DPO contact details
- **AND** the format MUST follow the VNG model verwerkingsregister structure

#### Scenario: Export as machine-readable JSON
- **GIVEN** the privacy officer requests `GET /api/verwerkingsregister/export?format=json`
- **THEN** the system MUST return a JSON document conforming to a documented JSON Schema
- **AND** each verwerkingsactiviteit MUST include all Art 30 mandatory fields plus linked schema UUIDs
- **AND** the JSON MUST be importable back into OpenRegister for migration or backup purposes

#### Scenario: Export as CSV for spreadsheet analysis
- **GIVEN** the privacy officer requests `GET /api/verwerkingsregister/export?format=csv`
- **THEN** the system MUST return a CSV file with one row per verwerkingsactiviteit
- **AND** multi-value fields (categorieën, ontvangers) MUST be semicolon-separated within their columns
- **AND** the CSV MUST use UTF-8 encoding with BOM for Excel compatibility

#### Scenario: Incremental export since last AP report
- **GIVEN** the previous AP export was generated on 2025-06-01
- **WHEN** the privacy officer requests an incremental export with `?since=2025-06-01`
- **THEN** the export MUST include only verwerkingsactiviteiten that were created or modified after that date
- **AND** the export MUST clearly mark which activities are new vs. modified

### Requirement: Automated PII detection MUST flag unregistered personal data processing
The system MUST leverage the existing `EntityRecognitionHandler` and `GdprEntity` infrastructure to automatically detect personal data in schemas not yet marked as containing personal data, and generate compliance alerts.

#### Scenario: Scheduled PII scan across all schemas
- **GIVEN** the administrator has configured a weekly PII detection scan
- **WHEN** the scan runs across all schemas
- **THEN** the `EntityRecognitionHandler` MUST analyze a sample of objects from each schema (configurable sample size, default 100 objects)
- **AND** for each schema where PII is detected but `containsPersonalData` is `false`, a compliance alert MUST be generated
- **AND** the alert MUST include: schema name, detected entity types (BSN, email, phone, name, address, IBAN), confidence scores, and sample count

#### Scenario: Real-time PII detection on object creation
- **GIVEN** schema `projectnotities` is NOT marked as containing personal data
- **WHEN** a new object is created containing text `Overleg met Jan de Vries (BSN 123456789) over zijn WMO-aanvraag`
- **THEN** the `EntityRecognitionHandler` MUST detect PII entities: `PERSON: Jan de Vries`, `BSN: 123456789`
- **AND** the detected entities MUST be stored as `GdprEntity` records linked to the object
- **AND** a notification MUST be sent to the privacy officer suggesting the schema be linked to a verwerkingsactiviteit

#### Scenario: PII detection respects detection method configuration
- **GIVEN** the file settings configure `entityRecognitionMethod: hybrid` (combining regex + OpenAnonymiser)
- **WHEN** PII detection runs
- **THEN** both regex patterns (fast, local) and the OpenAnonymiser API (Dutch-focused, higher accuracy) MUST be used
- **AND** results MUST be deduplicated across methods
- **AND** the detection method MUST be recorded on each `GdprEntity` record for audit purposes

### Requirement: Retention enforcement MUST automatically trigger deletion or anonymization
When an object's retention period expires and its archivering status permits destruction, the system MUST automatically initiate deletion or anonymization workflows, integrating with the `archivering-vernietiging` spec's retention infrastructure.

#### Scenario: Automatic deletion on retention expiry
- **GIVEN** objects in schema `meldingen` have `bewaartermijn: P5Y` configured via the linked verwerkingsactiviteit
- **AND** object `melding-001` was created on 2020-01-15 and has `archiefnominatie: vernietigen`
- **WHEN** the retention enforcement job runs after 2025-01-15
- **THEN** the system MUST queue `melding-001` for destruction per the `archivering-vernietiging` spec's multi-step approval workflow
- **AND** if auto-approval is configured, the object MUST be deleted with an audit trail entry recording the legal basis

#### Scenario: Retention conflict between AVG and Archiefwet
- **GIVEN** verwerkingsactiviteit specifies `bewaartermijn: P2Y` for data minimization
- **AND** the schema's archival configuration specifies `bewaartermijn: P10Y` per selectielijst
- **WHEN** the 2-year AVG retention expires
- **THEN** the system MUST NOT delete the object (Archiefwet takes precedence)
- **AND** the system MUST restrict processing to archival purposes only
- **AND** the object MUST be flagged as `avgRetentionExpired: true, archiefRetentionActive: true`
- **AND** access MUST be limited to users with archival roles

#### Scenario: Pseudonymization on partial retention expiry
- **GIVEN** verwerkingsactiviteit `Statistisch onderzoek` requires data retention for 20 years but PII retention for only 2 years
- **WHEN** the PII retention period expires
- **THEN** the system MUST pseudonymize identifying fields while retaining non-identifying data
- **AND** the pseudonymization mapping MUST be stored separately with its own shorter retention period
- **AND** the audit trail MUST record the pseudonymization event

### Requirement: Multi-tenant privacy isolation MUST prevent cross-organisation data access
In multi-tenant deployments, personal data and the verwerkingsregister MUST be strictly isolated between organisations. One organisation's privacy officer MUST NOT be able to access another organisation's processing register or personal data.

#### Scenario: Organisation-scoped verwerkingsregister
- **GIVEN** organisations `Gemeente Utrecht` and `Gemeente Amersfoort` share an OpenRegister instance
- **WHEN** the privacy officer of `Gemeente Utrecht` queries the verwerkingsregister
- **THEN** only verwerkingsactiviteiten belonging to `Gemeente Utrecht` MUST be returned
- **AND** the MagicMapper's organisation filter (existing RBAC) MUST enforce this isolation at the query level
- **AND** cross-organisation data access attempts MUST be logged as security events

#### Scenario: Data subject request scoped to organisation
- **GIVEN** person with BSN `123456789` has data in both `Gemeente Utrecht` and `Gemeente Amersfoort`
- **WHEN** `Gemeente Utrecht` processes a data subject access request
- **THEN** the report MUST only include data within `Gemeente Utrecht`'s schemas
- **AND** the system MUST NOT reveal that the data subject also has data in another organisation's schemas

#### Scenario: Art 30 export scoped to organisation
- **GIVEN** the AP requests the verwerkingsregister from `Gemeente Amersfoort`
- **WHEN** the export is generated
- **THEN** the export MUST contain only `Gemeente Amersfoort`'s processing activities
- **AND** no data, schema references, or processor information from other organisations MUST be included

### Requirement: An audit trail specifically for privacy operations MUST be maintained
All privacy-specific operations (data subject requests, consent changes, DPIA actions, erasure operations) MUST be tracked in a dedicated privacy audit trail that is separate from the general object audit trail, ensuring privacy operations cannot be obscured in high-volume general logs.

#### Scenario: Privacy operation creates dedicated audit entry
- **GIVEN** a data subject access request is filed for BSN `123456789`
- **WHEN** the request is processed and completed
- **THEN** the privacy audit trail MUST contain entries for:
  - `inzageverzoek_ontvangen`: filing date, data subject identifier, requesting channel
  - `inzageverzoek_verwerkt`: search scope, schemas searched, objects found
  - `inzageverzoek_afgerond`: completion date, report generated, delivery method
- **AND** each entry MUST include the processing officer's identity and timestamp

#### Scenario: Privacy audit trail is immutable and exportable
- **GIVEN** 200 privacy operations have been recorded over the past year
- **WHEN** the privacy officer exports the privacy audit trail
- **THEN** the export MUST include all operations with timestamps, actors, and outcomes
- **AND** the entries MUST be hash-chained (per `audit-trail-immutable` spec) for tamper evidence
- **AND** the export MUST be available in both PDF and JSON formats

#### Scenario: Privacy audit trail retention
- **GIVEN** privacy audit trail entries exist
- **WHEN** the retention period for the general audit trail expires
- **THEN** privacy audit trail entries MUST be retained for at least the accountability period (typically the statute of limitations for AP enforcement, 5 years under UAVG)
- **AND** privacy audit trail entries MUST NOT be automatically deleted with general audit trail cleanup

## Current Implementation Status
- **Partial foundations:**
  - `GdprEntity` (`lib/Db/GdprEntity.php`) exists with fields: uuid, type, value, category, belongsToEntityId, metadata, owner, organisation, detectedAt, updatedAt — represents detected personal data entities with categories `pii` and `sensitive_pii`
  - `GdprEntityMapper` (`lib/Db/GdprEntityMapper.php`) provides CRUD operations for GDPR entities stored in `openregister_entities` table
  - `GdprEntitiesController` (`lib/Controller/GdprEntitiesController.php`) exposes API endpoints for managing GDPR entities (list, get, types, categories, stats, delete)
  - `EntityRecognitionHandler` (`lib/Service/TextExtraction/EntityRecognitionHandler.php`) detects personal data entities in text using regex, Presidio, OpenAnonymiser, LLM, or hybrid methods — supports `CATEGORY_PERSONAL_DATA` and `CATEGORY_SENSITIVE_PII`
  - `SearchTrail` entity (`lib/Db/SearchTrail.php`) and `SearchTrailMapper` track access patterns with organisation context
  - `AuditTrail` (`lib/Db/AuditTrail.php`) supports hash-chained immutable entries with organisation field
  - `ObjectRetentionHandler` (`lib/Service/Settings/ObjectRetentionHandler.php`) manages retention configuration
  - `ObjectEntity.retention` field stores archival metadata (archiefnominatie, archiefactiedatum, bewaartermijn)
  - `MagicMapper` already prevents PII exposure for unauthenticated users and enforces organisation-scoped RBAC
  - `FileTextController::anonymizeDocument()` creates anonymized copies with PII replaced by placeholders
  - DocuDesk `consent-management` spec provides GDPR consent tracking for publication (WOO context)
  - DocuDesk `anonymization` spec provides a full anonymization pipeline with PII detection and replacement
- **NOT implemented:**
  - Verwerkingsactiviteiten register — no entity/schema for defining processing activities with Art 30 mandatory fields
  - Purpose-bound access control (doelbinding) — no `PurposeBindingMiddleware` or mechanism to require/validate processing purpose before data access
  - Schema-to-verwerkingsactiviteit linking — schemas have no `verwerkingsactiviteitId` or `containsPersonalData` configuration
  - Data subject access request (inzageverzoek) workflow — no `DataSubjectSearchService` for cross-schema BSN search
  - Right to rectification workflow — no structured rectification request handling
  - Right to erasure (recht op vergetelheid) workflow — no `ErasureRequestHandler` with retention conflict detection
  - Right to data portability — no personal data export per data subject
  - DPIA documentation and tracking — no DPIA entity or linking to verwerkingsactiviteiten
  - Consent tracking per processing activity — consent management exists in DocuDesk but only for WOO publication, not for general processing consent
  - Third-party processor (verwerker) registration — no verwerker entity or verwerkersovereenkomst tracking
  - Art 30 register export — no structured export endpoint
  - VNG Verwerkingenlogging API compliance — processing log entries do not include verwerkingsactiviteit references
  - Privacy-specific audit trail — no separation between general and privacy audit entries
  - Automated retention enforcement linked to verwerkingsactiviteit bewaartermijn
- **Partial:**
  - GdprEntity tracks detected personal data but does not implement the full processing register
  - AuditTrail provides immutable logging but not with purpose/legal basis/verwerkingsactiviteit context
  - SearchTrail provides access logging but not with doelbinding
  - MagicMapper enforces organisation isolation but not purpose-binding
  - DocuDesk anonymization pipeline can be leveraged for erasure-by-anonymization

## Standards & References
- **GDPR (AVG) Article 5** — Principles: lawfulness, purpose limitation (doelbinding), data minimization, accuracy, storage limitation, integrity, accountability
- **GDPR (AVG) Article 6** — Lawfulness of processing (legal bases: consent, contract, legal obligation, vital interests, public task, legitimate interest)
- **GDPR (AVG) Article 7** — Conditions for consent
- **GDPR (AVG) Article 9** — Special categories of personal data (bijzondere persoonsgegevens)
- **GDPR (AVG) Article 10** — Processing of criminal conviction data (strafrechtelijke gegevens)
- **GDPR (AVG) Article 12-14** — Transparency and information obligations
- **GDPR (AVG) Article 15** — Right of access (inzageverzoek)
- **GDPR (AVG) Article 16** — Right to rectification
- **GDPR (AVG) Article 17** — Right to erasure (recht op vergetelheid)
- **GDPR (AVG) Article 18** — Right to restriction of processing (opslagbeperking)
- **GDPR (AVG) Article 20** — Right to data portability
- **GDPR (AVG) Article 22** — Automated individual decision-making
- **GDPR (AVG) Article 25** — Data protection by design and by default
- **GDPR (AVG) Article 28** — Processor requirements (verwerkersovereenkomst)
- **GDPR (AVG) Article 30** — Records of processing activities (verwerkingsregister)
- **GDPR (AVG) Article 32** — Security of processing (beveiligingsmaatregelen)
- **GDPR (AVG) Article 35** — Data Protection Impact Assessment (DPIA/GEB)
- **GDPR (AVG) Article 36** — Prior consultation with supervisory authority
- **Uitvoeringswet AVG (UAVG)** — Dutch GDPR implementation act
- **Autoriteit Persoonsgegevens guidelines** — Dutch DPA model verwerkingsregister and DPIA-verplichtingenlijst
- **VNG Model Verwerkingsregister** — Template for municipal processing registers
- **VNG Verwerkingenlogging API** — Standard API for processing activity logging in Dutch government (v1.0)
- **BIO (Baseline Informatiebeveiliging Overheid)** — Information security baseline, personal data protection requirements
- **Archiefwet 1995** — Archival law governing retention that may override AVG deletion rights
- **Selectielijsten** — Category-based retention schedules that interact with AVG bewaartermijnen

## Cross-References
- **`archivering-vernietiging`** — Retention schedules, destruction workflows, and legal holds that interact with AVG bewaartermijnen and recht op vergetelheid. Archiefwet retention may override AVG deletion rights.
- **`audit-trail-immutable`** — Foundation for tamper-evident logging of all processing activities. Processing log entries MUST extend audit trail entries with verwerkingsactiviteit references.
- **`auth-system`** — Consumer entities, RBAC, and identity resolution that determine who can access personal data and which verwerkingsactiviteit justifies the access. Purpose-binding middleware integrates with the auth middleware chain.
- **`row-field-level-security`** — Field-level security can enforce PII field visibility rules, complementing purpose-bound access control.
- **`deletion-audit-trail`** — Records of deleted objects provide evidence for erasure request compliance.
- **`content-versioning`** — Version history must be considered in data subject access requests and erasure (all versions must be included/erased).
- **DocuDesk `anonymization`** — Provides the anonymization pipeline (PII detection + replacement) that can be leveraged for erasure-by-anonymization.
- **DocuDesk `consent-management`** — Provides consent tracking patterns (for WOO publication) that can inform the general processing consent model.

## Nextcloud Integration Analysis

**Status**: Not yet implemented as a formal processing register. `GdprEntity` exists for PII detection and `SearchTrail` tracks access patterns, but no processing activities register, purpose-bound access control, data subject access requests, or Art 30 export exist.

**Nextcloud Core Interfaces**:
- `INotifier` / `INotification`: Send notifications for data subject access requests (inzageverzoeken) — notify the privacy officer when a request is filed, notify when the deadline approaches, and notify the requester when the report is ready. Also notify when retention periods trigger erasure eligibility, verwerkersovereenkomsten approach expiration, and DPIA reviews are needed.
- `IEventDispatcher`: Fire `PersonalDataAccessedEvent` on every read/write to schemas marked as containing personal data. This event carries the user, object UUID, action type, linked verwerkingsactiviteit, and doelbinding. Listeners log these events to the processing log. Fire `DataSubjectRequestEvent` for inzageverzoek/rectificatie/vergetelheid workflows. Fire `ConsentChangedEvent` when consent is granted or withdrawn.
- `Middleware`: Implement a `PurposeBindingMiddleware` that intercepts requests to schemas flagged as containing personal data with `requirePurposeBinding: true`. The middleware checks whether the requesting user's role is linked to a valid verwerkingsactiviteit for the target schema. If no valid purpose exists, return HTTP 403.
- `AuditTrail` (OpenRegister's `AuditTrailMapper`): Extend audit trail entries to include `verwerkingsactiviteit_id`, `doelbinding`, and `grondslag` fields, providing the legally required processing evidence for GDPR Art 30 and Art 5(2) accountability.
- `IJobList` / `TimedJob`: Schedule automated retention enforcement, DPIA review reminders, verwerkersovereenkomst expiration checks, and periodic PII detection scans as Nextcloud background jobs.

**Implementation Approach**:
- Model verwerkingsactiviteiten as a dedicated OpenRegister register and schema. Each processing activity object stores all Art 30 mandatory fields. This register IS the Art 30 register — querying it produces the Art 30 overview. Use a pre-installed register (similar to DocuDesk's consent register pattern) created via a repair step.
- Model verwerkers (processors) as a separate schema in the same register, linked to verwerkingsactiviteiten via object references.
- Model DPIA records as a third schema in the register, linked to verwerkingsactiviteiten.
- Model consent records as a fourth schema, linking data subjects to verwerkingsactiviteiten with consent lifecycle tracking.
- Link processing activities to data schemas via a `privacy` configuration property on the Schema entity containing: `containsPersonalData`, `verwerkingsactiviteitIds[]`, `requirePurposeBinding`, `piiFields[]`.
- For data subject access requests, implement a `DataSubjectSearchService` that queries all schemas where `containsPersonalData: true`, searching for objects matching a BSN or other personal identifier across all string-type properties. Use existing search infrastructure (Solr/Elasticsearch if configured) for performance.
- For the right to erasure, implement an `ErasureRequestHandler` that evaluates each matching object against both the verwerkingsactiviteit's bewaartermijn and the schema's archival retention period (from `archivering-vernietiging`). Objects with expired retention are deleted/anonymized; objects with active retention are flagged with restricted processing.
- Art 30 register export: Create an `Art30ExportService` that generates PDF (via DocuDesk), JSON, and CSV exports. The PDF follows the VNG model verwerkingsregister template layout.
- Purpose-binding middleware: Implement as Nextcloud middleware that runs after authentication (from `auth-system`) but before controller execution. It checks the resolved user's groups against the verwerkingsactiviteit's linked roles for the target schema.

**Dependencies on Existing OpenRegister Features**:
- `GdprEntity` / `GdprEntityMapper` — existing PII detection entities, referenced for automated PII flagging of unregistered schemas.
- `EntityRecognitionHandler` — detects personal data entities using regex, Presidio, OpenAnonymiser, or hybrid methods. Drives automatic PII detection for compliance alerts.
- `SearchTrail` / `SearchTrailMapper` — existing access logging with organisation scope, provides partial processing evidence foundation.
- `AuditTrail` / `AuditTrailMapper` — immutable hash-chained audit entries, MUST be extended with verwerkingsactiviteit references.
- `ObjectRetentionHandler` — existing retention configuration infrastructure, used for AVG bewaartermijn enforcement.
- `ObjectEntity.retention` — existing retention metadata field on objects, used for archival status tracking.
- `ObjectService` — CRUD operations where processing logging hooks and purpose-binding checks are inserted.
- `MagicMapper` — existing organisation-scoped RBAC and PII exposure prevention, extended with purpose-binding enforcement.
- `Schema.archive` — existing archival configuration, extended with `privacy` configuration block.
- DocuDesk `ConsentService` — pattern for consent record management via OpenRegister objects.
- DocuDesk `FileService::anonymizeDocument()` — pattern for PII replacement in document anonymization.
