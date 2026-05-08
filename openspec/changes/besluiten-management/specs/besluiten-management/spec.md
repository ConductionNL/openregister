---
status: draft
---
# Besluiten Management

## Purpose
Implement formal decision management (besluiten) conforming to the ZGW BRC (Besluiten Registratie Component) standard, enabling Dutch government organizations to register, track, publish, and withdraw formal administrative decisions as first-class entities within OpenRegister. Decisions MUST be linked to decision types from a configurable catalog, support full lifecycle management from concept through definitive to withdrawn states, and integrate with case management (zaak-besluit linking), document management (besluit-informatieobject linking), archival calculations, and publication workflows under the Wet open overheid (Woo). Every decision MUST maintain an immutable audit trail documenting creation, modification, publication, and withdrawal actions to satisfy legal accountability requirements under the Algemene wet bestuursrecht (Awb).

**Source**: ZGW BRC API standard v1.0.2 (VNG Realisatie); gap identified in cross-platform competitive analysis (Dimpact ZAC, OpenZaak, Valtimo); Procest roles-decisions spec alignment.

## ADDED Requirements

### Requirement: Besluit Entity Schema (ZGW BRC Compliant)
Decisions (besluiten) SHALL be stored as OpenRegister objects with a dedicated schema whose properties conform to the ZGW BRC data model. The schema MUST include all fields defined in the BRC standard to ensure interoperability with other ZGW-compliant systems.

The besluit schema MUST define the following properties:

| Property | Type | ZGW Mapping | Required | Description |
|----------|------|-------------|----------|-------------|
| `identificatie` | string (max 50) | `identificatie` | auto-gen | Human-readable decision identifier, unique per verantwoordelijkeOrganisatie |
| `verantwoordelijkeOrganisatie` | string (RSIN, 9 digits) | `verantwoordelijke_organisatie` | Yes | RSIN of the responsible organization |
| `besluittype` | reference (UUID) | `besluittype` | Yes | Reference to a BesluitType object in the catalog |
| `zaak` | reference (UUID) | `zaak` | No | Reference to the originating case (optional for standalone decisions) |
| `datum` | date (ISO 8601) | `datum` | Yes | Decision date (MUST be today or earlier) |
| `toelichting` | string | `toelichting` | No | Explanation or motivation for the decision |
| `bestuursorgaan` | string (max 50) | `bestuursorgaan` | No | Governing body (e.g., Burgemeester, College van B&W, Gemeenteraad) |
| `ingangsdatum` | date (ISO 8601) | `ingangsdatum` | Yes | Effective start date of the decision |
| `vervaldatum` | date (ISO 8601) | `vervaldatum` | No | Expiry date of the decision |
| `vervalreden` | enum | `vervalreden` | No | Reason for expiry: `tijdelijk`, `ingetrokken_overheid`, `ingetrokken_belanghebbende` |
| `publicatiedatum` | date (ISO 8601) | `publicatiedatum` | No | Date the decision was published |
| `verzenddatum` | date (ISO 8601) | `verzenddatum` | No | Date the decision was sent to the betrokkene |
| `uiterlijkeReactiedatum` | date (ISO 8601) | `uiterlijke_reactiedatum` | No | Deadline for objection/response |

Key constraint: The combination of `identificatie` and `verantwoordelijkeOrganisatie` MUST be unique across all besluit objects.

#### Scenario: Create a besluit with all required fields
- **GIVEN** a register `procest` with a `besluit` schema conforming to the ZGW BRC data model
- **AND** a besluittype `omgevingsvergunning-verleend` exists in the catalog
- **WHEN** the user creates a besluit with:
  - `besluittype`: reference to `omgevingsvergunning-verleend`
  - `verantwoordelijkeOrganisatie`: `002220647`
  - `datum`: `2026-03-15`
  - `ingangsdatum`: `2026-03-16`
  - `toelichting`: `Vergunning verleend conform aanvraag`
  - `bestuursorgaan`: `College van B&W`
- **THEN** the besluit MUST be created as an OpenRegister object in the `besluit` schema
- **AND** an `identificatie` MUST be auto-generated based on the datum and a sequence number
- **AND** the besluit MUST be retrievable by its UUID

#### Scenario: Auto-generate identificatie when not provided
- **GIVEN** a besluit is being created without an explicit `identificatie`
- **AND** the `verantwoordelijkeOrganisatie` is `002220647`
- **AND** the `datum` is `2026-03-15`
- **WHEN** the besluit is saved
- **THEN** the system MUST generate a unique `identificatie` (e.g., `BESLUIT-2026-0001`)
- **AND** the combination `(BESLUIT-2026-0001, 002220647)` MUST be unique

#### Scenario: Reject duplicate identificatie for same organisation
- **GIVEN** a besluit with `identificatie` `BESLUIT-2026-0001` and `verantwoordelijkeOrganisatie` `002220647` already exists
- **WHEN** a new besluit is created with the same `identificatie` and `verantwoordelijkeOrganisatie`
- **THEN** the system MUST reject the creation with an error indicating the uniqueness constraint violation

#### Scenario: Reject datum in the future
- **GIVEN** today is `2026-03-15`
- **WHEN** the user creates a besluit with `datum` set to `2026-03-20`
- **THEN** the system MUST reject the creation
- **AND** the error message MUST indicate that `datum` cannot be in the future

#### Scenario: Create a standalone besluit without zaak reference
- **GIVEN** a policy decision that is not tied to a specific case
- **WHEN** the user creates a besluit with `zaak` set to null
- **THEN** the besluit MUST be created successfully as a standalone decision
- **AND** the besluit MUST still require `besluittype`, `datum`, and `ingangsdatum`

---

