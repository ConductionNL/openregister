---
status: done
---

# e-Depot Transfer

## Purpose

@e2e exclude backend OAIS/SFTP transfer service — covered by PHPUnit
Define how OpenRegister exports objects to a Dutch e-Depot for permanent archival storage. The capability MUST package selected objects as OAIS-compliant SIP archives with MDTO-compliant XML metadata, deliver them via SFTP, REST, or OpenConnector, track per-object transfer status, and enforce read-only state on transferred objects so the e-Depot remains the authoritative copy. This satisfies Archiefwet 1995 obligations for transferring permanent records to the receiving archival institution.

## Requirements

### Requirement: The system MUST generate MDTO-compliant XML metadata per object
Each object selected for e-Depot transfer MUST have its metadata exported as XML that validates against MDTO-XML 1.0.1, the Nationaal Archief's schema, vendored at `lib/Resources/mdto/MDTO-XML1.0.1.xsd`. How that is held true is the requirement "Generated MDTO documents MUST validate against the vendored MDTO-XML 1.0.1 XSD" below.

#### Scenario: Generate MDTO XML for a single object
- **WHEN** object `zaak-123` with complete archival metadata is selected for MDTO export
- **THEN** the system MUST produce an XML document with root element `mdto:MDTO` in namespace `https://www.nationaalarchief.nl/mdto`, holding exactly one `mdto:informatieobject`
- **AND** the informatieobject MUST include `identificatie` (object UUID, with the organisation identifier as `identificatieBron`), `naam` (object title, else its UUID), `waardering` (from `archiefnominatie`, as a term of the closed Waarderingen list), `archiefvormer` (the organisation from app settings) and `beperkingGebruik`
- **AND** it MUST include `bewaartermijn` as a `termijnGegevens`, which MDTO makes optional and openregister requires as local policy
- **AND** the document MUST validate against the vendored MDTO XSD

#### Scenario: MDTO XML includes file references
- **WHEN** object `zaak-123` has 2 associated files (original.docx and rendition.pdf)
- **THEN** the informatieobject document MUST reference each file with a `heeftRepresentatie`, and MUST NOT embed them
- **AND** each file MUST get its own MDTO document with root `mdto:MDTO` holding one `mdto:bestand`, carrying `identificatie`, `naam`, `omvang` (bytes), `bestandsformaat` (the IANA media type), `checksum` (SHA-256 with `checksumDatum`) and `isRepresentatieVan` pointing back at the informatieobject
- **AND** each file document MUST validate against the vendored MDTO XSD

#### Scenario: MDTO XML handles missing optional fields gracefully
- **WHEN** an object lacks optional MDTO values (e.g., no `toelichting`, no `classification`)
- **THEN** the XML MUST omit those elements (`omschrijving`, `informatiecategorie`) rather than including empty elements or placeholders
- **AND** the XML MUST still validate against the MDTO XSD
- **AND** required inputs that are missing or malformed MUST cause the export to fail with a descriptive error

### Requirement: The system MUST assemble SIP packages for e-Depot transfer
Objects approved for transfer MUST be packaged into a SIP (Submission Information Package) conforming to the OAIS reference model (ISO 14721). Each SIP MUST be a self-contained ZIP archive containing all metadata and content files needed for archival ingest.

#### Scenario: Assemble a SIP package for 5 objects
- **WHEN** a transfer list with 5 approved objects is ready for packaging
- **THEN** the system MUST generate a ZIP archive named `sip-{transferId}.zip` containing:
  - A `mets.xml` file describing the structural map of the package (file groups, div structure per object)
  - A `premis.xml` file with preservation events (creation, packaging) and SHA-256 fixity for every content file
  - A `sip-manifest.json` listing all files in the package with their relative paths, SHA-256 checksums, and sizes
  - One directory per object under `objects/{uuid}/` containing `mdto.xml`, `metadata.json` (object data snapshot), and a `content/` directory with associated files, each accompanied by its own MDTO document named `<bestandsnaam>.bestand.MDTO.xml`, as the MDTO SIP specification prescribes
- **AND** the total package MUST be integrity-verifiable by recomputing checksums from the manifest

