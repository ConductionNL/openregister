# avg-verwerkingsregister Specification

## Purpose
Implement a processing register (verwerkingsregister) conforming to GDPR Article 30. Every data processing activity on personal data stored in OpenRegister MUST be logged with purpose limitation (doelbinding), legal basis, data categories, and retention periods. The system MUST support data subject access requests (inzageverzoeken) and purpose-bound access control.

**Tender demand**: 58% of analyzed government tenders require AVG processing register capabilities.

## ADDED Requirements

### Requirement: The system MUST maintain a processing activities register
A central register of all processing activities (verwerkingsactiviteiten) MUST be maintained, conforming to GDPR Art 30.

#### Scenario: Define a processing activity
- GIVEN an admin configuring the processing register
- WHEN they define a new processing activity with:
  - Name: `Behandeling bezwaarschrift`
  - Purpose (doelbinding): `Uitvoering wettelijke taak bezwaarschriftprocedure`
  - Legal basis (grondslag): `Algemene wet bestuursrecht, art. 7:1`
  - Data categories: `NAW-gegevens, BSN, contactgegevens, zaakinhoud`
  - Data subjects (betrokkenen): `Bezwaarmaker, belanghebbenden`
  - Retention period: `10 jaar na afhandeling`
  - Processor (verwerker): `Eigen organisatie`
- THEN the processing activity MUST be stored in the register
- AND it MUST be exportable as part of the Art 30 register

#### Scenario: Link processing activity to schema
- GIVEN processing activity `Behandeling bezwaarschrift` is defined
- WHEN the admin links it to schema `bezwaarschriften`
- THEN every CRUD operation on bezwaarschriften objects MUST be logged with reference to this processing activity

### Requirement: All access to personal data MUST be logged with purpose
Every read, write, or delete operation on objects in schemas marked as containing personal data MUST produce a processing log entry.

#### Scenario: Log data access with purpose
- GIVEN schema `inwoners` is marked as containing personal data
- AND it is linked to processing activity `Uitvoering Wmo-aanvraag`
- WHEN user `medewerker-1` reads object `inwoner-123`
- THEN a processing log entry MUST be created with:
  - `timestamp`: current date-time
  - `user`: `medewerker-1`
  - `action`: `read`
  - `objectUuid`: UUID of `inwoner-123`
  - `verwerkingsactiviteit`: `Uitvoering Wmo-aanvraag`
  - `doelbinding`: the purpose text from the linked activity

#### Scenario: Reject access without valid purpose
- GIVEN schema `inwoners` requires purpose-bound access
- AND user `medewerker-2` has no role linked to any processing activity for `inwoners`
- WHEN `medewerker-2` attempts to read `inwoner-123`
- THEN the system MUST return HTTP 403 with message indicating no valid processing purpose

### Requirement: The system MUST support data subject access requests (inzageverzoek)
A data subject MUST be able to request an overview of all processing activities involving their personal data.

#### Scenario: Generate data subject access report
- GIVEN person with BSN `123456789` has data in schemas `inwoners`, `bezwaarschriften`, and `meldingen`
- WHEN an authorized user initiates a data subject access request for BSN `123456789`
- THEN the system MUST search all schemas marked as containing personal data
- AND return a report listing:
  - All objects containing references to this BSN
  - All processing log entries for those objects
  - The purpose (doelbinding) for each processing activity
  - Retention periods and scheduled deletion dates

#### Scenario: Export access report
- GIVEN a data subject access report has been generated
- WHEN the user exports the report
- THEN the system MUST generate a PDF document containing all processing details
- AND the export itself MUST be logged as a processing activity

### Requirement: The system MUST support the right to erasure (recht op vergetelheid)
Data subjects MUST be able to request deletion of their personal data, subject to legal retention obligations.

#### Scenario: Process erasure request with no retention conflict
- GIVEN person with BSN `123456789` requests erasure
- AND objects referencing this BSN in schema `meldingen` have no legal retention requirement
- WHEN the erasure request is processed
- THEN all objects in `meldingen` referencing BSN `123456789` MUST be deleted or anonymized
- AND an audit trail entry MUST record the erasure with legal basis

