---
status: proposed
---

# Integration: Maps

## Purpose

Link geolocations to OR objects through the registry with cached lat/lon for performant rendering.

**Standards**: NC Maps API, Leaflet, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Maps Provider Registration

`MapsProvider` registered with id='maps', group='docs', requiredApp='maps', storage='link-table'.

### Requirement: Cached lat/lon

Link table SHALL include `lat`, `lon`, `address`, `address_source`. Rendering SHALL NOT call geocoding.

#### Scenario: Rendering uses cached fields

- **GIVEN** 20 linked locations on a dashboard
- **WHEN** `CnMapCard` renders
- **THEN** NO geocoding API calls MUST be made

### Requirement: Two Add Flows

Users SHALL be able to add a location by (a) entering an address (geocoded) or (b) clicking on the embedded map.

### Requirement: Single-Entity is Address Chip

`surface='single-entity'` SHALL render an address chip (not an inline map). Click expands to a map popover.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'maps'` SHALL render the address chip.

### Requirement: Permission Inheritance

`requiresPermission() === null`; object + Maps ACLs apply.