#### Scenario: SIP package includes PDF/A renditions when available
- **WHEN** object `doc-001` has both an original file (report.docx) and a PDF/A rendition (report.pdf)
- **THEN** the SIP MUST include both files in `objects/{uuid}/content/`
- **AND** the `mets.xml` MUST distinguish between the original file group and the rendition file group

#### Scenario: SIP package handles objects without files
- **WHEN** object `record-001` has metadata but no associated files
- **THEN** the SIP MUST still include the object directory with `mdto.xml` and `metadata.json`
- **AND** the `content/` directory MUST be omitted
- **AND** the `mets.xml` MUST reflect that this object has no content files

#### Scenario: SIP package size limit triggers splitting
- **WHEN** a transfer list contains objects whose combined file size exceeds the configured maximum package size (default: 2 GB)
- **THEN** the system MUST split the transfer into multiple SIP packages
- **AND** each package MUST be independently valid with its own `mets.xml`, `premis.xml`, and `sip-manifest.json`
- **AND** a `sip-sequence.json` MUST be included in each package indicating its position (e.g., 1 of 3) and the parent transfer list UUID

### Requirement: The system MUST support transfer list management
Transfer lists MUST track which objects are pending, approved, or completed for e-Depot transfer. Transfer lists follow the same review-approve pattern as destruction lists.

#### Scenario: Automatically generate a transfer list via background job
- **WHEN** the `TransferCheckJob` runs and finds 8 objects with `archiefnominatie` = `bewaren`, `archiefactiedatum` <= today, and `archiefstatus` = `nog_te_archiveren`
- **THEN** the system MUST create a transfer list object containing references to all 8 objects
- **AND** the transfer list MUST have status `in_review`
- **AND** an `INotification` MUST be sent to users with the archivist role
- **AND** objects already on an existing transfer list with status `in_review` or `approved` MUST be excluded

#### Scenario: Archivist approves a transfer list
- **WHEN** an archivist with the `archivaris` role approves a transfer list containing 8 objects
- **THEN** the transfer list status MUST change to `approved`
- **AND** the system MUST queue a `TransferExecutionJob` to build the SIP and transmit it
- **AND** an audit trail entry MUST be created with action `archival.transfer_approved`

#### Scenario: Archivist partially excludes objects from transfer list
- **WHEN** the archivist removes 2 objects from a transfer list of 8 and approves the remaining 6
- **THEN** only the 6 approved objects MUST be included in the SIP package
- **AND** the 2 excluded objects MUST have their exclusion reason recorded
- **AND** the excluded objects MUST remain eligible for future transfer lists

#### Scenario: Reject a transfer list
- **WHEN** the archivist rejects an entire transfer list
- **THEN** no SIP package MUST be generated
- **AND** the transfer list status MUST change to `rejected`
- **AND** the archivist MUST provide a reason for rejection
- **AND** all objects on the list MUST remain eligible for future transfer lists

### Requirement: The system MUST support configurable e-Depot endpoint settings
Administrators MUST be able to configure the target e-Depot system, transport protocol, authentication, and SIP profile through the admin settings API.

#### Scenario: Configure e-Depot endpoint via API
- **WHEN** an admin sends `PUT /api/settings/edepot` with endpoint URL, authentication type (`api_key`, `certificate`, or `oauth2`), target archive identifier, and SIP profile name
- **THEN** the system MUST validate the configuration by performing a test connection
- **AND** the configuration MUST be stored in `IAppConfig` with sensitive values encrypted
- **AND** the response MUST confirm the connection test result

#### Scenario: Test e-Depot connection
- **WHEN** an admin sends `POST /api/settings/edepot/test` with the current or proposed configuration
- **THEN** the system MUST attempt to connect to the endpoint using the specified protocol
- **AND** the response MUST report success or failure with a descriptive error message

#### Scenario: Configure SIP profile
- **WHEN** an admin sets `sipProfile` to `nationaal-archief-v2`
- **THEN** the SIP package builder MUST use the corresponding profile's directory structure, naming conventions, and metadata requirements
- **AND** unknown profile names MUST be rejected with a validation error listing available profiles

### Requirement: The system MUST support multiple transport protocols for SIP delivery
SIP packages MUST be transmittable to e-Depot systems via SFTP, REST API, or OpenConnector source integration. The transport protocol MUST be configurable per e-Depot endpoint.