### Requirement: Besluit Lifecycle (Concept to Definitief to Ingetrokken)
The system SHALL track the lifecycle state of each besluit through three phases: concept (draft), definitief (final/published), and ingetrokken (withdrawn). Lifecycle transitions MUST be validated to prevent illegal state changes, and each transition MUST be recorded in the audit trail.

#### Scenario: Create a besluit in concept state
- **GIVEN** the user creates a new besluit
- **WHEN** the besluit is saved without setting `publicatiedatum` or `verzenddatum`
- **THEN** the besluit MUST be in the `concept` lifecycle state
- **AND** the besluit MUST be editable (all fields modifiable)
- **AND** the besluit MUST NOT be visible via the public API

#### Scenario: Transition from concept to definitief
- **GIVEN** a besluit in `concept` state
- **WHEN** the user sets the `verzenddatum` to `2026-03-16`
- **AND** the besluit has all required fields populated (besluittype, datum, ingangsdatum)
- **THEN** the besluit MUST transition to `definitief` state
- **AND** the audit trail MUST record the transition with timestamp and user
- **AND** core fields (`besluittype`, `datum`, `verantwoordelijkeOrganisatie`) MUST become immutable

#### Scenario: Transition from definitief to ingetrokken
- **GIVEN** a besluit in `definitief` state with verzenddatum `2026-03-16`
- **WHEN** the authorized user withdraws the besluit with vervalreden `ingetrokken_overheid`
- **THEN** the `vervaldatum` MUST be set to the current date
- **AND** the `vervalreden` MUST be set to `ingetrokken_overheid`
- **AND** the besluit MUST transition to `ingetrokken` state
- **AND** the audit trail MUST record the withdrawal with reason

#### Scenario: Prevent re-activation of a withdrawn besluit
- **GIVEN** a besluit in `ingetrokken` state
- **WHEN** the user attempts to clear `vervalreden` or change `vervaldatum` to a future date
- **THEN** the system MUST reject the modification
- **AND** the error message MUST indicate that withdrawn decisions cannot be reactivated

#### Scenario: Prevent deletion of a definitief besluit
- **GIVEN** a besluit in `definitief` state with linked informatieobjecten
- **WHEN** the user attempts to delete the besluit
- **THEN** the system MUST reject the deletion
- **AND** the error message MUST indicate that final decisions must be withdrawn, not deleted

---

### Requirement: BesluitType Configuration via Catalog
Decision types (besluittypen) SHALL be defined as OpenRegister objects in a dedicated `besluittype` schema within the catalog, analogous to zaaktype configuration. Each besluittype MUST define reaction periods, publication requirements, and allowed information object types.

The besluittype schema MUST include:

| Property | Type | ZGW Mapping | Required |
|----------|------|-------------|----------|
| `omschrijving` | string (max 255) | `omschrijving` | Yes |
| `omschrijvingGeneriek` | string | `omschrijving_generiek` | No |
| `besluitcategorie` | string | `besluitcategorie` | No |
| `reactietermijn` | string (ISO 8601 duration) | `reactietermijn` | No |
| `publicatieIndicatie` | boolean | `publicatie_indicatie` | Yes |
| `publicatietermijn` | string (ISO 8601 duration) | `publicatietermijn` | No |
| `informatieobjecttypen` | array of references | `informatieobjecttypen` | No |
| `zaaktypen` | array of references | `zaaktypen` | No |
| `beginGeldigheid` | date | `begin_geldigheid` | Yes |
| `eindeGeldigheid` | date | `einde_geldigheid` | No |
| `concept` | boolean | `concept` | Yes |

#### Scenario: Define a besluittype with reaction period
- **GIVEN** an admin configuring the besluittype catalog
- **WHEN** they create a besluittype:
  - `omschrijving`: `Omgevingsvergunning verleend`
  - `besluitcategorie`: `vergunning`
  - `reactietermijn`: `P42D` (42 days / 6 weeks)
  - `publicatieIndicatie`: `true`
  - `publicatietermijn`: `P14D` (14 days)
  - `beginGeldigheid`: `2026-01-01`
  - `concept`: `false`
- **THEN** the besluittype MUST be available for selection when creating besluiten
- **AND** the reactietermijn MUST be used to auto-calculate `uiterlijkeReactiedatum`

#### Scenario: Define a besluittype without publication requirement
- **GIVEN** an admin creating a besluittype for internal decisions
- **WHEN** they create a besluittype with `publicatieIndicatie`: `false`
- **THEN** besluiten of this type MUST NOT require `publicatiedatum`
- **AND** if a user sets `publicatiedatum` on a besluit of this type, the system MUST reject it with a validation error

#### Scenario: Link besluittype to specific zaaktypen
- **GIVEN** a besluittype `Omgevingsvergunning verleend`
- **WHEN** the admin links it to zaaktypen `Omgevingsvergunning` and `Bouwvergunning`
- **THEN** only cases of those zaaktypen MUST be able to create besluiten with this besluittype
- **AND** attempting to create a besluit with this besluittype on a case of zaaktype `Klacht` MUST be rejected

#### Scenario: Retire a besluittype by setting einde geldigheid
- **GIVEN** a besluittype `Subsidie toekenning` with `beginGeldigheid` `2024-01-01`
- **WHEN** the admin sets `eindeGeldigheid` to `2026-03-31`
- **THEN** the besluittype MUST remain valid for existing besluiten
- **AND** after `2026-03-31`, the besluittype MUST NOT be selectable for new besluiten

---

### Requirement: Besluit-Zaak Linking
Each besluit SHOULD be linkable to a case (zaak) through a bidirectional reference. When a besluit is linked to a zaak, the system SHALL create a corresponding ZaakBesluit reference on the zaak side to maintain referential integrity, consistent with the ZGW cross-API synchronization pattern.

