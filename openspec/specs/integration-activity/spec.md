---
status: done
---

# integration-activity Specification

## Purpose
Surfaces Nextcloud Activity events alongside OpenRegister cross-integration events as a blended, scope-filtered feed on an object, with event-type filter chips that persist the user's selection. Registers an Activity integration provider using a query-time storage strategy — listing is read directly from NC Activity, mutations return HTTP 501 — and renders the standard widget surfaces plus a single-event reference chip. Per-user visibility defers entirely to NC Activity's own filtering.
## Requirements
### Requirement: Activity Provider Registration

The system SHALL register `ActivityProvider` with id='activity', group='workflow', requiredApp='activity', storage='query-time' (new storage strategy value).

#### Scenario: Activity provider is registered

- **WHEN** the integration registry is enumerated
- **THEN** the system MUST include a provider with id='activity', group='workflow', requiredApp='activity', and storage='query-time'

### Requirement: Query-Time Storage Strategy

The provider SHALL implement `list()` by querying NC Activity filtered by object + linked entities. Mutation methods SHALL throw `NotImplementedException`.

#### Scenario: Mutation attempt returns 501

- **WHEN** `POST /api/objects/{register}/{schema}/{id}/activity` is called
- **THEN** the system MUST return HTTP 501 Not Implemented
- **AND** an explanatory message MUST reference NC Activity as the source of truth

### Requirement: Blended Feed

Tab SHALL show a unified feed of NC Activity events + OR cross-integration events (files linked, notes added, deck cards moved, etc.) filtered to the object's scope.

#### Scenario: Tab shows blended feed

- **WHEN** the Activity tab renders for an object
- **THEN** the system MUST show a unified feed of NC Activity events and OR cross-integration events filtered to the object's scope

### Requirement: Filter Chips

Tab SHALL provide event-type filter chips with persistence of the user's last selection.

#### Scenario: Filter chip selection persists

- **WHEN** a user selects event-type filter chips in the Activity tab
- **THEN** the system MUST persist the user's last selection

### Requirement: Widget Surfaces

The system SHALL render the standard four surfaces; the detail-page surface MUST mirror the tab.

#### Scenario: Detail-page surface mirrors the tab

- **WHEN** the Activity widget renders on the detail-page surface
- **THEN** the system MUST mirror the tab feed

### Requirement: Reference-Property (Niche)

`referenceType: 'activity'` SHALL render a single-event chip. Use cases are rare — activity events aren't typically referenced by schemas — but the contract is preserved for completeness.

#### Scenario: Activity reference renders a chip

- **WHEN** a schema property declares `referenceType: 'activity'`
- **THEN** the system MUST render a single-event chip

### Requirement: Permission Inheritance

The provider SHALL declare `requiresPermission() === null`; NC Activity's filtering governs per-user visibility.

#### Scenario: NC Activity governs visibility

- **WHEN** a user lists an object's activity events
- **THEN** the system MUST defer per-user visibility to NC Activity's own filtering