#### Scenario: Transfer SIP via SFTP
- **WHEN** the e-Depot is configured with transport `sftp` and the SIP package is ready
- **THEN** the system MUST upload the ZIP file to the configured SFTP path using `phpseclib`
- **AND** the system MUST verify the upload by checking remote file size matches local file size
- **AND** on success the transfer status MUST be updated to `completed`

#### Scenario: Transfer SIP via REST API
- **WHEN** the e-Depot is configured with transport `rest_api`
- **THEN** the system MUST POST the SIP as a multipart upload to the configured endpoint URL
- **AND** the system MUST include authentication headers as configured (API key or OAuth2 bearer token)
- **AND** the system MUST parse the response to determine acceptance or rejection per object

#### Scenario: Transfer SIP via OpenConnector
- **WHEN** the e-Depot is configured with transport `openconnector` and a source ID is specified
- **THEN** the system MUST create a synchronization job in OpenConnector with the SIP file as payload
- **AND** the transfer status MUST be tracked via OpenConnector's call log

#### Scenario: Transport failure with retry
- **WHEN** a SIP transfer fails due to a transient error (network timeout, connection refused)
- **THEN** the system MUST retry up to 3 times with exponential backoff (30s, 120s, 480s)
- **AND** if all retries fail, the transfer list status MUST change to `failed`
- **AND** the failure details MUST be stored in the transfer list and per-object `retention.transferErrors[]`
- **AND** an `INotification` MUST be sent to the archivist

### Requirement: The system MUST track transfer status per object
Each object involved in an e-Depot transfer MUST have its transfer status tracked in the `retention` field, enabling status queries and preventing duplicate transfers.

#### Scenario: Successful transfer updates object status
- **WHEN** the e-Depot confirms acceptance of object `zaak-123`
- **THEN** `retention.archiefstatus` MUST be set to `overgebracht`
- **AND** `retention.eDepotReferentie` MUST store the e-Depot's reference identifier for this object
- **AND** `retention.transferDate` MUST store the ISO 8601 timestamp of the transfer
- **AND** an audit trail entry MUST be created with action `archival.transferred`

#### Scenario: Partial transfer failure tracks per-object errors
- **WHEN** a SIP containing 5 objects is sent and the e-Depot accepts 3 but rejects 2
- **THEN** the 3 accepted objects MUST be marked as `overgebracht` with their e-Depot references
- **AND** the 2 rejected objects MUST remain in status `nog_te_archiveren`
- **AND** each rejected object MUST have the rejection reason stored in `retention.transferErrors[]` with timestamp and error message
- **AND** an `INotification` MUST be sent to the archivist detailing the partial failure

#### Scenario: Query objects by transfer status
- **WHEN** a user queries `GET /api/objects/{register}/{schema}?retention.archiefstatus=overgebracht`
- **THEN** the system MUST return only objects that have been successfully transferred
- **AND** the response MUST include the `eDepotReferentie` in the retention field

### Requirement: Transferred objects MUST be read-only
Objects with `archiefstatus` set to `overgebracht` MUST NOT be modifiable. The authoritative copy now resides in the e-Depot.

#### Scenario: Reject update to transferred object
- **WHEN** a user attempts to update object `zaak-123` which has `archiefstatus` = `overgebracht`
- **THEN** the system MUST reject the request with HTTP 409 Conflict
- **AND** the response body MUST include error code `OBJECT_TRANSFERRED` and a message indicating the object has been transferred to the e-Depot

#### Scenario: Reject deletion of transferred object
- **WHEN** a user attempts to delete object `zaak-123` which has `archiefstatus` = `overgebracht`
- **THEN** the system MUST reject the request with HTTP 409 Conflict
- **AND** the response MUST indicate that transferred objects cannot be deleted from the source system

#### Scenario: Read access to transferred objects is preserved
- **WHEN** a user requests `GET /api/objects/{register}/{schema}/{id}` for a transferred object
- **THEN** the system MUST return the object with all its metadata
- **AND** the response MUST include `retention.archiefstatus` = `overgebracht` and `retention.eDepotReferentie`