#### Scenario: Link a besluit to a zaak on creation
- **GIVEN** a case `vergunning-1` of zaaktype `Omgevingsvergunning`
- **AND** the zaaktype has besluittype `Omgevingsvergunning verleend` in its `besluittypen` list
- **WHEN** the user creates a besluit with `zaak` referencing `vergunning-1`
- **THEN** the besluit MUST be created with the zaak reference
- **AND** the zaak object MUST be updated to include the besluit reference in its `besluiten` array
- **AND** the besluit MUST be visible in the Decisions section of the case detail view

#### Scenario: Validate besluittype belongs to zaaktype
- **GIVEN** a case `klacht-1` of zaaktype `Klacht behandeling`
- **AND** the zaaktype does NOT include besluittype `Omgevingsvergunning verleend`
- **WHEN** the user creates a besluit with `zaak` referencing `klacht-1` and `besluittype` referencing `Omgevingsvergunning verleend`
- **THEN** the system MUST reject the creation
- **AND** the error MUST indicate that the besluittype is not allowed for this zaaktype

#### Scenario: Update zaak reference on an existing besluit
- **GIVEN** a besluit `B-001` linked to zaak `vergunning-1`
- **WHEN** the user changes the `zaak` reference to `vergunning-2`
- **THEN** the previous zaak `vergunning-1` MUST have its besluit reference removed
- **AND** the new zaak `vergunning-2` MUST have the besluit reference added
- **AND** the audit trail MUST record the zaak change with both old and new references

#### Scenario: Display multiple besluiten on a case
- **GIVEN** case `vergunning-1` has three linked besluiten:
  - `B-001`: `Omgevingsvergunning verleend` (datum: 2026-03-10, ingangsdatum: 2026-03-15)
  - `B-002`: `Voorwaardelijk besluit` (datum: 2026-02-20, ingangsdatum: 2026-03-01)
  - `B-003`: `Besluit ingetrokken` (datum: 2026-04-01, vervalreden: ingetrokken_overheid)
- **WHEN** the user views the case detail
- **THEN** all three besluiten MUST be displayed in the Decisions section
- **AND** besluiten MUST be sorted by `datum` descending
- **AND** each besluit MUST show: identificatie, besluittype omschrijving, datum, lifecycle state indicator

---

### Requirement: Besluit-InformatieObject Linking
Each besluit SHALL support linking to one or more informatieobjecten (documents) via a `besluitInformatieObject` join entity. This linking pattern follows the ZGW BRC standard where the BRC leads the relationship and the DRC mirrors it through ObjectInformatieObject records.

#### Scenario: Link a document to a besluit
- **GIVEN** a besluit `B-001` for `Omgevingsvergunning verleend`
- **WHEN** the user uploads a formal decision letter `beschikking-2026-0001.pdf`
- **THEN** a `besluitInformatieObject` record MUST be created linking the besluit to the document
- **AND** the document MUST be accessible from both the besluit detail and the case dossier
- **AND** the `aardRelatie` MUST be set to `legt_vast` (documents the decision)

#### Scenario: Link multiple documents to a besluit
- **GIVEN** a besluit `B-001`
- **WHEN** the user links three documents: the decision letter, a site plan, and an environmental assessment
- **THEN** three `besluitInformatieObject` records MUST be created
- **AND** all three documents MUST be listed in the besluit detail view
- **AND** each document MUST display its title, type, and creation date

#### Scenario: Validate informatieobjecttype against besluittype
- **GIVEN** besluittype `Omgevingsvergunning verleend` allows informatieobjecttypen: `Beschikking`, `Bijlage`
- **AND** document `rapport.pdf` has informatieobjecttype `Intern rapport`
- **WHEN** the user attempts to link `rapport.pdf` to a besluit of this type
- **THEN** the system MUST reject the link with a validation error
- **AND** the error MUST indicate that the informatieobjecttype is not allowed for this besluittype

#### Scenario: Remove a document link from a besluit
- **GIVEN** a besluit `B-001` with linked document `beschikking-2026-0001.pdf`
- **WHEN** the user removes the document link
- **THEN** the `besluitInformatieObject` record MUST be deleted
- **AND** the document itself MUST NOT be deleted (only the link is removed)
- **AND** the corresponding ObjectInformatieObject in the DRC MUST also be removed

---

### Requirement: Verantwoordelijke Organisatie Tracking
Each besluit SHALL record the RSIN (Rechtspersonen en Samenwerkingsverbanden Identificatienummer) of the responsible organization. This field MUST be validated as a 9-digit number and SHALL be used together with the identificatie to ensure uniqueness across organizations.

#### Scenario: Set verantwoordelijke organisatie from system configuration
- **GIVEN** the OpenRegister instance is configured with default RSIN `002220647` (Gemeente Utrecht)
- **WHEN** a new besluit is created without explicitly setting `verantwoordelijkeOrganisatie`
- **THEN** the system MUST default to the configured RSIN `002220647`
- **AND** the RSIN MUST be stored on the besluit object

#### Scenario: Override verantwoordelijke organisatie for mandated decisions
- **GIVEN** a besluit is being created by Gemeente Utrecht on behalf of the Omgevingsdienst (RSIN `003456789`)
- **WHEN** the user explicitly sets `verantwoordelijkeOrganisatie` to `003456789`
- **THEN** the system MUST accept the override
- **AND** the uniqueness constraint for `identificatie` MUST be scoped to the new RSIN

