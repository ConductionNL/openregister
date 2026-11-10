# Design: Calendar Provider

## Approach

Register OpenRegister as an `ICalendarProvider` via `IRegistrationContext::registerCalendarProvider()` during app bootstrap. The provider creates one virtual `ICalendar` per schema that has calendar configuration enabled. Each virtual calendar translates object data into VEVENT-compatible search results that the Nextcloud Calendar app can display.

```
IRegistrationContext::registerCalendarProvider()
    -> RegisterCalendarProvider (ICalendarProvider)
        -> getCalendars(principalUri, calendarUris)
            -> For each calendar-enabled schema:
                -> RegisterCalendar (ICalendar)
                    -> search(pattern, searchProperties, options)
                        -> MagicMapper (date-range query)
                        -> RBAC filtering
                        -> Object -> VEVENT array transformation
```

## Architecture Decisions

### AD-1: One Virtual Calendar Per Schema (Not Per Register)

**Decision**: Each schema with calendar configuration produces exactly one virtual calendar, regardless of how many registers use that schema.

**Why**: Schemas define the structure (including date fields). Registers are containers. If schema "Zaak" is used in registers "Gemeente A" and "Gemeente B", the user should see ONE "Zaken" calendar with events from both registers (RBAC filters visibility). Creating per-register calendars would flood the calendar sidebar with duplicates of the same data type.

**Trade-off**: Users cannot toggle individual registers on/off within a schema's calendar. Acceptable because RBAC already scopes visibility, and schemas with the same name across registers represent the same concept.

### AD-2: Read-Only Virtual Events (No ICreateFromString)

**Decision**: Virtual calendars implement `ICalendar` but NOT `ICreateFromString`. Events are read-only projections of object data.

**Why**: The source of truth is the OpenRegister object. Creating events via the calendar would bypass schema validation, RBAC, audit trail, and workflow hooks. Users who need to edit dates should use the OpenRegister UI. The calendar is a *view*, not an *editor*.

**Trade-off**: Users cannot drag-and-drop to reschedule events in the Calendar app. This is intentional -- changing a case deadline is a business action that should go through proper channels.

### AD-3: Schema Configuration Over Global Settings

**Decision**: Calendar provider settings are stored in the schema's `configuration` JSON field under a `calendarProvider` key, not in a global app config.

**Why**: Different schemas need different mappings. A "Zaak" schema might map `startdatum` -> DTSTART and `einddatum` -> DTEND with title template `{identificatie} - {zaaktype}`. A "Publicatie" schema might map `publicatiedatum` -> DTSTART (all-day) with title `{titel}`. Global config cannot express per-schema differences.

**Structure**:
```json
{
  "calendarProvider": {
    "enabled": true,
    "displayName": "Zaken",
    "color": "#0082C9",
    "dtstart": "startdatum",
    "dtend": "einddatum",
    "titleTemplate": "{identificatie} - {omschrijving}",
    "descriptionTemplate": "Zaaktype: {zaaktype}\nStatus: {status}",
    "locationField": "locatie",
    "allDay": false
  }
}
```

### AD-4: Date-Range Query Via MagicMapper

**Decision**: Use the existing MagicMapper infrastructure to query objects by date range, rather than loading all objects and filtering in PHP.

**Why**: Nextcloud Calendar sends timerange parameters (`options['timerange']['start']` and `options['timerange']['end']`). With potentially thousands of objects, loading all and filtering is not feasible. MagicMapper already supports date comparisons on magic table columns.

**Implementation**: The calendar's `search()` method translates the timerange into a MagicMapper query filtering on the configured `dtstart` field: `WHERE {dtstart_column} >= :start AND {dtstart_column} <= :end`.

### AD-5: RBAC Enforcement Via Existing Infrastructure

**Decision**: Reuse `MagicRbacHandler` from the existing RBAC system rather than implementing calendar-specific authorization.

**Why**: The same user viewing the calendar is the same user who may or may not have access to specific objects. MagicMapper queries already apply RBAC filters. By routing calendar queries through MagicMapper, we get RBAC for free.

**Trade-off**: Calendar queries go through the full MagicMapper stack (RBAC, tenant isolation, etc.), which adds some overhead. Acceptable because calendar queries already have timerange limits that reduce the dataset.

### AD-6: Stable Event IDs From Object UUID

**Decision**: Use `openregister-{schemaId}-{objectUuid}` as the unique event identifier.

**Why**: Nextcloud Calendar may cache event IDs. Using the object UUID ensures stable, predictable identifiers that don't change when object data is updated. The schema ID prefix prevents collisions when the same object appears in multiple schema-calendars (edge case with schema inheritance).

### AD-7: Calendar URI Pattern

**Decision**: Use `openregister-schema-{schemaId}` as the calendar URI.

**Why**: The URI must be unique within the principal's scope and stable across requests. Schema IDs are immutable integers. The prefix `openregister-` avoids collisions with other calendar providers.

## Files Affected

### New Files (Backend)

| File | Purpose |
|------|---------|
| `lib/Calendar/RegisterCalendarProvider.php` | `ICalendarProvider` implementation -- returns virtual calendars for enabled schemas |
| `lib/Calendar/RegisterCalendar.php` | `ICalendar` implementation -- translates object queries into VEVENT arrays |
| `lib/Calendar/CalendarEventTransformer.php` | Transforms ObjectEntity + schema config into VEVENT-compatible arrays |

### Modified Files (Backend)

