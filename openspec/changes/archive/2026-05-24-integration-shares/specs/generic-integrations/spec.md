---
status: proposed
---

# Integration: Shares — Delta on generic-integrations

## Purpose

Add the `shares` leaf — query-time aggregation of NC Shares across an object's linked files, with revoke action — to the registry of generic integrations established by `pluggable-integration-registry`.

**Standards**: NC Share API (`OCP\Share\IManager`), ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Shares Provider Registration

`SharesProvider` SHALL be registered with id='shares', group='core', requiredApp=null (NC core), storage='query-time'.

#### Scenario: Provider always present

- **GIVEN** any Nextcloud install
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** exactly ONE provider with id='shares' MUST be included
- **AND** its `getRequiredApp()` MUST return `null`

---

### Requirement: Query-Time Aggregation

The provider SHALL NOT maintain its own link table. Shares SHALL be queried live from `OCP\Share\IManager::getSharesBy()` filtered by the object's linked files. The legacy `MarkerLookupTrait`-on-`share.note` path MUST be removed.

#### Scenario: List queries IManager per linked file

- **GIVEN** an OR object with two linked files, each carrying a user share
- **WHEN** `SharesProvider::list()` is invoked
- **THEN** `IManager::getSharesBy()` MUST be called for each linked file
- **AND** the returned rows MUST union shares across files, deduplicated by share id

---

### Requirement: Read + Revoke Only

The tab SHALL support list and revoke. Create / update share flows SHALL delegate to the NC Files UI.

#### Scenario: Revoke deletes the share

- **GIVEN** a share exists on an object's linked file
- **WHEN** the user clicks revoke in `CnSharesTab`
- **THEN** `IManager::deleteShare()` MUST be called
- **AND** the share MUST disappear from the tab

---

### Requirement: Group-By Display

The tab SHALL group shares by type: user / group / public link / federated.

#### Scenario: Mixed types grouped

- **GIVEN** one user share, one group share, and one public link on an object
- **WHEN** `CnSharesTab` renders
- **THEN** rows MUST be grouped into three distinct sections labelled by type

---

### Requirement: Widget Surfaces

Per umbrella AD-6 / AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); the dashboard surfaces SHALL show a count headline.

#### Scenario: Dashboard count headline

- **GIVEN** an object with 3 user shares + 1 public link
- **WHEN** `CnSharesCard` renders on `surface='detail-page'`
- **THEN** a count headline MUST surface totals by type
- **AND** the most-recent share MUST appear as a secondary row

---

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'shares'` SHALL render `CnSharesCard` at `surface='single-entity'` showing a share-type chip.

#### Scenario: Single-entity chip

- **GIVEN** a schema property carrying `referenceType: 'shares'`
- **WHEN** a form / detail renderer mounts it
- **THEN** the registry MUST resolve `CnSharesCard` for `surface='single-entity'`
- **AND** the chip MUST display the share-type icon plus a target label

---

### Requirement: Permission Inheritance

`SharesProvider::requiresPermission()` SHALL return `null`. Share visibility per user is governed by NC Share Manager transitively.

#### Scenario: Per-user visibility honored

- **GIVEN** a share visible only to user A
- **WHEN** user B invokes `SharesProvider::list()` against the same object
- **THEN** the row MUST NOT appear in the response
- **AND** `requiresPermission()` MUST return `null`

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When the underlying NC core sharing subsystem is unreachable, the provider SHALL return an empty list and report degraded health rather than leaking generic errors.

#### Scenario: Share subsystem unreachable

- **GIVEN** `OCP\Share\IManager` is unavailable (binding misconfigured / runtime failure)
- **WHEN** `SharesProvider::list()` is invoked
- **THEN** the method MUST return `[]`
- **AND** `health()` MUST surface the documented degraded shape rather than throwing

#### Scenario: User lacks share-management permission

- **GIVEN** a user viewing object shares but lacking the NC permission to revoke a specific share
- **WHEN** `CnSharesTab` renders that share
- **THEN** the revoke action MUST be disabled with a tooltip "Only the share owner can revoke"
- **AND** listing the share MUST still succeed
