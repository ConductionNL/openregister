## MODIFIED Requirements

### Requirement: The system MUST maintain a verwerkingsactiviteiten register as an OpenRegister schema
A central register of all processing activities (verwerkingsactiviteiten) MUST be maintained as a dedicated OpenRegister register and schema, conforming to GDPR Article 30(1) for controllers and Article 30(2) for processors. Each processing activity record MUST contain all fields mandated by the Autoriteit Persoonsgegevens model verwerkingsregister and the VNG model verwerkingsregister for gemeenten. Field names throughout this requirement use the English identifiers introduced by the `verwerkingsregister-i18n` change (`name`, `purpose`, `legalBasis`, `dataSubjectCategories`, `personalDataCategories`, `recipients`, `retentionPeriod`, `technicalMeasures`/`organisationalMeasures`) — the Dutch labels these fields previously used were the same rename target as the shipped `Verwerkingsactiviteit` entity's columns.

#### Scenario: Create a processing activity with all Art 30 mandatory fields
- **GIVEN** an administrator or privacy officer (FG/DPO) accesses the verwerkingsregister
- **WHEN** they create a new verwerkingsactiviteit with:
  - `name`: `Behandeling bezwaarschrift`
  - `purpose`: `Uitvoering wettelijke taak bezwaarschriftprocedure conform Algemene wet bestuursrecht`
  - `legalBasis` (per Art 6): `legal_obligation` (Art 6 lid 1 sub c AVG — Awb art. 7:1)
  - `dataSubjectCategories`: `["bezwaarmaker", "belanghebbenden", "gemachtigden"]`
  - `personalDataCategories`: `["NAW-gegevens", "BSN", "contactgegevens", "zaakinhoud", "financiele gegevens"]`
  - `recipients`: `["behandelend ambtenaar", "bezwaarschriftencommissie", "rechtbank (bij beroep)"]`
  - `retentionPeriod`: `P10Y` (ISO 8601 duration, 10 years after case closure)
  - `technicalMeasures`/`organisationalMeasures` (security measures per Art 32): `["versleuteling in rust en transit", "toegangscontrole op basis van rollen", "audit logging", "pseudonimisering waar mogelijk"]`
  - `controller` (processor/verwerkingsverantwoordelijke): `Eigen organisatie`
  - `dpiaVereist` (DPIA required — not part of this rename, unbuilt DPIA linkage): `false`
  - `status`: `published`
- **THEN** the processing activity MUST be stored as an object in the verwerkingsactiviteiten schema
- **AND** a UUID MUST be generated for cross-referencing from audit trail entries
- **AND** the `created` and `updated` timestamps MUST be set automatically

#### Scenario: Reject processing activity without mandatory fields
- **GIVEN** an administrator attempts to create a verwerkingsactiviteit
- **WHEN** the `purpose`, `legalBasis`, or `dataSubjectCategories` fields are missing
- **THEN** the system MUST reject the creation with HTTP 400
- **AND** the response MUST list which mandatory Art 30 fields are missing
- **AND** the error message MUST reference the specific GDPR article (e.g., "Art 30 lid 1 sub b vereist het doel van de verwerking")

#### Scenario: List all processing activities with filtering
- **GIVEN** 25 verwerkingsactiviteiten exist across multiple organisational units
- **WHEN** a privacy officer queries `GET /api/objects/{register}/{schema}?legalBasis=legal_obligation`
- **THEN** the system MUST return only activities with the matching legal basis
- **AND** results MUST include pagination metadata
- **AND** the query itself MUST NOT be logged as a processing activity on personal data (it queries the register, not personal data)

#### Scenario: Version processing activity changes
- **GIVEN** verwerkingsactiviteit `Behandeling bezwaarschrift` exists with `retentionPeriod: P10Y`
- **WHEN** the privacy officer updates the retention period to `P7Y` following a new selectielijst
- **THEN** the system MUST create an audit trail entry recording the change via the immutable audit trail (see `audit-trail-immutable` spec)
- **AND** the previous version MUST remain retrievable for compliance evidence
- **AND** the `updated` timestamp MUST reflect the modification date