### Requirement: The system MUST log all transfer actions in the audit trail
Every transfer lifecycle event MUST produce an immutable audit trail entry for legal accountability and traceability.

#### Scenario: Audit trail for transfer initiation
- **WHEN** an archivist approves a transfer list and the transfer is initiated
- **THEN** an audit trail entry MUST be created with action `archival.transfer_initiated` containing the transfer list UUID, archivist user ID, number of objects, and target e-Depot identifier

#### Scenario: Audit trail for successful transfer
- **WHEN** an object is successfully transferred to the e-Depot
- **THEN** an audit trail entry MUST be created with action `archival.transferred` containing the object UUID, transfer list UUID, e-Depot reference, and timestamp

#### Scenario: Audit trail for transfer failure
- **WHEN** a transfer fails (partially or completely)
- **THEN** an audit trail entry MUST be created with action `archival.transfer_failed` containing the transfer list UUID, error details, number of failed objects, and transport protocol used

### Requirement: The MDTO generator MUST report the limits of its own validation

`MdtoXmlGenerator` MUST NOT present its pre-generation check as an MDTO
validity check. The check MUST be named and documented for what it is (the
inputs the generator needs, refused in any form the XSD would reject), and
the generator MUST separately report which MDTO-required elements carry the
standard's "not recorded" term rather than a value the object supplied.
Validity against the schema is established by the XSD test, not by this
check.

The required-element list is taken from two authoritative sources published by
the Nationaal Archief: the MDTO metagegevensschema
(`github.com/NationaalArchief/MDTO-Metagegevensschema`, `markdown/attributenKlassen.md`)
and the XML syntax `MDTO-XML1.0.1.xsd`
(`nationaalarchief.nl/sites/default/mdto/MDTO-XML1.0.1.xsd`). Against those:

- `identificatie` is `minOccurs="1"` on `objectType`, and both
  `identificatieKenmerk` and `identificatieBron` are `minOccurs="1"` on
  `identificatieGegevens`. The schema states for `identificatie`:
  "Verplicht | Ja".
- `naam` is `minOccurs="1"` on `objectType`. The schema states:
  "Verplicht | Ja".
- `waardering` is `minOccurs="1"` on `informatieobjectType`. The schema
  states: "Verplicht | Ja".
- `archiefvormer` is `minOccurs="1" maxOccurs="unbounded"`. The schema states:
  "Verplicht | Ja".
- `beperkingGebruik` is `minOccurs="1" maxOccurs="unbounded"`. The schema
  states: "Verplicht | Ja". The XSD carries a dated comment recording the
  change: "20230109 ONDERWERP: aanpassing schema. WAT: maxOccurs bij attribuut
  archiefvormer aangepast naar 'unbounded', minOccurs bij attribuut
  beperkingGebruik aangepast naar '1'."
- On `bestandType`: `omvang`, `bestandsformaat`, `checksum` and
  `isRepresentatieVan` are all `minOccurs="1"`, and `checksumGegevens`
  requires `checksumAlgoritme`, `checksumWaarde` and `checksumDatum`.
- `bewaartermijn` is `minOccurs="0"` and the schema states "Verplicht | Ja,
  indien bekend". openregister's stricter refusal to export without one is a
  LOCAL policy and MUST be documented as such rather than attributed to MDTO.

#### Scenario: A generator input is missing
- **WHEN** an object has no `retention.archiefnominatie`, or a file entry has no `checksum`
- **THEN** generation MUST fail with an error naming the missing input
- **AND** the error MUST state that the check covers generator inputs only and is not an MDTO validity check

#### Scenario: A generator input is malformed
- **WHEN** `archiefnominatie` is outside the closed Waarderingen list, `bewaartermijn` is not an `xsd:duration` (for example `P2W`), or a file's checksum is not a SHA-256 or its `checksumDate` is not an `xsd:dateTime`
- **THEN** generation MUST fail naming the input, rather than write a document the XSD rejects or a label the list does not contain

#### Scenario: A required MDTO element cannot be sourced
- **WHEN** an object declares no use restriction and carries no active legal hold
- **THEN** generation MUST still succeed, with `beperkingGebruik` carrying the BeperkingGebruikTypeLijst term `Nader te bepalen`
- **AND** the generator MUST report `beperkingGebruik` as carrying that default, so it is never read as a recorded fact

