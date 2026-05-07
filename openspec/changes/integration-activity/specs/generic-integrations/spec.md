---
status: proposed
---

# Integration: Activity

## Purpose

Surface NC Activity events relevant to an OR object through a query-time integration (no link table).

**Standards**: NC Activity API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Activity Provider Registration

`ActivityProvider` registered with id='activity', group='workflow', requiredApp='activity', storage='query-time' (new storage strategy value).

### Requirement: Query-Time Storage Strategy

The provider SHALL implement `list()` by querying NC Activity filtered by object + linked entities. Mutation methods SHALL throw `NotImplementedException`.

#### Scenario: Mutation attempt returns 501

- **WHEN** `POST /api/objects/{register}/{schema}/{id}/activity` is called
- **THEN** the system MUST return HTTP 501 Not Implemented
- **AND** an explanatory message MUST reference NC Activity as the source of truth

### Requirement: Blended Feed

Tab SHALL show a unified feed of NC Activity events + OR cross-integration events (files linked, notes added, deck cards moved, etc.) filtered to the object's scope.

### Requirement: Filter Chips

Tab SHALL provide event-type filter chips with persistence of the user's last selection.

### Requirement: Widget Surfaces

Per umbrella AD-6/AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); the `detail-page` surface mirrors the tab.

### Requirement: Reference-Property (Niche)

`referenceType: 'activity'` SHALL render a single-event chip. Use cases are rare — activity events aren't typically referenced by schemas — but the contract is preserved for completeness.

### Requirement: Permission Inheritance

`requiresPermission() === null`; NC Activity's filtering governs per-user visibility.

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying activity event in NC Activity is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Activity app disabled mid-session

- **GIVEN** the user had `CnActivityTab` open when an admin disabled NC Activity
- **WHEN** the next poll fetches new events
- **THEN** the tab MUST render a "Activity unavailable" state (no crash)
- **AND** the integration MUST disappear from the registry on the next request
