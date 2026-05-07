---
status: implemented
---

# Calendar Provider

## Purpose

OpenRegister SHALL implement `OCP\Calendar\ICalendarProvider` to surface objects with date properties as read-only calendar events in the Nextcloud Calendar app. This enables users to see case deadlines, publication dates, hearing schedules, and other time-based data directly in their calendar without manual event creation.

**Key principle**: The calendar provider creates a *read-only projection* of object data. Objects remain the source of truth. Events are computed on-the-fly from object date fields, not stored as separate CalDAV items.

**Standards**: OCP\Calendar\ICalendarProvider (NC 23+), OCP\Calendar\ICalendar (NC 13+), RFC 5545 (iCalendar VEVENT format)
**Cross-references**: [object-interactions](../../../specs/object-interactions/spec.md), [rbac-scopes](../../../specs/rbac-scopes/spec.md), [faceting-configuration](../../../specs/faceting-configuration/spec.md)

---

## Requirements

### Requirement: Calendar Provider Registration

The application SHALL register an `ICalendarProvider` implementation via `IRegistrationContext::registerCalendarProvider()` during application bootstrap. This provider is called by the Nextcloud Calendar Manager when calendars are needed.

#### Rationale

Nextcloud's Calendar Manager lazily loads calendars from registered providers. By implementing `ICalendarProvider`, OpenRegister integrates into the native calendar infrastructure without modifying the Calendar app. Any app that queries calendars via `IManager` (Calendar, Dashboard widgets, search) will see OpenRegister events.

#### Scenario: Provider is registered during app bootstrap
- **GIVEN** the OpenRegister app is installed and enabled
- **WHEN** Nextcloud initializes the application
- **THEN** `RegisterCalendarProvider` MUST be registered via `$context->registerCalendarProvider(RegisterCalendarProvider::class)`
- **AND** the provider MUST be available to `IManager::getCalendarsForPrincipal()`

#### Scenario: Provider returns calendars for enabled schemas
- **GIVEN** 3 schemas exist: "Zaken" (calendar enabled), "Documenten" (calendar disabled), "Meldingen" (calendar enabled)
- **WHEN** `getCalendars('principals/users/admin')` is called
- **THEN** the provider MUST return exactly 2 `ICalendar` instances (for "Zaken" and "Meldingen")
- **AND** each calendar MUST have a unique key following the pattern `openregister-schema-{schemaId}`
- **AND** each calendar MUST have the display name and color from the schema's calendar configuration

#### Scenario: Provider filters by calendar URIs when specified
- **GIVEN** schemas "Zaken" (URI: `openregister-schema-5`) and "Meldingen" (URI: `openregister-schema-8`) are calendar-enabled
- **WHEN** `getCalendars('principals/users/admin', ['openregister-schema-5'])` is called
- **THEN** the provider MUST return only the "Zaken" calendar
- **AND** the "Meldingen" calendar MUST NOT be returned

#### Scenario: Provider returns empty array when no schemas are calendar-enabled
- **GIVEN** no schemas have `calendarProvider.enabled: true` in their configuration
- **WHEN** `getCalendars()` is called
- **THEN** the provider MUST return an empty array
- **AND** no errors MUST be thrown

#### Scenario: Provider handles schema loading errors gracefully
- **GIVEN** a database error occurs while loading schemas
- **WHEN** `getCalendars()` is called
- **THEN** the provider MUST log the error as a warning
- **AND** return an empty array (not throw an exception)

---

### Requirement: Virtual Calendar Implementation

Each calendar-enabled schema SHALL produce an `ICalendar` implementation that translates object queries into VEVENT-compatible arrays. The calendar is read-only and does not support write operations.

#### Rationale

The Nextcloud Calendar app calls `ICalendar::search()` to retrieve events for display. By translating OpenRegister objects into the expected VEVENT array format, objects appear as native calendar events with full date, title, description, and location support.