#### Scenario: Process erasure request with retention conflict
- GIVEN person with BSN `123456789` requests erasure
- AND objects in schema `bezwaarschriften` have a 10-year legal retention period not yet expired
- WHEN the erasure request is evaluated
- THEN the system MUST flag these objects as retention-blocked
- AND the report MUST explain which legal basis prevents erasure
- AND processing MUST be restricted to the legal purpose only

### Requirement: The Art 30 register MUST be exportable
The complete processing register MUST be exportable for supervisory authority (Autoriteit Persoonsgegevens) review.

#### Scenario: Export Art 30 register
- GIVEN 12 processing activities are defined
- WHEN the admin exports the Art 30 register
- THEN the system MUST generate a document listing all activities with:
  - Name, purpose, legal basis, data categories, data subjects
  - Retention periods, processor information
  - Technical and organizational security measures
- AND the export format MUST be PDF or structured data (JSON/XML)

### Current Implementation Status
- **Partial foundations:**
  - `GdprEntity` (`lib/Db/GdprEntity.php`) exists with fields: uuid, type, value, category, belongsToEntityId, metadata, owner, organisation, detectedAt, updatedAt — represents detected personal data entities
  - `GdprEntityMapper` (`lib/Db/GdprEntityMapper.php`) provides CRUD operations for GDPR entities
  - `GdprEntitiesController` (`lib/Controller/GdprEntitiesController.php`) exposes API endpoints for managing GDPR entities
  - `SearchTrail` entity (`lib/Db/SearchTrail.php`) and `SearchTrailMapper` (`lib/Db/SearchTrailMapper.php`) track search/access patterns
  - `SearchTrailController` (`lib/Controller/SearchTrailController.php`) for querying search trails
  - `EntityRecognitionHandler` (`lib/Service/TextExtraction/EntityRecognitionHandler.php`) detects personal data entities in text
- **NOT implemented:**
  - Processing activities register (verwerkingsactiviteiten) — no entity for defining processing activities with purpose, legal basis, data categories, retention periods
  - Purpose-bound access control (doelbinding) — no mechanism to require/validate processing purpose before data access
  - Data subject access request (inzageverzoek) workflow — no cross-schema search by BSN or personal identifier
  - Right to erasure (recht op vergetelheid) workflow — no erasure request processing with retention conflict detection
  - Art 30 register export — no structured export of all processing activities
  - Processing log entries with verwerkingsactiviteit reference — audit trail does not link to processing purposes
  - Link between processing activities and schemas
- **Partial:**
  - GdprEntity tracks detected personal data but does not implement the full processing register as specified
  - SearchTrail provides some access logging but not with purpose/legal basis context

### Standards & References
- **GDPR (AVG) Article 30** — Register of processing activities
- **GDPR Article 15** — Right of access (inzageverzoek)
- **GDPR Article 17** — Right to erasure
- **GDPR Article 5(1)(b)** — Purpose limitation (doelbinding)
- **Uitvoeringswet AVG (UAVG)** — Dutch GDPR implementation act
- **Autoriteit Persoonsgegevens guidelines** — Dutch Data Protection Authority
- **VNG Model Verwerkingsregister** — Template for municipal processing registers
- **Verwerkingenlogging API (VNG)** — Standard API for processing activity logging in Dutch government
- **BIO** — Information security baseline (personal data protection requirements)

### Specificity Assessment
- The spec provides good scenario-based coverage of the main GDPR workflows.
- Missing: entity/schema definitions for processing activities; API endpoint specifications; how processing activities are linked to schemas (admin UI vs. API); BSN search implementation across schemas.
- Ambiguous: relationship between the existing GdprEntity and the proposed processing register — are they separate concepts or should GdprEntity be extended?
- Open questions:
  - Should the verwerkingsactiviteiten be stored as OpenRegister objects (in a dedicated schema) or as a separate entity table?
  - How does purpose-bound access control interact with the existing RBAC system?
  - What is the format for the Art 30 export — VNG template, custom, or configurable?
  - How should the BSN cross-schema search be implemented efficiently across potentially large datasets?
