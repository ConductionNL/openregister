---
status: done
---

# integration-shares Specification

## Purpose

@e2e exclude redirect/duplicate of generic-integrations — backend-only
TBD - created by archiving change integration-shares. Update Purpose after archive.
## Requirements
### Requirement: Shares Provider Registration

`SharesProvider` SHALL be registered with id='shares', group='core', requiredApp=null (NC core, always available), storage='query-time'.

#### Scenario: Provider always present

- **GIVEN** any Nextcloud install (NC core sharing is always available)
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** exactly ONE provider with id='shares' MUST be included
- **AND** the provider's `getRequiredApp()` MUST return `null`

---

### Requirement: Query-Time Aggregation via OCP\Share\IManager

The provider SHALL query shares live from `OCP\Share\IManager::getSharesBy()` filtered by the object's linked files; it MUST NOT maintain its own link table or scan upstream `share.note` for OR markers.

#### Scenario: List returns shares pulled from IManager

- **GIVEN** an OR object with two linked files, each carrying a user-type share
- **WHEN** `SharesProvider::list()` is invoked for that object
- **THEN** `IManager::getSharesBy()` MUST be called for each linked file
- **AND** the returned rows MUST union shares across files, deduplicated by share id

---

### Requirement: Read + Revoke Only

The tab SHALL support list and revoke. Create / update share flows SHALL delegate to NC Files UI (no in-OR share creation surface).

#### Scenario: Revoke deletes the share

- **GIVEN** a share exists on an object's linked file
- **WHEN** the user clicks revoke in `CnSharesTab`
- **THEN** `IManager::deleteShare()` MUST be called
- **AND** the share MUST disappear from the tab

#### Scenario: No create UI in tab

- **WHEN** `CnSharesTab` renders
- **THEN** no "create share" affordance MUST be present
- **AND** an "open in Files" deep-link MUST be available so the user can create shares in the NC Files UI

---

### Requirement: Group-By Display

The tab SHALL group shares visually by type: user / group / public link / federated.

#### Scenario: Mixed share types grouped

- **GIVEN** an object with one user share, one group share, and one public link
- **WHEN** `CnSharesTab` renders
- **THEN** rows MUST be grouped into three distinct sections labelled by type

---

### Requirement: Widget Surfaces

Per umbrella AD-6 / AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); the dashboard surfaces show a count headline.

#### Scenario: Dashboard headline rendered

- **GIVEN** an object with 3 user shares + 1 public link
- **WHEN** `CnSharesCard` renders with `surface='detail-page'`
- **THEN** a count headline MUST surface the totals by type
- **AND** the most-recent share MUST appear as a secondary row

---

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'shares'` SHALL render `CnSharesCard` at `surface='single-entity'` showing a share-type chip.

#### Scenario: Single-entity chip

- **GIVEN** a schema property carries `referenceType: 'shares'` and a share id value
- **WHEN** the form / detail renderer mounts it
- **THEN** the integration registry MUST resolve `CnSharesCard` for `surface='single-entity'`
- **AND** the chip MUST display the share type icon plus a target label

---

### Requirement: Permission Inheritance

`SharesProvider::requiresPermission()` SHALL return `null`. Share visibility per user is governed by NC Share Manager transitively.

#### Scenario: User without revoke permission sees disabled action

- **GIVEN** a user viewing object shares but lacking the NC permission to revoke a specific share
- **WHEN** `CnSharesTab` renders that share
- **THEN** the revoke action MUST be disabled with a tooltip "Only the share owner can revoke"
- **AND** listing the share MUST still succeed

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When the underlying NC core sharing subsystem is unreachable, the provider SHALL return an empty list and report degraded health rather than leaking generic errors.

#### Scenario: Share subsystem unreachable

- **GIVEN** `OCP\Share\IManager` is unavailable (binding misconfigured / runtime failure)
- **WHEN** `SharesProvider::list()` is invoked
- **THEN** the method MUST return `[]`
- **AND** `health()` MUST surface the documented degraded shape rather than throwing