#### Scenario: Calendar returns correct metadata
- **GIVEN** a schema "Zaken" with ID 5 and calendar configuration `{"displayName": "Zaak Deadlines", "color": "#E64A19"}`
- **WHEN** the calendar's metadata methods are called
- **THEN** `getKey()` MUST return `"openregister-schema-5"`
- **AND** `getUri()` MUST return `"openregister-schema-5"`
- **AND** `getDisplayName()` MUST return `"Zaak Deadlines"`
- **AND** `getDisplayColor()` MUST return `"#E64A19"`
- **AND** `getPermissions()` MUST return `Constants::PERMISSION_READ` (read-only)
- **AND** `isDeleted()` MUST return `false`

#### Scenario: Calendar uses schema title as fallback display name
- **GIVEN** a schema "Meldingen" with ID 8 and calendar configuration without `displayName`
- **WHEN** `getDisplayName()` is called
- **THEN** it MUST return `"Meldingen"` (the schema title)

#### Scenario: Calendar uses default color when not configured
- **GIVEN** a schema with calendar configuration without `color`
- **WHEN** `getDisplayColor()` is called
- **THEN** it MUST return `"#0082C9"` (Nextcloud default blue)

---

### Requirement: Object-to-Event Search and Transformation

The virtual calendar's `search()` method SHALL query OpenRegister objects by date range and text pattern, then transform matching objects into VEVENT-compatible arrays.

#### Rationale

The Calendar app sends search requests with timerange options, text patterns, and pagination. The calendar must efficiently query objects using the existing MagicMapper infrastructure and return events in the standard Nextcloud format.

#### Scenario: Search with timerange returns matching events
- **GIVEN** schema "Zaken" is calendar-enabled with `dtstart: "startdatum"` and `dtend: "einddatum"`
- **AND** 3 objects exist:
  - Object A: startdatum=2026-03-20, einddatum=2026-03-25
  - Object B: startdatum=2026-04-01, einddatum=2026-04-15
  - Object C: startdatum=2026-05-01, einddatum=2026-05-10
- **WHEN** `search('', [], ['timerange' => ['start' => 2026-03-01, 'end' => 2026-03-31]])` is called
- **THEN** only Object A MUST be returned
- **AND** the event MUST include:
  - `id`: `"openregister-5-{objectA.uuid}"`
  - `type`: `"VEVENT"`
  - `calendar-key`: `"openregister-schema-5"`
  - `objects[0].DTSTART`: the startdatum value with appropriate VALUE parameter
  - `objects[0].DTEND`: the einddatum value with appropriate VALUE parameter

#### Scenario: Search with text pattern filters by summary
- **GIVEN** schema "Zaken" with `titleTemplate: "{identificatie} - {omschrijving}"`
- **AND** Object A has identificatie="ZK-001", omschrijving="Dakkapel"
- **AND** Object B has identificatie="ZK-002", omschrijving="Aanbouw"
- **WHEN** `search('Dakkapel', ['SUMMARY'])` is called
- **THEN** only Object A MUST be returned as a VEVENT

#### Scenario: Search without timerange returns all events
- **GIVEN** no timerange is specified in the options
- **WHEN** `search('')` is called
- **THEN** all objects with valid date values MUST be returned
- **AND** results MUST respect `$limit` and `$offset` for pagination

#### Scenario: All-day events from date-only fields
- **GIVEN** schema configuration with `allDay: true` and `dtstart: "publicatiedatum"`
- **AND** an object with `publicatiedatum: "2026-03-25"`
- **WHEN** the object is transformed to a VEVENT
- **THEN** DTSTART MUST be `['20260325', ['VALUE' => 'DATE']]`
- **AND** DTEND MUST be `['20260326', ['VALUE' => 'DATE']]` (next day for all-day display)
- **AND** no time component MUST be included

#### Scenario: DateTime events from datetime fields
- **GIVEN** schema configuration with `allDay: false` and `dtstart: "startdatum"`, `dtend: "einddatum"`
- **AND** an object with `startdatum: "2026-03-25T09:00:00Z"`, `einddatum: "2026-03-25T17:00:00Z"`
- **WHEN** the object is transformed to a VEVENT
- **THEN** DTSTART MUST be `['20260325T090000Z', ['VALUE' => 'DATE-TIME']]`
- **AND** DTEND MUST be `['20260325T170000Z', ['VALUE' => 'DATE-TIME']]`

