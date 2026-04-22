---
status: proposed
---

# Integration: Shares

## Purpose

Surface NC Shares (file/folder shares on object's linked files) through the registry with query-time aggregation and revoke action.

**Standards**: NC Share API (`OCP\Share\IManager`), ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md), [integration-activity](../../../integration-activity/specs/integration-activity/spec.md)

---

## ADDED Requirements

### Requirement: Shares Provider Registration

`SharesProvider` registered with id='shares', group='core', requiredApp=null (always available), storage='query-time'.

### Requirement: Query-Time Aggregation

Integration SHALL NOT maintain its own link table. Shares SHALL be queried live from Share Manager filtered by the object's linked files.

### Requirement: Read + Revoke Only

Tab SHALL support list and revoke. Create/update share flows SHALL delegate to NC Files UI (no in-OR share creation).

#### Scenario: Revoke deletes the share

- **GIVEN** a share exists on an object's linked file
- **WHEN** the user clicks revoke in `CnSharesTab`
- **THEN** `IManager::deleteShare()` MUST be called
- **AND** the share MUST disappear from the tab

### Requirement: Group-By Display

Tab SHALL group shares by type: user / group / public link / federated.

### Requirement: Widget Surfaces

Standard four; dashboard surface shows count headline.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'shares'` SHALL render share-type chip.

### Requirement: Permission Inheritance

`requiresPermission() === null`. Share visibility per-user is governed by NC Share Manager transitively.