#### Scenario: Reject invalid RSIN format
- **GIVEN** the user sets `verantwoordelijkeOrganisatie` to `12345` (too short) or `abcdefghi` (non-numeric)
- **WHEN** the besluit is submitted
- **THEN** the system MUST reject the submission
- **AND** the error MUST indicate that the RSIN must be exactly 9 digits

---

### Requirement: Ingangsdatum/Vervaldatum Handling
The system SHALL track the validity period (werkingsperiode) of each besluit through `ingangsdatum` (effective start) and `vervaldatum` (expiry). Changes to these dates MUST trigger archival recalculation on the linked zaak when the zaaktype uses `ingangsdatum_besluit` or `vervaldatum_besluit` as the archival date derivation method (afleidingswijze).

#### Scenario: Calculate archival date from ingangsdatum when afleidingswijze is ingangsdatum_besluit
- **GIVEN** a zaak `vergunning-1` with zaaktype where `afleidingswijze` is `ingangsdatum_besluit`
- **AND** the zaak has two besluiten with `ingangsdatum` `2026-03-15` and `2026-04-01`
- **WHEN** the archival date is calculated
- **THEN** the system MUST use the maximum `ingangsdatum` across all linked besluiten (`2026-04-01`)
- **AND** this date MUST be the brondatum for the archival calculation

#### Scenario: Calculate archival date from vervaldatum when afleidingswijze is vervaldatum_besluit
- **GIVEN** a zaak `vergunning-1` with zaaktype where `afleidingswijze` is `vervaldatum_besluit`
- **AND** the zaak has two besluiten with `vervaldatum` `2031-03-15` and `2029-12-31`
- **WHEN** the archival date is calculated
- **THEN** the system MUST use the maximum `vervaldatum` across all linked besluiten (`2031-03-15`)

#### Scenario: Trigger archival recalculation on vervaldatum change
- **GIVEN** a besluit `B-001` linked to zaak `vergunning-1`
- **AND** the zaak's zaaktype uses `vervaldatum_besluit` as afleidingswijze
- **WHEN** the user updates `vervaldatum` from `2031-03-15` to `2033-06-30`
- **THEN** the system MUST trigger archival date recalculation on `vergunning-1`
- **AND** the new archival brondatum MUST reflect the updated vervaldatum

#### Scenario: Display validity period on besluit detail
- **GIVEN** a besluit with `ingangsdatum` `2026-03-16` and `vervaldatum` `2031-03-16`
- **AND** today is `2026-06-15`
- **WHEN** the user views the besluit detail
- **THEN** the system MUST display the validity period as `16 maart 2026 -- 16 maart 2031`
- **AND** the status MUST show `Actief` with remaining time `4 jaar, 9 maanden resterend`

#### Scenario: Display besluit without vervaldatum as indefinitely valid
- **GIVEN** a besluit with `ingangsdatum` `2026-03-16` and no `vervaldatum`
- **WHEN** the user views the besluit detail
- **THEN** the system MUST display `Geldig vanaf 16 maart 2026` with no end date
- **AND** the besluit MUST be treated as indefinitely valid

---

### Requirement: Vervalreden Tracking
When a besluit expires or is withdrawn, the system SHALL record the reason (vervalreden) using the ZGW standard enumeration. The vervalreden MUST be one of three values: `tijdelijk` (temporary decision expired naturally), `ingetrokken_overheid` (withdrawn by the governing authority), or `ingetrokken_belanghebbende` (withdrawn at the request of the interested party).

#### Scenario: Record expiry by natural end of temporary decision
- **GIVEN** a besluit `B-001` with `vervaldatum` `2026-12-31`
- **WHEN** the vervaldatum passes and the system detects the expiry during a scheduled check
- **THEN** the `vervalreden` MUST be set to `tijdelijk`
- **AND** the besluit lifecycle state MUST change to reflect the expiry

#### Scenario: Record withdrawal by the governing authority
- **GIVEN** a definitief besluit `B-001` for `Omgevingsvergunning verleend`
- **WHEN** the authorized user withdraws the besluit with explanation `Besluit ingetrokken wegens onregelmatigheden in de aanvraag`
- **THEN** the `vervalreden` MUST be set to `ingetrokken_overheid`
- **AND** the `vervaldatum` MUST be set to the current date
- **AND** the `toelichting` MUST be updated with: `Overheid: Besluit ingetrokken wegens onregelmatigheden in de aanvraag`

#### Scenario: Record withdrawal at request of the interested party
- **GIVEN** a definitief besluit `B-002` for a granted permit
- **WHEN** the permit holder requests withdrawal
- **AND** the authorized user processes the withdrawal with vervalreden `ingetrokken_belanghebbende`
- **THEN** the `vervalreden` MUST be set to `ingetrokken_belanghebbende`
- **AND** the `toelichting` MUST be updated with: `Belanghebbende: [withdrawal explanation]`

#### Scenario: Reject vervalreden without vervaldatum
- **GIVEN** a besluit without a `vervaldatum`
- **WHEN** the user attempts to set `vervalreden` to `tijdelijk`
- **THEN** the system MUST reject the modification
- **AND** the error MUST indicate that vervalreden requires a vervaldatum to be set

---

### Requirement: Besluit Publicatie (Woo Compliance)
Besluiten with `publicatieIndicatie: true` on their besluittype SHALL be subject to publication requirements under the Wet open overheid (Woo). The system MUST support marking decisions for publication, tracking publication dates, and providing a public-facing view with personal data redaction.

#### Scenario: Flag a besluit for publication based on besluittype
- **GIVEN** a besluit of besluittype `Omgevingsvergunning verleend` with `publicatieIndicatie: true`
- **WHEN** the besluit transitions to definitief state
- **THEN** the system MUST flag the besluit as requiring publication
- **AND** the publication deadline MUST be calculated from the `verzenddatum` plus the besluittype's `publicatietermijn`
- **AND** a notification MUST be sent to the publication officer