#### Scenario: Events without dtend configured use dtstart as single point
- **GIVEN** schema configuration with `dtstart: "deadline"` and no `dtend` configured
- **AND** an object with `deadline: "2026-04-01"`
- **WHEN** the object is transformed to a VEVENT
- **THEN** DTSTART MUST be set to the deadline date
- **AND** DTEND MUST be set to dtstart + 1 day (for all-day) or dtstart + 1 hour (for datetime)
- **AND** the event MUST display correctly in the Calendar app

#### Scenario: Title template interpolation
- **GIVEN** schema configuration with `titleTemplate: "{identificatie} - {omschrijving}"`
- **AND** an object with data `{"identificatie": "ZK-2026-0142", "omschrijving": "Dakkapel Kerkstraat"}`
- **WHEN** the object is transformed to a VEVENT
- **THEN** SUMMARY MUST be `"ZK-2026-0142 - Dakkapel Kerkstraat"`

#### Scenario: Title template with missing fields uses fallback
- **GIVEN** schema configuration with `titleTemplate: "{identificatie} - {omschrijving}"`
- **AND** an object with data `{"identificatie": "ZK-2026-0142"}` (no omschrijving field)
- **WHEN** the object is transformed to a VEVENT
- **THEN** SUMMARY MUST be `"ZK-2026-0142 - "` (empty placeholder replaced with empty string)
- **AND** the event MUST still be valid

#### Scenario: Description template interpolation
- **GIVEN** schema configuration with `descriptionTemplate: "Status: {status}\nType: {zaaktype}"`
- **AND** an object with `status: "In behandeling"`, `zaaktype: "Omgevingsvergunning"`
- **WHEN** the object is transformed to a VEVENT
- **THEN** DESCRIPTION MUST be `"Status: In behandeling\nType: Omgevingsvergunning"`

#### Scenario: Location field mapping
- **GIVEN** schema configuration with `locationField: "adres"`
- **AND** an object with `adres: "Kerkstraat 42, 5038 AB Tilburg"`
- **WHEN** the object is transformed to a VEVENT
- **THEN** LOCATION MUST be `"Kerkstraat 42, 5038 AB Tilburg"`

#### Scenario: Objects with null/empty date fields are skipped
- **GIVEN** an object where the configured dtstart field is null or empty
- **WHEN** the object is encountered during a search
- **THEN** it MUST be silently skipped (not included in results)
- **AND** no error MUST be thrown

#### Scenario: Event URL links back to OpenRegister
- **GIVEN** an object with UUID `abc-123` in register 5, schema 12
- **WHEN** the object is transformed to a VEVENT
- **THEN** the URL property MUST be set to the OpenRegister object detail URL
- **AND** the format MUST be `/apps/openregister/#/objects/{register}/{schema}/{uuid}`

#### Scenario: Events are marked as transparent
- **GIVEN** any object transformed to a VEVENT
- **WHEN** the TRANSP property is set
- **THEN** it MUST be `"TRANSPARENT"` (virtual events do not block free/busy time)

#### Scenario: Events include OpenRegister category
- **GIVEN** any object transformed to a VEVENT
- **WHEN** the CATEGORIES property is set
- **THEN** it MUST include `"OpenRegister"` and the schema display name

#### Scenario: Status mapping from object fields
- **GIVEN** schema configuration with `statusMapping: {"open": "CONFIRMED", "afgerond": "CANCELLED"}`
- **AND** an object with a status field value of `"afgerond"`
- **WHEN** the object is transformed to a VEVENT
- **THEN** STATUS MUST be `"CANCELLED"`

#### Scenario: Default status when no mapping configured
- **GIVEN** schema configuration without `statusMapping`
- **WHEN** an object is transformed to a VEVENT
- **THEN** STATUS MUST default to `"CONFIRMED"`