#### Scenario: Deactivate a processing activity
- **GIVEN** verwerkingsactiviteit `Papieren correspondentie archivering` is no longer performed
- **WHEN** the privacy officer sets its `status` to `archived`
- **THEN** the activity MUST remain in the register with status `archived` (MUST NOT be deleted per Art 30 accountability principle)
- **AND** schemas linked to this activity MUST display a warning that the processing activity is inactive
- **AND** the deactivation MUST be recorded in the audit trail

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
> **Carve-out — stays Dutch by design.** The VNG "Verwerkingenlogging" API is an external standard
> that mandates these exact field names on the wire. Future implementers of this not-yet-built
> export endpoint MUST NOT "fix" this by translating it — doing so would break interoperability
> with the standard `verwerkingsregister-i18n` deliberately did not touch this scenario's field
> names for that reason.

- **GIVEN** the municipality uses the VNG Verwerkingenlogging API standard for cross-system logging
- **WHEN** processing log entries are created
- **THEN** the entries MUST be exportable in the VNG Verwerkingenlogging format including:
  - `actie_id` (action identifier), `verwerking_id` (processing ID), `verwerkingsactiviteit_id`
  - `vertrouwelijkheid` (confidentiality), `bewaartermijn` (retention)
  - `tijdstip`, `tijdstip_registratie`, `verwerkende_organisatie`
- **AND** a REST endpoint `GET /api/verwerkingslog/export` MUST provide this format

### Requirement: Third-party processors (verwerkers) MUST be registered with verwerkersovereenkomst tracking
All third parties that process personal data on behalf of the organisation MUST be registered in the verwerkingsregister with their processor agreement details, conforming to Art 28 AVG. The `avg-bundle.json` `verwerker` schema was renamed to `processor` by the `verwerkingsregister-i18n` change; its property names below use the renamed English keys.

#### Scenario: Register a third-party processor
- **GIVEN** the organisation uses `CloudHosting B.V.` for document storage
- **WHEN** the privacy officer registers the processor
- **THEN** the processor record MUST include:
  - `name`: `CloudHosting B.V.`
  - `chamberOfCommerceNumber`: `12345678`
  - `contactPerson`: `privacy@cloudhosting.nl`
  - `agreementDate`: `2025-03-01`
  - `agreementExpiresAt`: `2027-03-01`
  - `subProcessors`: `["AWS EU-West", "Backup B.V."]`
  - `internationalTransferDetails`: `Servers in EU, geen doorgifte buiten EER`
  - `securityCertifications`: `ISO 27001, SOC 2 Type II`

#### Scenario: Alert on expiring processor agreement
- **GIVEN** processor `CloudHosting B.V.` has an `agreementExpiresAt` of `2027-03-01`
- **WHEN** the current date is within 90 days of expiration
- **THEN** the system MUST send a notification to the privacy officer
- **AND** the processor record MUST display a warning indicator in the UI

#### Scenario: Link processor to processing activities
- **GIVEN** processor `CloudHosting B.V.` is registered
- **WHEN** verwerkingsactiviteit `Documentopslag en -verwerking` lists this processor
- **THEN** the Art 30 export MUST include the processor details alongside the processing activity
- **AND** if the processor is deactivated, all linked verwerkingsactiviteiten MUST display a compliance warning

### Requirement: The Art 30 register MUST be exportable for the Autoriteit Persoonsgegevens
The complete verwerkingsregister MUST be exportable in formats suitable for AP supervision, internal audit, and FG/DPO reporting. The export MUST conform to the VNG model verwerkingsregister template structure.

#### Scenario: Export complete Art 30 register as structured document
> **Carve-out — the PDF's column labels stay Dutch by design.** This is human-readable display text
> for a citizen-facing/AP-facing compliance document mandated by the VNG model verwerkingsregister
> template, not a code identifier — `verwerkingsregister-i18n` explicitly did not translate it.

- **GIVEN** 25 verwerkingsactiviteiten are defined with linked schemas, processors, and DPIAs
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
