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

The system SHALL register `MapsProvider` with id='maps', group='docs', requiredApp='maps', storage='link-table'.

#### Scenario: Provider registered

- **WHEN** the integration registry is built
- **THEN** the system MUST register `MapsProvider` with id='maps', group='docs', requiredApp='maps', storage='link-table'

### Requirement: Cached lat/lon

Link table SHALL include `lat`, `lon`, `address`, `address_source`. Rendering SHALL NOT call geocoding.

#### Scenario: Rendering uses cached fields

- **GIVEN** 20 linked locations on a dashboard
- **WHEN** `CnMapCard` renders
- **THEN** NO geocoding API calls MUST be made

### Requirement: Two Add Flows

Users SHALL be able to add a location by (a) entering an address (geocoded) or (b) clicking on the embedded map.

#### Scenario: Add by address or by map click

- **WHEN** a user adds a location to an object
- **THEN** the system MUST allow adding it either by entering an address (geocoded) or by clicking on the embedded map

### Requirement: Single-Entity is Address Chip

`surface='single-entity'` SHALL render an address chip (not an inline map). Click expands to a map popover.

#### Scenario: Single-entity renders address chip

- **WHEN** the integration renders at `surface='single-entity'`
- **THEN** the system MUST render an address chip rather than an inline map
- **AND** clicking the chip MUST expand to a map popover

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'maps'` SHALL render the address chip.

#### Scenario: Reference property renders address chip

- **WHEN** a property with `referenceType: 'maps'` is rendered
- **THEN** the system MUST render the address chip

### Requirement: Permission Inheritance

The system SHALL expose `requiresPermission() === null`; object and Maps ACLs apply.

#### Scenario: Permission inherited from object and Maps

- **WHEN** `requiresPermission()` is evaluated for the Maps provider
- **THEN** it MUST return `null` so that the object and Maps ACLs apply
