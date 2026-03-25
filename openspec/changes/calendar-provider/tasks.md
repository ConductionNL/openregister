# Tasks: Calendar Provider

## Provider Registration & Bootstrap

- [ ] Create `lib/Calendar/RegisterCalendarProvider.php` implementing `OCP\Calendar\ICalendarProvider`
  - Inject `SchemaMapper`, `MagicMapper`, `MagicRbacHandler`, `IUserSession`, `LoggerInterface`
  - `getCalendars()` loads all schemas with `calendarProvider.enabled: true` in configuration
  - Returns one `RegisterCalendar` per enabled schema
  - Filters by `$calendarUris` when provided (match against `openregister-schema-{id}`)
  - Catches exceptions and returns empty array on error (logged as warning)

- [ ] Register calendar provider in `lib/AppInfo/Application.php`
  - Add `$context->registerCalendarProvider(RegisterCalendarProvider::class)` in the `register()` method

## Virtual Calendar Implementation

- [ ] Create `lib/Calendar/RegisterCalendar.php` implementing `OCP\Calendar\ICalendar`
  - Constructor: `Schema`, calendar config array, `MagicMapper`, `MagicRbacHandler`, `CalendarEventTransformer`, principal URI
  - `getKey()` returns `"openregister-schema-{schemaId}"`
  - `getUri()` returns `"openregister-schema-{schemaId}"`
  - `getDisplayName()` returns config `displayName` or falls back to schema title
  - `getDisplayColor()` returns config `color` or defaults to `"#0082C9"`
  - `getPermissions()` returns `Constants::PERMISSION_READ`
  - `isDeleted()` returns `false`

- [ ] Implement `RegisterCalendar::search()` method
  - Extract timerange from `$options['timerange']['start']` and `$options['timerange']['end']`
  - Build MagicMapper query filtering on the configured `dtstart` field within the timerange
  - Apply RBAC filters via `MagicRbacHandler` using the stored principal URI
  - Apply text pattern matching on title-template fields when `$pattern` is non-empty
  - Respect `$limit` and `$offset` parameters (pass through to MagicMapper)
  - Transform each matching object into a VEVENT array via `CalendarEventTransformer`
  - Skip objects where the dtstart field is null or empty
  - Return array of VEVENT arrays in the Nextcloud ICalendar format

## Event Transformer