#### Scenario: Set publicatiedatum and validate response deadline
- **GIVEN** a besluit with besluittype having `reactietermijn: P42D` (42 days)
- **WHEN** the user sets `publicatiedatum` to `2026-03-16`
- **THEN** the `uiterlijkeReactiedatum` MUST be at minimum `2026-04-27` (publicatiedatum + 42 days)
- **AND** if the user sets `uiterlijkeReactiedatum` to a date before `2026-04-27`, the system MUST reject it with a validation error

#### Scenario: Publish besluit to public API with PII redaction
- **GIVEN** a besluit with `publicatiedatum` set and `publicatieIndicatie: true`
- **WHEN** the besluit is accessed via the public (unauthenticated) API
- **THEN** the besluit MUST be returned with personal data fields redacted
- **AND** the `toelichting` MUST have person names, BSN numbers, and addresses replaced with `[GEANONIMISEERD]`
- **AND** linked documents in the public view MUST also have PII redacted or be restricted based on schema-level redaction configuration

#### Scenario: Reject publication dates when publicatieIndicatie is false
- **GIVEN** a besluittype `Intern adviesbesluit` with `publicatieIndicatie: false`
- **WHEN** the user creates a besluit of this type and sets `publicatiedatum` to `2026-03-16`
- **THEN** the system MUST reject the publication date
- **AND** the error MUST indicate that this besluittype does not require publication

#### Scenario: Validate response date requires publication date and vice versa
- **GIVEN** a besluit with publicatieIndicatie true
- **WHEN** the user sets `uiterlijkeReactiedatum` without setting `publicatiedatum`
- **THEN** the system MUST reject with error indicating that `publicatiedatum` is required when `uiterlijkeReactiedatum` is set
- **AND** similarly, setting `publicatiedatum` without `uiterlijkeReactiedatum` MUST also be rejected

---

### Requirement: Besluit Bezwaar/Beroep Tracking
The system SHALL support tracking objections (bezwaar) and appeals (beroep) filed against decisions. When the `uiterlijkeReactiedatum` is set, the system MUST track whether the deadline has passed and whether any formal objection has been received, supporting the administrative law lifecycle under the Awb.

#### Scenario: Calculate uiterlijkeReactiedatum from verzenddatum and reactietermijn
- **GIVEN** a besluit with `verzenddatum` `2026-03-16`
- **AND** the besluittype has `reactietermijn` `P42D`
- **WHEN** the besluit is created or `verzenddatum` is set
- **THEN** `uiterlijkeReactiedatum` MUST be automatically calculated as `2026-04-27`
- **AND** the calculated date MUST be stored on the besluit
- **AND** the user MAY override the calculated date to a later date but NOT to an earlier date

#### Scenario: Display active bezwaartermijn with countdown
- **GIVEN** a besluit with `uiterlijkeReactiedatum` `2026-04-27`
- **AND** today is `2026-04-01`
- **WHEN** the besluit detail is viewed
- **THEN** the system MUST display `26 dagen resterend voor bezwaar/beroep`
- **AND** a progress indicator MUST show the elapsed and remaining portion of the bezwaartermijn

#### Scenario: Display expired bezwaartermijn
- **GIVEN** a besluit with `uiterlijkeReactiedatum` `2026-04-27`
- **AND** today is `2026-05-01`
- **WHEN** the besluit detail is viewed
- **THEN** the system MUST display `Bezwaartermijn verlopen (sinds 27 april 2026)`
- **AND** the indicator MUST be visually distinct (e.g., greyed out or marked as complete)

#### Scenario: Register a bezwaar against a besluit
- **GIVEN** a definitief besluit `B-001` with active bezwaartermijn
- **WHEN** a formal objection is received and registered
- **THEN** the system MUST create a linked `bezwaar` record referencing the besluit
- **AND** the besluit detail MUST show the number of active bezwaren
- **AND** the bezwaar MAY trigger a new case (zaak) for processing the objection

#### Scenario: Notify approaching bezwaartermijn deadline
- **GIVEN** a besluit with `uiterlijkeReactiedatum` `2026-04-27`
- **AND** today is `2026-04-22` (5 days before deadline)
- **WHEN** the daily scheduled job runs
- **THEN** the system MUST send a Nextcloud notification to the case handler
- **AND** the notification MUST include: besluit identificatie, linked zaak, days remaining

---

### Requirement: Besluit API (CRUD and Status Transitions)
The system SHALL expose RESTful API endpoints for besluit CRUD operations that follow the ZGW BRC URL structure and response format. The API MUST support content negotiation, pagination, filtering, and the standard ZGW scope-based authorization model.

| Method | Path | Scope | Description |
|--------|------|-------|-------------|
| GET | `/api/besluiten/v1/besluiten` | `besluiten.lezen` | List decisions with filtering |
| POST | `/api/besluiten/v1/besluiten` | `besluiten.aanmaken` | Create a decision |
| GET | `/api/besluiten/v1/besluiten/{uuid}` | `besluiten.lezen` | Retrieve a decision |
| PUT | `/api/besluiten/v1/besluiten/{uuid}` | `besluiten.bijwerken` | Full update |
| PATCH | `/api/besluiten/v1/besluiten/{uuid}` | `besluiten.bijwerken` | Partial update |
| DELETE | `/api/besluiten/v1/besluiten/{uuid}` | `besluiten.verwijderen` | Delete a decision |
| GET | `/api/besluiten/v1/besluiten/{uuid}/audittrail` | `besluiten.lezen` | Audit trail |
| GET | `/api/besluiten/v1/besluitinformatieobjecten` | `besluiten.lezen` | List linked documents |
| POST | `/api/besluiten/v1/besluitinformatieobjecten` | `besluiten.aanmaken` | Link a document |
| DELETE | `/api/besluiten/v1/besluitinformatieobjecten/{uuid}` | `besluiten.verwijderen` | Unlink a document |