---

### Requirement: RBAC Enforcement on Calendar Queries

The calendar provider SHALL enforce OpenRegister's authorization model. Users MUST only see events for objects they have read access to.

#### Rationale

OpenRegister supports row-level and schema-level RBAC. Calendar queries must respect these access controls to prevent information leakage through the Calendar app.

#### Scenario: User sees only authorized objects as events
- **GIVEN** user `behandelaar-1` has read access to objects in register 5 but not register 8
- **AND** both registers use schema "Zaken" (calendar-enabled)
- **WHEN** `search()` is called for `behandelaar-1`'s principal
- **THEN** only objects from register 5 MUST appear as events
- **AND** objects from register 8 MUST be filtered out

#### Scenario: Admin user sees all objects as events
- **GIVEN** an admin user queries the calendar
- **WHEN** `search()` is called
- **THEN** all objects with valid date values MUST appear, regardless of register

#### Scenario: Anonymous/public users see no virtual calendar events
- **GIVEN** an unauthenticated principal URI
- **WHEN** `getCalendars()` is called
- **THEN** the provider MUST return an empty array (no calendars for anonymous users)

#### Scenario: RBAC changes are immediately reflected
- **GIVEN** user `behandelaar-1` previously had access to an object
- **WHEN** RBAC is updated to revoke access
- **AND** the calendar is queried again
- **THEN** the revoked object MUST no longer appear as an event
- **AND** no caching MUST prevent this update from taking effect

---

### Requirement: Schema Calendar Configuration

The schema's `configuration` JSON field SHALL include a `calendarProvider` section that controls how objects are projected as calendar events. Configuration is managed via the existing schema API.

#### Rationale

Different schemas represent different data types with different date semantics. A "Zaak" schema has start/end dates and a case identifier, while a "Publicatie" schema has a single publication date and a title. The configuration must be flexible enough to handle these variations.

#### Configuration Schema

```json
{
  "calendarProvider": {
    "enabled": true,
    "displayName": "string (optional, falls back to schema title)",
    "color": "string (optional, CSS hex color, default #0082C9)",
    "dtstart": "string (required when enabled, property name for event start)",
    "dtend": "string (optional, property name for event end)",
    "titleTemplate": "string (required when enabled, template with {property} placeholders)",
    "descriptionTemplate": "string (optional, template with {property} placeholders)",
    "locationField": "string (optional, property name for LOCATION)",
    "allDay": "boolean (default: auto-detect from field format)",
    "statusMapping": "object (optional, maps object field values to VEVENT STATUS values)",
    "statusField": "string (optional, property name for status, used with statusMapping)"
  }
}
```

#### Scenario: Enable calendar provider on a schema
- **GIVEN** an admin user and schema "Zaken" with ID 5
- **WHEN** a PUT request updates the schema with:
  ```json
  {
    "configuration": {
      "calendarProvider": {
        "enabled": true,
        "dtstart": "startdatum",
        "dtend": "einddatum",
        "titleTemplate": "{identificatie} - {omschrijving}"
      }
    }
  }
  ```
- **THEN** the schema configuration MUST be saved
- **AND** the next `getCalendars()` call MUST include a calendar for this schema

#### Scenario: Disable calendar provider on a schema
- **GIVEN** schema "Zaken" has calendar provider enabled
- **WHEN** a PUT request updates with `calendarProvider.enabled: false`
- **THEN** the next `getCalendars()` call MUST NOT include a calendar for this schema

#### Scenario: Validation of required fields when enabling
- **GIVEN** a schema update request with `calendarProvider.enabled: true`
- **WHEN** the `dtstart` field is missing from the configuration
- **THEN** the API MUST return HTTP 400 with `{"error": "calendarProvider.dtstart is required when calendar provider is enabled"}`

