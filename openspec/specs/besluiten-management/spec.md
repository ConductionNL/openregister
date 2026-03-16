# besluiten-management Specification

## Purpose
Implement formal decision management (besluiten) conforming to the ZGW BRC (Besluiten Registratie Component) standard. Decisions MUST be first-class entities with decision types from the catalog, publication dates, appeal periods (bezwaartermijn), withdrawal support, and linked documents. Every case (zaak) MUST be able to have one or more formal decisions associated with it.

**Source**: ZGW API standard requirement; gap identified in cross-platform analysis.

## ADDED Requirements

### Requirement: Decisions MUST be first-class register objects
Decisions (besluiten) MUST be stored as OpenRegister objects with a dedicated schema conforming to the ZGW BRC data model.

#### Scenario: Create a formal decision
- GIVEN a case object `vergunning-1` in schema `vergunningen`
- WHEN the user creates a decision (besluit) with:
  - `besluittype`: `omgevingsvergunning-verleend`
  - `zaak`: reference to `vergunning-1`
  - `datum`: `2026-03-15`
  - `toelichting`: `Vergunning verleend conform aanvraag`
  - `ingangsdatum`: `2026-03-16`
  - `vervaldatum`: null (no expiry)
  - `publicatiedatum`: `2026-03-16`
  - `verzenddatum`: `2026-03-16`
  - `uiterlijkeReactiedatum`: `2026-04-27` (6 weeks bezwaartermijn)
- THEN the decision MUST be created as an OpenRegister object
- AND it MUST be linked to the case via a bidirectional reference

#### Scenario: Create a rejection decision
- GIVEN a case `vergunning-2` for which the application is denied
- WHEN the user creates a decision with besluittype `omgevingsvergunning-geweigerd`
- THEN the decision MUST store the rejection reasoning in `toelichting`
- AND the bezwaartermijn MUST be calculated from the verzenddatum

### Requirement: Decision types MUST be configurable via a catalog
Decision types (besluittypen) MUST be defined in a catalog schema, similar to zaaktype catalogs.

#### Scenario: Define a decision type
- GIVEN an admin configuring the besluittype catalog
- WHEN they create a besluittype:
  - `omschrijving`: `Omgevingsvergunning verleend`
  - `besluitcategorie`: `vergunning`
  - `reactietermijn`: `P42D` (42 days in ISO 8601 duration)
  - `publicatieIndicatie`: true
- THEN the besluittype MUST be available for selection when creating decisions

### Requirement: Decisions MUST support appeal period tracking (bezwaartermijn)
The system MUST calculate and track the appeal period (bezwaartermijn) for each decision.

#### Scenario: Calculate bezwaartermijn
- GIVEN a decision with verzenddatum `2026-03-16` and besluittype reactietermijn `P42D`
- WHEN the decision is created
- THEN `uiterlijkeReactiedatum` MUST be automatically set to `2026-04-27`
- AND the system MUST track whether the bezwaartermijn has expired

#### Scenario: Display active bezwaartermijn
- GIVEN a decision with uiterlijkeReactiedatum `2026-04-27` and today is `2026-04-01`
- WHEN the decision detail is viewed
- THEN the system MUST display `26 dagen resterend voor bezwaar`
- AND after the deadline, display `Bezwaartermijn verlopen`

### Requirement: Decisions MUST support linked documents
Each decision MUST support one or more linked documents (the formal decision letter, attachments).

#### Scenario: Link decision document
- GIVEN a decision `besluit-1`
- WHEN the user uploads the formal decision letter `beschikking.pdf`
- THEN the document MUST be linked to the decision with type `besluitdocument`
- AND the document MUST be accessible from both the decision detail and the case dossier

### Requirement: Decisions MUST support withdrawal (intrekking)
Published decisions MUST be withdrawable with a formal withdrawal record.

#### Scenario: Withdraw a decision
- GIVEN a published decision `besluit-1` for vergunning `vergunning-1`
- WHEN the user withdraws the decision with reason `Besluit ingetrokken wegens nieuwe informatie`
- THEN the decision status MUST change to `ingetrokken`
- AND the withdrawal date and reason MUST be recorded
- AND a new bezwaartermijn MUST start for the withdrawal itself
- AND the linked case MUST be updated to reflect the withdrawal

### Requirement: Decisions MUST be publishable
Decisions with `publicatieIndicatie: true` MUST be flagged for publication in external systems.

#### Scenario: Mark decision for publication
- GIVEN a decision with publicatieIndicatie true
- WHEN the decision reaches status `gepubliceerd`
- THEN the decision MUST be available via the public API
- AND the publication date and besluit content MUST be accessible without authentication
- AND personal data in the decision MUST be redacted in the public view