#### Scenario: Create a besluit via API
- **GIVEN** an authenticated client with scope `besluiten.aanmaken`
- **WHEN** the client sends `POST /api/besluiten/v1/besluiten` with a valid JSON body
- **THEN** the system MUST return HTTP 201 with the created besluit including generated `uuid` and `identificatie`
- **AND** the `url` field in the response MUST be the absolute URL to the created resource

#### Scenario: List besluiten with filtering
- **GIVEN** 50 besluiten in the register, 10 of which are linked to zaak `vergunning-1`
- **WHEN** the client sends `GET /api/besluiten/v1/besluiten?zaak=<zaak-uuid>`
- **THEN** the system MUST return only the 10 besluiten linked to the specified zaak
- **AND** the response MUST use standard ZGW pagination with `count`, `next`, `previous`, and `results`

#### Scenario: Filter besluiten by besluittype and date range
- **GIVEN** multiple besluiten across different types and dates
- **WHEN** the client sends `GET /api/besluiten/v1/besluiten?besluittype=<uuid>&datum__gte=2026-01-01&datum__lte=2026-03-31`
- **THEN** the system MUST return only besluiten matching both the besluittype and the date range

#### Scenario: Reject unauthorized API access
- **GIVEN** an authenticated client with only `besluiten.lezen` scope
- **WHEN** the client sends `POST /api/besluiten/v1/besluiten` (create)
- **THEN** the system MUST return HTTP 403 Forbidden
- **AND** the error MUST indicate insufficient scope

#### Scenario: Return audit trail for a besluit
- **GIVEN** a besluit `B-001` that has been created, updated, and had documents linked
- **WHEN** the client sends `GET /api/besluiten/v1/besluiten/{uuid}/audittrail`
- **THEN** the system MUST return a chronological list of all actions performed on the besluit
- **AND** each entry MUST include: timestamp, user, action type (create/update/delete), and changed fields

---

### Requirement: Bulk Besluit Operations
The system SHALL support batch processing of besluiten for common government workflows where multiple decisions are issued simultaneously (e.g., batch permit approvals, mass subsidy grants). The batch endpoint MUST follow the OpenZaak `besluit_verwerken` convenience pattern.

#### Scenario: Batch create besluiten for multiple cases
- **GIVEN** 15 cases of zaaktype `Subsidie aanvraag` are ready for decision
- **AND** all cases should receive besluittype `Subsidie toegekend`
- **WHEN** the user submits a batch operation with a list of zaak UUIDs and shared besluit properties
- **THEN** the system MUST create 15 individual besluiten, one per case
- **AND** each besluit MUST have a unique `identificatie`
- **AND** the response MUST include a summary: `15 besluiten aangemaakt, 0 fouten`

#### Scenario: Batch create with partial failure
- **GIVEN** a batch of 10 besluiten to create
- **AND** 2 of the 10 have invalid zaak references
- **WHEN** the batch is submitted
- **THEN** the system MUST create the 8 valid besluiten
- **AND** the response MUST report `8 besluiten aangemaakt, 2 fouten`
- **AND** each error MUST include the zaak reference and the specific validation error

#### Scenario: Batch withdrawal of related besluiten
- **GIVEN** 5 besluiten linked to cases that are part of a revoked policy
- **WHEN** the user submits a batch withdrawal with vervalreden `ingetrokken_overheid`
- **THEN** all 5 besluiten MUST have `vervaldatum` set to the current date and `vervalreden` set to `ingetrokken_overheid`
- **AND** the audit trail for each besluit MUST record the withdrawal

---

### Requirement: Besluit Search and Filtering
The system SHALL provide comprehensive search and filtering capabilities for besluiten, supporting both the API filter parameters from the ZGW BRC standard and a frontend search interface integrated with OpenRegister's faceted search.

#### Scenario: Search besluiten by free text in toelichting
- **GIVEN** 100 besluiten in the register
- **AND** 3 of them contain the word `asbest` in the toelichting
- **WHEN** the user searches for `asbest`
- **THEN** the system MUST return the 3 matching besluiten
- **AND** the search result MUST highlight the matching text in the toelichting

#### Scenario: Filter besluiten by lifecycle state
- **GIVEN** 50 besluiten: 20 concept, 25 definitief, 5 ingetrokken
- **WHEN** the user filters by lifecycle state `definitief`
- **THEN** the system MUST return only the 25 definitief besluiten

#### Scenario: Filter besluiten by verantwoordelijke organisatie
- **GIVEN** besluiten from multiple organizations in a shared register
- **WHEN** the user filters by `verantwoordelijkeOrganisatie` `002220647`
- **THEN** only besluiten from that organization MUST be returned

#### Scenario: Filter besluiten with active bezwaartermijn
- **GIVEN** 30 definitief besluiten, 12 of which have `uiterlijkeReactiedatum` in the future
- **WHEN** the user selects the filter `Bezwaartermijn actief`
- **THEN** the system MUST return only the 12 besluiten with unexpired bezwaartermijn
- **AND** results MUST be sorted by `uiterlijkeReactiedatum` ascending (nearest deadline first)

#### Scenario: Faceted search combining multiple filters
- **GIVEN** the user wants to find all granted permits from Q1 2026
- **WHEN** the user applies filters:
  - besluittype: `Omgevingsvergunning verleend`
  - datum range: `2026-01-01` to `2026-03-31`
  - lifecycle state: `definitief`
