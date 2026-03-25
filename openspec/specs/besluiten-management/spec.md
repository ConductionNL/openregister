---
status: draft
---

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

### Current Implementation Status
- **NOT implemented:** No dedicated besluiten (decisions) management exists in the codebase.
  - No `besluit` schema, entity, or dedicated controller
  - No `besluittype` catalog schema or configuration
  - No bezwaartermijn calculation logic
  - No decision withdrawal (intrekking) workflow
  - No publication workflow for decisions
  - No personal data redaction for public decision views
- **Partial foundations:**
  - Register and Schema entities (`lib/Db/Register.php`, `lib/Db/Schema.php`) mention `publication` in passing but not specific to decisions
  - Objects can reference each other via schema `$ref` properties, enabling case-to-decision linking
  - The existing object model could store decisions as regular register objects with a besluit schema
  - File linking is partially available via `FileService` (`lib/Service/FileService.php`) for attaching decision documents
  - Computed date fields (if implemented) could calculate bezwaartermijn from verzenddatum + reactietermijn

### Standards & References
- **ZGW BRC (Besluiten Registratie Component)** — API standard for decision registration in Dutch government
- **ZGW ZTC (Zaaktypecatalogus)** — Besluittype definitions within the catalog
- **Awb (Algemene wet bestuursrecht)** — Legal framework for formal government decisions and appeal periods
- **RGBZ (Referentiemodel Gemeentelijke Basisgegevens Zaken)** — Reference model including besluiten
- **MDTO** — Archival metadata for decisions
- **Wet open overheid (Woo)** — Publication requirements for government decisions
- **VNG ZGW API specificaties** — https://vng-realisatie.github.io/gemma-zaken/

### Specificity Assessment
- The spec is well-defined with clear ZGW-aligned data model scenarios.
- Missing: REST API endpoint definitions for besluiten CRUD; how besluittypen are stored (separate schema or admin config); how the bidirectional zaak-besluit link is maintained.
- Ambiguous: whether decisions should be a separate entity type or implemented as regular OpenRegister objects with a dedicated schema; how personal data redaction works technically (field-level masking? separate public view?).
- Open questions:
  - Should the besluiten API be ZGW BRC-compatible (same URL structure and response format)?
  - How does bezwaartermijn tracking integrate with notifications — should the system send reminders before deadlines?
  - Is the besluittype catalog shared across registers or per-register?
  - How does the withdrawal workflow interact with the audit trail and document dossier?

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No dedicated besluiten management, besluittype catalog, bezwaartermijn tracking, or publication workflow exists. Objects can reference each other and files can be linked, providing partial foundations.

**Nextcloud Core Interfaces**:
- `INotifier` / `INotification`: Send notifications for bezwaartermijn expiration warnings (e.g., "5 days remaining for bezwaar on besluit X"), decision publication events, and withdrawal actions. Register a `BesluitNotifier` implementing `INotifier` for formatted notification display.
- `IEventDispatcher`: Fire typed events (`BesluitCreatedEvent`, `BesluitPublishedEvent`, `BesluitWithdrawnEvent`) for cross-app integration. Procest and other consuming apps can listen for these events to update case status or trigger follow-up workflows.
- `TimedJob`: Schedule a `BezwaartermijnCheckJob` that runs daily, scanning decisions with upcoming or expired `uiterlijkeReactiedatum` and triggering notifications or status updates.
- `IActivityManager` / `IProvider`: Register decision lifecycle events (creation, publication, withdrawal) in the Nextcloud Activity stream so users see a chronological history of decision actions on their activity feed.

**Implementation Approach**:
- Model besluiten and besluittypen as OpenRegister schemas within the Procest register. A `besluit` schema stores the decision data (datum, toelichting, ingangsdatum, publicatiedatum, bezwaartermijn). A `besluittype` schema serves as the catalog defining decision types with reactietermijn and publicatieIndicatie.
- Use schema `$ref` properties for bidirectional zaak-besluit linking. When a besluit is created, the linked zaak object is updated with the besluit reference (via `ObjectService`).
- Implement bezwaartermijn calculation as a computed field or pre-save hook: `uiterlijkeReactiedatum = verzenddatum + besluittype.reactietermijn` (ISO 8601 duration parsing).
- For publication, leverage OpenRegister's existing public API access control. Mark published besluiten with a publication flag that makes them accessible via unauthenticated API endpoints. Personal data redaction requires a `RedactionHandler` that strips PII fields from the public view based on schema-level configuration.
- Use `FileService` for linking beschikking documents (PDF) to besluit objects, integrating with the document-zaakdossier spec for structured dossier views.

**Dependencies on Existing OpenRegister Features**:
- `ObjectService` — CRUD for besluit and besluittype objects with inter-object references.
- `SchemaService` — schema definitions with `$ref` for zaak-besluit relationships.
- `AuditTrailMapper` — immutable logging of decision creation, publication, and withdrawal actions.
- `FileService` — document attachment for beschikking PDFs.
- Procest app — owns the case context and decision type catalog configuration.
