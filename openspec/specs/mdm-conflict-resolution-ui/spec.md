# mdm-conflict-resolution-ui Specification

## Purpose
TBD - created by archiving change mdm-survivorship-override. Update Purpose after archive.
## Requirements
### Requirement: Conflict-resolution modal surfaces disagreeing sources per attribute
OpenRegister SHALL provide a steward-facing conflict-resolution modal, launched
from the golden-record detail view, that lists every attribute whose linked
source records supply **more than one distinct non-empty value**. For each such
attribute the modal SHALL present the competing values, each labelled with its
source system, and let the steward select the authoritative one. Attributes on
which all sources agree (or where only one source supplies a value) SHALL NOT be
listed as conflicts. The modal MUST live in its own file under `src/modals/`
(ADR-004 modal-isolation) and MUST NOT be written inline in the parent view.

#### Scenario: Only disagreeing attributes are listed
- **WHEN** the steward opens the conflict-resolution modal for a master object whose sources supply two different values for `legalName` but the same value for `email`
- **THEN** `legalName` MUST appear as a conflict row with both values labelled by their source system
- **AND** `email` MUST NOT appear

#### Scenario: No conflicts renders an empty state
- **WHEN** every attribute across the linked sources agrees (or has a single source)
- **THEN** the modal MUST render an empty-content state and MUST NOT enable the save action

### Requirement: Steward chooses the authoritative source per attribute
Each conflict row SHALL offer the steward a single-select of the competing
source/value options, wired with the component's accessibility props
(`inputLabel`, ADR-004 nc-input-labels) — never a bare manual `<label>`. The
save action SHALL be disabled until at least one conflict is present and remains
disabled while a save is in flight.

#### Scenario: Selecting a winning source enables save
- **WHEN** the steward selects a winning source for a conflicted attribute
- **THEN** the selection MUST be retained per attribute
- **AND** the primary save action MUST be enabled

### Requirement: Steward chooses persistent-rule or one-off outcome
For each resolved conflict the modal SHALL let the steward choose between a
**persistent** outcome and a **one-off** outcome. The persistent outcome SHALL
write a `trustConfiguration` row for the `(entityType, attribute, sourceSystem)`
tuple through OpenRegister's generic `/api/objects` CRUD — reusing the seeded
`trustConfiguration` register with no bespoke endpoint. The one-off outcome SHALL
set a per-object attribute override via the survivorship override endpoint. The
modal SHALL capture an optional rationale string. After saving, the modal SHALL
trigger a golden-record recompute/refresh and emit a saved event so the parent
view updates.

#### Scenario: Persistent choice writes a trust-configuration row
- **WHEN** the steward picks a winning source, selects the persistent outcome, and saves
- **THEN** a `trustConfiguration` object MUST be created via `/api/objects` for that `(entityType, attribute, sourceSystem)` tuple with a winning tier
- **AND** the master object's golden record MUST be recomputed and the view refreshed

#### Scenario: One-off choice sets a per-object override
- **WHEN** the steward picks a winning value, selects the one-off outcome, and saves
- **THEN** the survivorship override endpoint MUST be called for that object + attribute with the chosen value
- **AND** the golden record MUST reflect the override on refresh without altering any trust rule

#### Scenario: Save failure surfaces an error and keeps the modal open
- **WHEN** a persist or override request fails
- **THEN** the modal MUST show an error toast and MUST NOT emit the saved event

### Requirement: Store actions wrap the resolution endpoints
`src/store/modules/quality.js` SHALL expose a `setAttributeOverride` action
(thin `generateUrl` + axios POST to the survivorship override endpoint) and a
`persistTrustRule` action (thin `generateUrl` + axios POST to `/api/objects`
creating a `trustConfiguration` row). Both SHALL follow the existing
merge-action pattern in that module: set loading, capture `error` on failure,
return the response data, and use no custom store base class (ADR-026).

#### Scenario: setAttributeOverride posts to the override endpoint
- **WHEN** `setAttributeOverride` is dispatched with an object id, attribute, and value
- **THEN** it MUST POST to `/api/objects/survivorship/{id}/override` and return the recomputed object payload

#### Scenario: persistTrustRule creates a trust-configuration object
- **WHEN** `persistTrustRule` is dispatched with an entity type, attribute, source system, and tier
- **THEN** it MUST POST a `trustConfiguration` row to the generic `/api/objects` surface and return the created row