- **THEN** the system MUST return only besluiten matching all three criteria
- **AND** facet counts MUST be displayed for further narrowing

---

### Requirement: Audit Trail for Decisions
Every action on a besluit (creation, modification, status transition, document linking, withdrawal) SHALL be recorded in an immutable audit trail. The audit trail MUST comply with the ZGW BRC audittrail specification and integrate with OpenRegister's existing AuditTrailMapper for consistent logging across all entity types.

#### Scenario: Record besluit creation in audit trail
- **GIVEN** user `jan.devries` creates a besluit `B-001`
- **WHEN** the creation is completed
- **THEN** the audit trail MUST contain an entry with:
  - `actie`: `create`
  - `actieWeergave`: `Besluit aangemaakt`
  - `resultaat`: HTTP 201
  - `hoofdObject`: URL of the besluit
  - `resource`: `besluit`
  - `resourceUrl`: URL of the besluit
  - `aanmaakdatum`: current timestamp
  - `wijzigingen.nieuw`: all field values of the created besluit

#### Scenario: Record field modification in audit trail
- **GIVEN** a besluit `B-001` with `toelichting` `Vergunning verleend`
- **WHEN** user `maria.bakker` updates `toelichting` to `Vergunning verleend met voorwaarden`
- **THEN** the audit trail MUST contain an entry with:
  - `actie`: `update`
  - `wijzigingen.oud.toelichting`: `Vergunning verleend`
  - `wijzigingen.nieuw.toelichting`: `Vergunning verleend met voorwaarden`

#### Scenario: Record withdrawal in audit trail
- **GIVEN** a definitief besluit `B-001`
- **WHEN** the besluit is withdrawn with vervalreden `ingetrokken_overheid`
- **THEN** the audit trail MUST contain an entry recording:
  - The vervalreden being set
  - The vervaldatum being set
  - The toelichting being updated with the withdrawal explanation
  - The lifecycle state transition from `definitief` to `ingetrokken`

#### Scenario: Audit trail entries are immutable
- **GIVEN** an audit trail with 10 entries for besluit `B-001`
- **WHEN** a user or API client attempts to modify or delete an existing audit trail entry
- **THEN** the system MUST reject the operation with HTTP 405 Method Not Allowed
- **AND** audit trail entries MUST be append-only

---

### Requirement: VNG BRC API Mapping
The system SHALL provide a ZGW BRC-compatible API layer that maps OpenRegister's internal besluit objects to the standard BRC response format. This mapping enables interoperability with other ZGW-compliant systems (Dimpact ZAC, Valtimo, Open Formulieren) that expect standard BRC endpoints and response structures.

#### Scenario: Map internal besluit to ZGW BRC response format
- **GIVEN** an internal besluit object stored in OpenRegister with camelCase property names
- **WHEN** the besluit is retrieved via the ZGW-compatible API endpoint
- **THEN** the response MUST use the ZGW BRC field naming convention (snake_case):
  - `verantwoordelijke_organisatie` (not `verantwoordelijkeOrganisatie`)
  - `uiterlijke_reactiedatum` (not `uiterlijkeReactiedatum`)
  - `besluittype` as full URL reference (not UUID)
  - `zaak` as full URL reference (not UUID)
- **AND** the response MUST include the standard `url` field pointing to the resource's canonical URL

#### Scenario: Accept ZGW BRC request format on creation
- **GIVEN** an external system (e.g., Valtimo) sends a POST request using ZGW BRC field naming
- **WHEN** the request body uses `verantwoordelijke_organisatie` and `uiterlijke_reactiedatum`
- **THEN** the system MUST accept both snake_case and camelCase field names
- **AND** the internal storage MUST normalize to the OpenRegister property naming convention

#### Scenario: Resolve URL references to besluittype and zaak
- **GIVEN** a besluit creation request with `besluittype` as a full URL `https://catalogi.example.com/api/v1/besluittypen/{uuid}`
- **WHEN** the system processes the request
- **THEN** the system MUST resolve the URL to the internal besluittype object
- **AND** if the URL references an external catalog, the system MUST validate that the besluittype exists at that URL (HTTP GET returns 200)

#### Scenario: Cross-API synchronization for zaak-besluit linking
- **GIVEN** a besluit is created with a `zaak` reference
- **WHEN** the creation is processed
- **THEN** the system MUST automatically create a corresponding `ZaakBesluit` record on the zaak side
- **AND** the ZaakBesluit MUST reference the besluit URL
- **AND** deleting the besluit MUST also remove the ZaakBesluit record

---

## Current Implementation Status

- **NOT implemented:** No dedicated besluiten (decisions) management exists in the OpenRegister core codebase.
  - No `besluit` schema, entity, or dedicated controller in OpenRegister
  - No `besluittype` catalog schema or configuration
  - No bezwaartermijn calculation logic
  - No decision withdrawal (intrekking) workflow
  - No publication workflow for decisions
  - No personal data redaction for public decision views
  - No batch besluit operations
  - No BRC-compatible API endpoints

- **Partial foundations in OpenRegister:**
  - Register and Schema entities (`lib/Db/Register.php`, `lib/Db/Schema.php`) support arbitrary schema definitions that can model the besluit data structure
  - Objects can reference each other via schema `$ref` properties, enabling zaak-besluit bidirectional linking
  - The existing object model can store besluiten as regular register objects with a dedicated besluit schema
  - File linking is available via `FileService` (`lib/Service/FileService.php`) for attaching decision documents
  - `AuditTrailMapper` provides immutable audit logging infrastructure
  - DSO register (`lib/Settings/dso_register.json`) already contains `besluitdatum` fields on permit applications, demonstrating the pattern
  - ORI register (`lib/Settings/ori_register.json`) already has `besluit` as a document type for council decisions