### Requirement: The system MUST emit MDTO aggregatieniveau beperkingGebruik and dekkingInTijd from their declared sources

`aggregatieniveau` and `dekkingInTijd` MUST be emitted when the object
carries a value for them, and MUST be omitted entirely when it does not. A
placeholder or a derived guess MUST NOT be written.

`beperkingGebruik` is the exception, because the XSD requires it
(`minOccurs="1"`). When no source supplies one it MUST carry the
BeperkingGebruikTypeLijst term `Nader te bepalen`, defined by the standard as
"Er is mogelijk een beperking, maar de aard daarvan is niet vastgelegd als
type beperking en niet vastgelegd in de metagegevens". That term states the
absence of a recorded restriction; it is not a guess at one.

Sources, in order: the abstract English key on the `retention` block
(`aggregationLevel`, `useRestriction`, `temporalCoverage`), then the Dutch key
on the `tmlo` block (`aggregatieniveau`, `beperkingGebruik`, `dekkingInTijd`).
For `beperkingGebruik` only, an ACTIVE `retention.legalHold` is a further
source and is reported with the `BeperkingGebruikTypeLijst` term "Overig",
which the standard defines as "Niet nader gespecificeerde beperking. Deze
waarde wordt gebruikt als er geen ander geschikt type gedefinieerd is en er in
de documentatie wel een beperking is omschreven."

`aggregatieniveau` values MUST come from the `Aggregatieniveaus`
begrippenlijst, whose terms are Archief, Serie, Dossier and Archiefstuk.

`dekkingInTijd` MUST be emitted only when the declared entry supplies both a
type and a start date, because `dekkingInTijdType` and
`dekkingInTijdBegindatum` are both `minOccurs="1"`. Both dates MUST be an
`xsd:gYear`, `xsd:gYearMonth` or `xsd:date`, the union the XSD declares. The record's own `created`
timestamp MUST NOT be used: MDTO defines dekkingInTijd as "Datum waar de
inhoud van het informatieobject betrekking op heeft".

#### Scenario: Declared aggregation level is exported
- **WHEN** an object carries `retention.aggregationLevel` = `Dossier`
- **THEN** the XML MUST contain `mdto:aggregatieniveau` with `begripLabel` `Dossier` and `begripBegrippenlijst/verwijzingNaam` `Aggregatieniveaus`

#### Scenario: An unsourced element is omitted
- **WHEN** an object carries no aggregation level in either the retention or the tmlo block
- **THEN** the XML MUST NOT contain an `mdto:aggregatieniveau` element at all, empty or otherwise

#### Scenario: An active legal hold is exported as a use restriction
- **WHEN** an object carries `retention.legalHold` with `active` true and a reason
- **THEN** the XML MUST contain `mdto:beperkingGebruik` with `beperkingGebruikType/begripLabel` `Overig` and the reason in `beperkingGebruikNadereBeschrijving`

#### Scenario: No known restriction is stated as not recorded
- **WHEN** an object declares no restriction and any legal hold has been released
- **THEN** the XML MUST contain `mdto:beperkingGebruik` with `beperkingGebruikType/begripLabel` `Nader te bepalen`, and MUST NOT carry `Overig`

### Requirement: The system MUST derive MDTO event entries from the audit trail

MDTO `event` entries MUST be derived from `openregister_audit_trails` rather
than from a separate store. The audit trail records action, actor, timestamp
and a hash chain, which is the event history MDTO asks for.

The trail is not itself an archival event log, so the derivation MUST be
bounded on three axes:

- An ALLOW-LIST of audit actions, so an action nobody has mapped cannot start
  appearing in an export: `create`, `update`, `archival.transferred`,
  `archival.destroyed` and `archival.legal_hold_placed`.
- Labels drawn only from MDTO's `EventTypeLijst`, mapping to `Creatie`,
  `Wijziging`, `Overbrenging`, `Vernietigen` and `Bevriezing` respectively.
- A hard cap on the number of events emitted for one object, applied in the
  database query, with the record's creation event kept when the cap truncates.