- [ ] Create `lib/Calendar/CalendarEventTransformer.php`
  - `transform(ObjectEntity $object, Schema $schema, array $calendarConfig): array`
  - Generate stable UID: `"openregister-{schemaId}-{objectUuid}"`
  - Interpolate `titleTemplate` by replacing `{property}` placeholders with object data values
  - Interpolate `descriptionTemplate` similarly (missing fields become empty strings)
  - Map `dtstart` field value to DTSTART with VALUE=DATE or VALUE=DATE-TIME
  - Map `dtend` field value to DTEND (if configured), or compute default end (dtstart + 1 day for all-day, dtstart + 1 hour for datetime)
  - Map `locationField` to LOCATION (if configured and value exists)
  - Map `statusField` through `statusMapping` to VEVENT STATUS (default: CONFIRMED)
  - Set TRANSP to TRANSPARENT (virtual events don't block time)
  - Set URL to OpenRegister object detail path: `/apps/openregister/#/objects/{register}/{schema}/{uuid}`
  - Set CATEGORIES to `["OpenRegister", schemaDisplayName]`
  - Set `calendar-key` and `calendar-uri` to `"openregister-schema-{schemaId}"`

- [ ] Implement allDay auto-detection in transformer
  - Check schema property format for the dtstart field
  - `format: date` -> allDay=true, VALUE=DATE
  - `format: date-time` -> allDay=false, VALUE=DATE-TIME
  - Explicit `allDay` in config overrides auto-detection
  - Parse date strings into proper iCalendar format (YYYYMMDD for DATE, YYYYMMDDTHHMMSSZ for DATE-TIME)

## Schema Configuration

- [ ] Add `getCalendarProviderConfig(): ?array` convenience method to `lib/Db/Schema.php`
  - Extract `calendarProvider` section from the `configuration` JSON field
  - Return null if not present or `enabled` is false
  - Return the full config array when enabled

- [ ] Add validation for calendar provider configuration in schema update logic
  - When `calendarProvider.enabled` is true, require `dtstart` and `titleTemplate` fields
  - Return HTTP 400 with descriptive error if required fields are missing
  - Log a warning (but do not reject) if referenced property names don't exist in schema properties

## RBAC Integration

- [ ] Integrate RBAC filtering in `RegisterCalendar::search()`
  - Extract user ID from the stored principal URI (format: `principals/users/{userId}`)
  - Pass user context to MagicMapper queries to enforce row-level and schema-level access controls
  - Ensure admin users can see all objects
  - Return empty results for anonymous/unauthenticated principals

## Frontend: Schema Calendar Configuration Tab

- [ ] Create `src/views/schemas/tabs/CalendarProviderTab.vue`
  - Toggle switch for `calendarProvider.enabled`
  - Dropdown for `dtstart` (populated with date/datetime schema properties)
  - Dropdown for `dtend` (optional, populated with date/datetime schema properties)
  - Text input for `titleTemplate` with helper showing available `{property}` placeholders
  - Textarea for `descriptionTemplate` with same placeholder helpers
  - Dropdown for `locationField` (optional, populated with string schema properties)
  - Color picker for `color`
  - Text input for `displayName` (optional, placeholder shows schema title)
  - Toggle for `allDay` (with "auto" option)
  - Optional status mapping section (key-value pairs of object status -> VEVENT status)
  - Save button that PUTs the updated configuration to `/api/schemas/{id}`

- [ ] Add CalendarProviderTab to schema detail view
  - Import and register the tab in `src/views/schemas/SchemaDetail.vue`
  - Add "Calendar" tab label with calendar icon
  - Pass schema data and properties to the tab component

## Testing

- [ ] Unit tests for `RegisterCalendarProvider`
  - Test `getCalendars()` returns correct count of calendars for enabled schemas
  - Test `getCalendars()` with URI filter returns only matching calendars
  - Test `getCalendars()` returns empty array when no schemas are enabled
  - Test graceful error handling when schema loading fails

- [ ] Unit tests for `RegisterCalendar`
  - Test metadata methods (`getKey`, `getUri`, `getDisplayName`, `getDisplayColor`, `getPermissions`, `isDeleted`)
  - Test fallback display name to schema title
  - Test default color when not configured
  - Test `search()` with timerange returns only matching objects
  - Test `search()` with pattern filters by summary
  - Test `search()` with limit and offset
  - Test `search()` skips objects with null date fields
  - Test RBAC filtering excludes unauthorized objects

- [ ] Unit tests for `CalendarEventTransformer`
  - Test all-day event transformation (VALUE=DATE)
  - Test datetime event transformation (VALUE=DATE-TIME)
  - Test title template interpolation with all fields present
  - Test title template with missing fields (empty string substitution)
  - Test description template interpolation
  - Test location field mapping
  - Test status mapping with configured mapping
  - Test default status when no mapping configured
  - Test TRANSP is always TRANSPARENT
  - Test URL generation
  - Test CATEGORIES include OpenRegister and schema name
  - Test UID stability (same object always produces same UID)
  - Test auto-detection of allDay from property format
  - Test explicit allDay override

- [ ] Integration test: Calendar visible in Nextcloud Calendar app
  - Enable calendar provider on a test schema
  - Create objects with date fields
  - Verify events appear in the Calendar app via browser test
  - Verify timerange filtering works correctly
  - Verify RBAC restricts visibility for non-admin users

## Documentation

- [ ] Add calendar provider section to schema configuration documentation
  - Document all configuration fields with examples
  - Provide common configuration patterns (zaak deadlines, publication dates, event schedules)
  - Document the auto-detection behavior for allDay
