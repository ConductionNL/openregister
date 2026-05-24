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

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying location in NC Maps is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Geocoding service unavailable during add

- **GIVEN** user adds a location by address and the Nominatim service is unavailable
- **WHEN** geocoding fails
- **THEN** the UI MUST offer "Place on map" as a fallback (user clicks the map)
- **AND** the link record MUST persist with `address_source='click-placed'` and the entered address text