- **Partial foundations in Procest:**
  - Decision schema defined in `procest_register.json` with `title`, `description`, `case`, `decisionType`, `decidedBy`, `decidedAt`, `effectiveDate`, `expiryDate` properties -- needs alignment with ZGW BRC field names
  - DecisionType schema defined with `name`, `description`, `category`, `objectionPeriod`, `publicationRequired`, `publicationPeriod` -- needs ZGW BRC field mapping
  - No frontend UI exists for creating, viewing, editing, or deleting decisions
  - The roles-decisions spec (`procest/openspec/specs/roles-decisions/spec.md`) defines the Procest-side data model and CRUD requirements

## Standards & References
- **ZGW BRC (Besluiten Registratie Component) v1.0.2** -- API standard for decision registration in Dutch government (VNG Realisatie)
- **ZGW ZTC (Zaaktypecatalogus)** -- BesluitType definitions within the catalog, including reactietermijn and publicatieIndicatie
- **Awb (Algemene wet bestuursrecht)** -- Legal framework for formal government decisions, appeal periods (bezwaartermijn), and administrative proceedings
- **RGBZ (Referentiemodel Gemeentelijke Basisgegevens Zaken)** -- Reference data model including besluiten entity relationships
- **MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie)** -- Archival metadata standard for decisions
- **Wet open overheid (Woo)** -- Publication requirements for government decisions, replacing the Wob
- **VNG ZGW API specificaties** -- https://vng-realisatie.github.io/gemma-zaken/
- **OpenZaak BRC implementation** -- Reference implementation for BRC API compliance (analyzed in competitive analysis)
- **Dimpact ZAC DecisionService** -- Publication date validation patterns with reactietermijn calculation

## Cross-References
- **document-zaakdossier** -- Linked documents (beschikking PDFs) in the case dossier view; besluitInformatieObject records integrate with the dossier structure
- **archivering-vernietiging** -- Besluit ingangsdatum/vervaldatum drive archival brondatum calculation via afleidingswijze `ingangsdatum_besluit` and `vervaldatum_besluit`
- **zgw-api-mapping** -- BRC API endpoint structure, field name translation (camelCase to snake_case), and URL-based resource references
- **audit-trail-immutable** -- Audit trail entries for besluit lifecycle events use the shared AuditTrailMapper infrastructure
- **roles-decisions (Procest)** -- Procest-side decision entity and decision type schemas; the `decision_maker` generic role determines who can create besluiten

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No dedicated besluiten management, besluittype catalog, bezwaartermijn tracking, or publication workflow exists. Objects can reference each other and files can be linked, providing partial foundations.

**Nextcloud Core Interfaces**:
- `INotifier` / `INotification`: Send notifications for bezwaartermijn expiration warnings (e.g., "5 days remaining for bezwaar on besluit X"), decision publication deadlines, and withdrawal actions. Register a `BesluitNotifier` implementing `INotifier` for formatted notification display.
- `IEventDispatcher`: Fire typed events (`BesluitCreatedEvent`, `BesluitPublishedEvent`, `BesluitWithdrawnEvent`, `BesluitExpiredEvent`) for cross-app integration. Procest and other consuming apps can listen for these events to update case status or trigger follow-up workflows.
- `TimedJob`: Schedule a `BezwaartermijnCheckJob` that runs daily, scanning besluiten with upcoming or expired `uiterlijkeReactiedatum` and triggering notifications or status updates. Schedule a `VervaldatumCheckJob` to detect naturally expired temporary decisions and set `vervalreden` to `tijdelijk`.
- `IActivityManager` / `IProvider`: Register decision lifecycle events (creation, publication, withdrawal, expiry) in the Nextcloud Activity stream so users see a chronological history of decision actions on their activity feed.

**Implementation Approach**:
- Model besluiten and besluittypen as OpenRegister schemas within the Procest register. The `besluit` schema stores the decision data conforming to the ZGW BRC data model. The `besluittype` schema serves as the catalog defining decision types with reactietermijn and publicatieIndicatie.
- Use schema `$ref` properties for bidirectional zaak-besluit linking. When a besluit is created, the linked zaak object is updated with the besluit reference (via `ObjectService`). Implement a pre-save hook to maintain referential integrity when zaak references change.
- Implement bezwaartermijn calculation as a computed field or pre-save hook: `uiterlijkeReactiedatum = verzenddatum + besluittype.reactietermijn` (ISO 8601 duration parsing).
- For publication, leverage OpenRegister's existing public API access control. Mark published besluiten with a publication flag that makes them accessible via unauthenticated API endpoints. Implement a `RedactionHandler` that strips PII fields from the public view based on schema-level configuration (field-level annotation of sensitive fields).
- Use `FileService` for linking beschikking documents (PDF) to besluit objects, integrating with the document-zaakdossier spec for structured dossier views.
- Implement the BRC-compatible API layer as a separate controller that translates between ZGW BRC format (snake_case, URL references) and the internal OpenRegister object model (camelCase, UUID references).

**Dependencies on Existing OpenRegister Features**:
- `ObjectService` -- CRUD for besluit and besluittype objects with inter-object references
- `SchemaService` / `SchemaMapper` -- schema definitions with `$ref` for zaak-besluit relationships
- `AuditTrailMapper` -- immutable logging of decision creation, publication, and withdrawal actions
- `FileService` -- document attachment for beschikking PDFs
- `HyperFacetHandler` -- faceted search and filtering for besluit lists
- Procest app -- owns the case context and decision type catalog configuration; the `decision_maker` role determines authorization for besluit creation