`read`, `list`, `search` and `delete` MUST NOT become events. `delete` in
particular is written for a reversible trash operation as well as a purge,
while MDTO defines `Vernietigen` as "het blijvend ontoegankelijk maken van die
informatie".

`eventResultaat` MUST NOT be populated from the audit hash chain: the hash
attests to the integrity of the audit row, not to an outcome of the event.

#### Scenario: Create and update rows become events
- **WHEN** an object's audit trail holds one `create` row and two `update` rows
- **THEN** the XML MUST contain three `mdto:event` elements with `eventType/begripLabel` `Creatie`, `Wijziging` and `Wijziging`, oldest first

#### Scenario: Reads are not events
- **WHEN** an object's audit trail holds `read` and `list` rows
- **THEN** those rows MUST NOT produce `mdto:event` elements

#### Scenario: The event list is bounded
- **WHEN** an object's audit trail holds more qualifying rows than the cap
- **THEN** the XML MUST contain at most the capped number of `mdto:event` elements
- **AND** the record's `Creatie` event MUST be among them

### Requirement: Generated MDTO documents MUST validate against the vendored MDTO-XML 1.0.1 XSD

Every document `MdtoXmlGenerator` produces, the informatieobject document and
each file's bestand document, MUST validate against
`lib/Resources/mdto/MDTO-XML1.0.1.xsd`. That file MUST be a verbatim copy of
the Nationaal Archief's schema, with its source, version, checksum and licence
recorded beside it in `version.json`. The licence is CC BY-SA: "Het Nationaal
Archief hanteert de licentie CC BY SA voor al diens kennisproducten en dus ook
voor MDTO" (Forum Standaardisatie, Intakeadvies MDTO, FS-20241002.3C).

`MdtoXmlGeneratorXsdTest` MUST hold this true by running
`DOMDocument::schemaValidate()` over documents for representative objects,
with and without files and with and without the optional elements, and MUST
name libxml's own error lines when a document fails. It MUST include a
negative control (a document the schema rejects) and a positive control (the
Nationaal Archief's own example documents), so neither a schema that failed
to load nor a validator that accepts anything can make it pass. The vendored
schema's checksum MUST be pinned, so the file the export is held to cannot
change unnoticed.

Where MDTO makes a value a term from a begrippenlijst, the XSD sees only a
string. Those rules are enforced by the generator instead: `waardering` MUST
be a term of the CLOSED Waarderingen list (`B` Blijvend te bewaren, `V`
Tijdelijk te bewaren, `N` Nader te bepalen), onto which the four
`archiefnominatie` values the codebase writes map, and any other value MUST be
refused.

`checksumDatum`, defined by MDTO as "Datum waarop de checksum is gemaakt",
MUST be the moment the checksum was computed. Because a stored checksum
carries no date, the transfer service MUST hash every file at packaging and
date that moment, and MUST refuse a file whose stored SHA-256 does not match
its bytes rather than replace the stale value silently.

#### Scenario: Representative documents validate
- **WHEN** documents are generated for a minimal object, an object with files, and an object carrying every optional element
- **THEN** every informatieobject document and every bestand document MUST validate against the vendored XSD

#### Scenario: A deviation from the schema is caught and named
- **WHEN** the generator emits a document the schema rejects
- **THEN** `MdtoXmlGeneratorXsdTest` MUST fail, and its message MUST quote libxml's error naming the offending element

#### Scenario: A stored checksum no longer matches the file
- **WHEN** a file reference carries a SHA-256 that differs from the SHA-256 of the file's bytes
- **THEN** the file MUST be refused as a fixity failure, and the object excluded from that transfer with a logged reason

**Known gap: a second MDTO exporter does not validate.** `TmloService::generateMdtoXml()` serves `GET /api/objects/{register}/{schema}/{id}/export/mdto`
and is governed by `tmlo-export`, not by this capability. Measured on
2026-09-11 against the same vendored XSD, it does not validate: its root is
`mdto:informatieobject`, and it emits `archiefactiedatum`, `archiefstatus` and
`vernietigingsCategorie`, which are TMLO fields and not MDTO elements. The
`tmlo-export` spec mandates those elements, so correcting the exporter is a
decision about that spec, recorded there.
