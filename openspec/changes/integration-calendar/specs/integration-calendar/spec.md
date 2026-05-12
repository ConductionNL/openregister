---
status: proposed
---

# Integration: Calendar

## Purpose

Surface Nextcloud Calendar VEVENT entries linked to OpenRegister objects through the standard integration registry contract. Users see meetings on object detail pages, dashboards, and inline next to schema reference properties; they can link, create, and unlink meetings without leaving the OR object context.

**Standards**: RFC 5545 (iCalendar), RFC 9253 (LINK property), Nextcloud CalDAV, ADR-019 (Integration Registry)
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md), [nextcloud-entity-relations](../../../../specs/nextcloud-entity-relations/spec.md)

---

## Requirements

### Requirement: Calendar Provider Registration

The system SHALL ship a `CalendarProvider` implementing `IntegrationProvider`. The provider SHALL be registered as a DI-tagged service and SHALL appear in `IntegrationRegistry::list()` with id `calendar` whenever the Nextcloud Calendar app is installed and enabled.

#### Scenario: Provider appears when Calendar app is installed

- **GIVEN** the NC Calendar app is installed and enabled
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the result MUST include a provider with `id='calendar'`, `group='comms'`, `requiredApp='calendar'`, `storageStrategy='link-table'`

#### Scenario: Provider hidden when Calendar app is missing

- **GIVEN** the NC Calendar app is not installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the result MUST NOT include the calendar provider
- **AND** `GET /api/integrations/calendar` MUST return HTTP 404
- **AND** the OCS capabilities `integrations` block MUST NOT include `calendar`

---

### Requirement: Sidebar Tab

The system SHALL render a `CnCalendarTab` component inside `CnObjectSidebar` when (a) the calendar provider is enabled in the registry AND (b) the object's schema has `calendar` in `linkedTypes` AND (c) the rendering component does not exclude `calendar`.

#### Scenario: Tab appears for schemas with calendar in linkedTypes

- **GIVEN** an object whose schema has `configuration.linkedTypes: ["calendar", "files"]`
- **AND** the user has read access to the object
- **WHEN** `CnObjectSidebar` renders for the object
- **THEN** a tab labeled "Meetings" MUST appear with the Calendar icon
- **AND** the tab MUST contain the linked VEVENTs ordered by date ascending

#### Scenario: Tab hidden for schemas without calendar in linkedTypes

- **GIVEN** an object whose schema has `configuration.linkedTypes: ["files", "notes"]` (calendar absent)
- **WHEN** `CnObjectSidebar` renders for the object
- **THEN** no Meetings tab MUST appear

#### Scenario: Inline meeting creation

- **GIVEN** the Meetings tab is visible
- **WHEN** the user fills the inline form (date, time, summary, attendees) and clicks "Add"
- **THEN** the system MUST POST to `/api/objects/{register}/{schema}/{id}/calendar` with the form data
- **AND** the new VEVENT MUST be created in the user's first writable calendar
- **AND** the X-OPENREGISTER-* properties MUST link the VEVENT to the object
- **AND** the new meeting MUST appear in the tab list without a page reload

#### Scenario: Unlink does not delete the VEVENT

- **GIVEN** a linked meeting in the tab
- **WHEN** the user clicks "Unlink"
- **THEN** the system MUST DELETE the link
- **AND** the VEVENT MUST remain in the user's NC Calendar
- **AND** the meeting MUST disappear from the tab list

---

### Requirement: Widget Across Surfaces

The system SHALL render `CnCalendarCard` for every surface defined in the integration registry. The component SHALL receive `surface` as a prop and render appropriately for each.

#### Scenario: User dashboard surface renders upcoming meetings

- **GIVEN** the user has 12 upcoming meetings linked across various objects
- **WHEN** `CnCalendarCard` renders with `surface='user-dashboard'`
- **THEN** the next 5 upcoming meetings MUST be displayed
- **AND** each MUST link to its object's detail page

#### Scenario: Detail page surface includes create CTA

- **GIVEN** an object with linked meetings
- **WHEN** `CnCalendarCard` renders with `surface='detail-page'` and the object's id
- **THEN** the meeting list MUST be rendered
- **AND** an "Add meeting" CTA MUST be visible

#### Scenario: Single-entity surface renders a chip

- **GIVEN** a VEVENT id `event-uuid-123`
- **WHEN** `CnCalendarCard` renders with `surface='single-entity'` and `entityId='event-uuid-123'`
- **THEN** the component MUST fetch the single VEVENT and render it as a compact chip with date, summary, and status icon

---

### Requirement: Reference-Property Auto-Rendering

When a schema property declares `referenceType: 'calendar'`, frontend form/detail components SHALL render `CnCalendarCard` at `surface='single-entity'` for the referenced VEVENT id.

#### Scenario: Detail grid renders calendar reference inline

- **GIVEN** a schema with property `nextMeeting: { type: 'string', referenceType: 'calendar' }`
- **AND** an object with `nextMeeting: 'event-uuid-456'`
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the `nextMeeting` row MUST contain `CnCalendarCard` with `entityId='event-uuid-456'` and `surface='single-entity'`
- **AND** raw VEVENT id MUST NOT be visible to the user

---

### Requirement: Permission Inheritance

The provider SHALL declare `requiresPermission(): null`. Access to the calendar integration SHALL be governed by (a) read access to the underlying object AND (b) NC Calendar's own access controls on the linked VEVENTs.

#### Scenario: User without object read access does not see meetings

- **GIVEN** a user with no read permission on object `abc-123`
- **WHEN** the user tries `GET /api/objects/{register}/{schema}/abc-123/calendar`
- **THEN** the system MUST return HTTP 403 (object permission denied, before integration check)

#### Scenario: User with object access but restricted calendar still works

- **GIVEN** a user with object read access AND a restricted view of NC Calendar (limited to one calendar)
- **WHEN** the user lists the object's meetings
- **THEN** only meetings on calendars the user can see MUST be returned (NC Calendar enforces this transitively)