#### Scenario: Validation of referenced property existence
- **GIVEN** a schema with properties `["startdatum", "einddatum", "titel"]`
- **WHEN** the calendar configuration references `dtstart: "deadline"` (not a schema property)
- **THEN** the API SHOULD log a warning but MUST NOT reject the configuration
- **AND** objects without the referenced field will be silently skipped during search

#### Scenario: Auto-detect allDay from property format
- **GIVEN** schema property `startdatum` has format `date` (no time component)
- **AND** `allDay` is not explicitly set in the calendar configuration
- **WHEN** the calendar generates events
- **THEN** events MUST be rendered as all-day events (VALUE=DATE)

#### Scenario: Auto-detect datetime from property format
- **GIVEN** schema property `begintijd` has format `date-time`
- **AND** `allDay` is not explicitly set
- **WHEN** the calendar generates events
- **THEN** events MUST be rendered as timed events (VALUE=DATE-TIME)

---

### Requirement: Frontend Configuration UI

A new tab SHALL be added to the schema detail view that allows administrators to configure the calendar provider settings via a visual form.

#### Rationale

Schema administrators need a user-friendly way to enable and configure calendar providers without manually editing JSON. The UI should show available date properties, provide template helpers, and show a preview.

#### Scenario: Calendar provider tab appears on schema detail
- **GIVEN** an admin user viewing the schema detail page
- **WHEN** the schema detail tabs are rendered
- **THEN** a "Calendar" tab MUST be visible
- **AND** clicking it MUST show the calendar provider configuration form

#### Scenario: Configuration form shows available properties
- **GIVEN** a schema with properties `startdatum` (date), `einddatum` (date), `titel` (string), `status` (string)
- **WHEN** the calendar provider tab is opened
- **THEN** the `dtstart` dropdown MUST show `startdatum` and `einddatum` as options
- **AND** the `dtend` dropdown MUST show the same date properties
- **AND** the `titleTemplate` field MUST show available placeholders: `{startdatum}`, `{einddatum}`, `{titel}`, `{status}`

#### Scenario: Saving configuration updates schema
- **GIVEN** the admin fills in the calendar provider form and clicks "Save"
- **WHEN** the save action is triggered
- **THEN** a PUT request MUST be sent to `/api/schemas/{id}` with the updated configuration
- **AND** a success notification MUST be shown

#### Scenario: Toggle to enable/disable calendar provider
- **GIVEN** the calendar provider tab is shown
- **WHEN** the admin toggles the "Enable calendar" switch
- **THEN** the form fields MUST be shown/hidden accordingly
- **AND** the enabled state MUST be saved as `calendarProvider.enabled`

---

### Requirement: Performance and Scalability

The calendar provider SHALL perform efficiently even with large numbers of objects, leveraging existing query infrastructure and respecting calendar query patterns.

#### Scenario: Timerange queries use database filtering
- **GIVEN** a schema with 10,000 objects spanning 3 years
- **WHEN** the Calendar app queries a single month (timerange)
- **THEN** the MagicMapper query MUST include a SQL WHERE clause on the date column
- **AND** only objects within the timerange MUST be loaded from the database
- **AND** the response time MUST be under 2 seconds for typical schemas

#### Scenario: Limit and offset are respected
- **GIVEN** 500 objects match a timerange query
- **WHEN** `search('', [], $options, limit: 50, offset: 0)` is called
- **THEN** only the first 50 events MUST be returned
- **AND** the MagicMapper query MUST use SQL LIMIT/OFFSET (not PHP array_slice)

#### Scenario: Schema list is cached within request
- **GIVEN** the Calendar app calls `getCalendars()` multiple times in the same request
- **WHEN** schema data is loaded
- **THEN** schemas MUST be loaded from database only once per request
- **AND** subsequent calls MUST use the cached result

#### Scenario: Disabled schemas are excluded at query level
- **GIVEN** 20 schemas exist, 3 have calendar provider enabled
- **WHEN** `getCalendars()` is called
- **THEN** only 3 schemas MUST be loaded/processed
- **AND** the SQL query MUST filter on the configuration JSON (or load all and filter in PHP if JSON queries are not supported)