| File | Change |
|------|--------|
| `lib/AppInfo/Application.php` | Add `$context->registerCalendarProvider(RegisterCalendarProvider::class)` in `register()` |
| `lib/Db/Schema.php` | Add `getCalendarProviderConfig()` convenience method to extract config from `configuration` JSON |

### New Files (Frontend)

| File | Purpose |
|------|---------|
| `src/views/schemas/tabs/CalendarProviderTab.vue` | Schema detail tab for configuring calendar provider settings |

### Modified Files (Frontend)

| File | Change |
|------|--------|
| `src/views/schemas/SchemaDetail.vue` | Add CalendarProviderTab to schema detail tabs |

## Class Design

### RegisterCalendarProvider

```php
class RegisterCalendarProvider implements ICalendarProvider
{
    public function __construct(
        SchemaMapper $schemaMapper,
        MagicMapper $magicMapper,
        MagicRbacHandler $rbacHandler,
        IUserSession $userSession,
        LoggerInterface $logger
    );

    /**
     * Returns one RegisterCalendar per schema that has
     * calendarProvider.enabled = true in its configuration.
     */
    public function getCalendars(string $principalUri, array $calendarUris = []): array;
}
```

### RegisterCalendar

```php
class RegisterCalendar implements ICalendar
{
    public function __construct(
        Schema $schema,
        array $calendarConfig,
        MagicMapper $magicMapper,
        MagicRbacHandler $rbacHandler,
        CalendarEventTransformer $transformer,
        string $principalUri
    );

    public function getKey(): string;           // "openregister-schema-{id}"
    public function getUri(): string;           // "openregister-schema-{id}"
    public function getDisplayName(): ?string;  // from config or schema title
    public function getDisplayColor(): ?string; // from config
    public function getPermissions(): int;      // Constants::PERMISSION_READ
    public function isDeleted(): bool;          // false

    /**
     * Queries objects by date range and pattern, returns VEVENT arrays.
     * Respects RBAC via MagicMapper.
     */
    public function search(
        string $pattern,
        array $searchProperties = [],
        array $options = [],
        ?int $limit = null,
        ?int $offset = null
    ): array;
}
```

### CalendarEventTransformer

```php
class CalendarEventTransformer
{
    /**
     * Transforms an ObjectEntity into a VEVENT-compatible array
     * as expected by ICalendar::search() return format.
     */
    public function transform(
        ObjectEntity $object,
        Schema $schema,
        array $calendarConfig
    ): array;
}
```

## VEVENT Array Format

The `ICalendar::search()` method must return arrays in the Nextcloud Calendar format:

```php
[
    'id' => 'openregister-12-abc-123-uuid',
    'type' => 'VEVENT',
    'calendar-key' => 'openregister-schema-12',
    'calendar-uri' => 'openregister-schema-12',
    'objects' => [
        [
            'UID' => ['openregister-12-abc-123-uuid', []],
            'SUMMARY' => ['ZK-2026-0142 - Omgevingsvergunning dakkapel', []],
            'DTSTART' => ['20260325T000000Z', ['VALUE' => 'DATE']],  // or DATE-TIME
            'DTEND' => ['20260410T000000Z', ['VALUE' => 'DATE']],    // optional
            'DESCRIPTION' => ['Zaaktype: Omgevingsvergunning\nStatus: In behandeling', []],
            'LOCATION' => ['Kerkstraat 42, Tilburg', []],
            'STATUS' => ['CONFIRMED', []],
            'TRANSP' => ['TRANSPARENT', []],  // virtual events don't block time
            'URL' => ['/apps/openregister/#/objects/5/12/abc-123', []],
            'CATEGORIES' => [['OpenRegister', 'Zaken'], []],
        ],
    ],
]
```

## Schema Configuration API

The calendar provider configuration is part of the schema's existing `configuration` JSON field. No new API endpoints are needed -- schemas are updated via the existing `PUT /api/schemas/{id}` endpoint.

Example configuration payload:
```json
{
  "configuration": {
    "calendarProvider": {
      "enabled": true,
      "displayName": "Zaken Deadlines",
      "color": "#0082C9",
      "dtstart": "startdatum",
      "dtend": "einddatum",
      "titleTemplate": "{identificatie} - {omschrijving}",
      "descriptionTemplate": "Zaaktype: {zaaktype}\nStatus: {status}\nVerantwoordelijke: {verantwoordelijke}",
      "locationField": "locatie",
      "allDay": false,
      "statusMapping": {
        "open": "CONFIRMED",
        "afgerond": "CANCELLED",
        "in_behandeling": "CONFIRMED"
      }
    }
  }
}
```

## Performance Considerations

1. **Lazy loading**: `ICalendarProvider` is lazy -- `getCalendars()` is only called when the Calendar app actually needs calendar data. Schema loading is deferred.
2. **Date-range scoping**: Calendar queries always include a timerange. The MagicMapper query filters on the date column in SQL, not in PHP.
3. **Schema caching**: Calendar-enabled schemas are cached via Nextcloud's `IMemcache` for the duration of the request. The provider queries schemas once per `getCalendars()` call.
4. **No event materialization**: Events are never stored. They are computed from object data on each query. This ensures consistency but means calendar performance depends on object query performance.
5. **Limit/offset**: The `search()` method respects `$limit` and `$offset` parameters for pagination of large result sets.

## Migration Path

No database migration is needed. Calendar provider configuration is stored in the existing schema `configuration` JSON field. The feature is opt-in per schema -- no existing behavior changes.
