---
status: done
---

# Activity Provider

## Purpose

@e2e exclude Nextcloud activity provider — backend-only, covered by PHPUnit

Integrate OpenRegister with Nextcloud's Activity app so that all CRUD operations on Objects, Registers, and Schemas are visible in the standard Nextcloud activity stream, dashboard activity widget, and (optionally) email notifications. This gives users and administrators a clear, auditable timeline of who changed what and when, using the standard `OCP\Activity` API (IManager, IProvider, IFilter, ActivitySettings).

**Source**: OpenRegister is a multi-user data registration platform where multiple people collaborate on structured data. Without Activity integration, users have no Nextcloud-native visibility into changes made by others. The existing internal event system (`ObjectCreatedEvent`, etc.) already dispatches events but they are not surfaced to end users.
## Requirements
### Requirement: OpenRegister MUST publish activity events for Object CRUD operations

When an object is created, updated, or deleted, the app MUST publish a corresponding activity event via `OCP\Activity\IManager::publish()`. The event MUST contain the app ID, activity type, author, timestamp, subject with parameters, object reference, and a link to the object in the OpenRegister UI.

#### Scenario: Object created activity is published
- **GIVEN** a user `admin` creates a new object with title `Omgevingsvergunning` in register `5`, schema `12`
- **WHEN** the `ObjectCreatedEvent` is dispatched
- **THEN** an activity event SHALL be published with:
  - `app` = `'openregister'`
  - `type` = `'openregister_objects'`
  - `subject` = `'object_created'` with parameters `['title' => 'Omgevingsvergunning', 'schemaTitle' => 'Producten', 'registerTitle' => 'Gemeente']`
  - `author` = `'admin'`
  - `affectedUser` = `'admin'`
  - `object` = `('object', <objectId>, 'Omgevingsvergunning')`
  - `link` pointing to `#/registers/5/schemas/12/objects/<uuid>`
  - `timestamp` = current Unix timestamp

#### Scenario: Object updated activity is published
- **GIVEN** a user `editor` updates an existing object with title `Omgevingsvergunning`
- **WHEN** the `ObjectUpdatedEvent` is dispatched
- **THEN** an activity event SHALL be published with:
  - `subject` = `'object_updated'`
  - `author` = `'editor'`
  - All other fields populated as in the creation scenario

#### Scenario: Object deleted activity is published
- **GIVEN** a user `admin` deletes an object with title `Omgevingsvergunning`
- **WHEN** the `ObjectDeletedEvent` is dispatched
- **THEN** an activity event SHALL be published with:
  - `subject` = `'object_deleted'`
  - `link` = empty string (object no longer exists)

#### Scenario: Object owner receives notification when another user modifies their object
- **GIVEN** an object owned by user `owner1` and a different user `editor` updates it
- **WHEN** the `ObjectUpdatedEvent` is dispatched
- **THEN** TWO activity events SHALL be published:
  - One with `affectedUser` = `'editor'` (the actor sees their own action)
  - One with `affectedUser` = `'owner1'` (the owner is notified of the change)

#### Scenario: Activity publishing failure does not break object operations
- **GIVEN** the Activity app is disabled or `IManager::publish()` throws an exception
- **WHEN** an object is created, updated, or deleted
- **THEN** the core operation SHALL succeed without error
- **AND** the exception SHALL be logged at error level

### Requirement: OpenRegister MUST publish activity events for Register CRUD operations

When a register is created, updated, or deleted, the app MUST publish a corresponding activity event with type `'openregister_registers'`.

#### Scenario: Register created activity is published
- **GIVEN** a user `admin` creates a new register with title `Gemeente Tilburg`
- **WHEN** the `RegisterCreatedEvent` is dispatched
- **THEN** an activity event SHALL be published with:
  - `type` = `'openregister_registers'`
  - `subject` = `'register_created'` with parameters `['title' => 'Gemeente Tilburg']`
  - `object` = `('register', <registerId>, 'Gemeente Tilburg')`
  - `link` pointing to `#/registers/<registerId>`

#### Scenario: Register updated activity is published
- **GIVEN** a user updates an existing register
- **WHEN** the `RegisterUpdatedEvent` is dispatched
- **THEN** an activity event SHALL be published with `subject` = `'register_updated'`

#### Scenario: Register deleted activity is published
- **GIVEN** a user deletes a register
- **WHEN** the `RegisterDeletedEvent` is dispatched
- **THEN** an activity event SHALL be published with `subject` = `'register_deleted'` and empty link

### Requirement: OpenRegister MUST publish activity events for Schema CRUD operations

When a schema is created, updated, or deleted, the app MUST publish a corresponding activity event with type `'openregister_schemas'`.

#### Scenario: Schema created activity is published
- **GIVEN** a user `admin` creates a new schema with title `Producten`
- **WHEN** the `SchemaCreatedEvent` is dispatched
- **THEN** an activity event SHALL be published with:
  - `type` = `'openregister_schemas'`
  - `subject` = `'schema_created'` with parameters `['title' => 'Producten']`
  - `object` = `('schema', <schemaId>, 'Producten')`

#### Scenario: Schema updated activity is published
- **GIVEN** a user updates an existing schema
- **WHEN** the `SchemaUpdatedEvent` is dispatched
- **THEN** an activity event SHALL be published with `subject` = `'schema_updated'`

#### Scenario: Schema deleted activity is published
- **GIVEN** a user deletes a schema
- **WHEN** the `SchemaDeletedEvent` is dispatched
- **THEN** an activity event SHALL be published with `subject` = `'schema_deleted'` and empty link

### Requirement: An IProvider MUST parse activity events into human-readable entries

A class implementing `OCP\Activity\IProvider` MUST be registered to parse OpenRegister activity events into rich, human-readable entries for display in the activity stream.

#### Scenario: Provider parses object_created event
- **GIVEN** an activity event with app `'openregister'` and subject `'object_created'` with parameter `title` = `'Omgevingsvergunning'`
- **WHEN** `Provider::parse()` is called
- **THEN** the event's parsed subject SHALL be set to `'Object created: Omgevingsvergunning'` (translated)
- **AND** the rich subject SHALL be set to `'Object created: {title}'` with a `highlight` parameter for the title
- **AND** the event icon SHALL be set to the OpenRegister app icon URL

#### Scenario: Provider parses all nine subjects
- **GIVEN** the provider handles subjects: `object_created`, `object_updated`, `object_deleted`, `register_created`, `register_updated`, `register_deleted`, `schema_created`, `schema_updated`, `schema_deleted`
- **WHEN** any of these subjects are passed to `parse()`
- **THEN** the provider SHALL return a valid parsed event with rich subject and icon
- **AND** unknown subjects SHALL cause `UnknownActivityException` to be thrown

#### Scenario: Provider throws UnknownActivityException for foreign events
- **GIVEN** an activity event with app `'files'` or an unrecognized subject
- **WHEN** `Provider::parse()` is called
- **THEN** it SHALL throw `OCP\Activity\Exceptions\UnknownActivityException`

### Requirement: An IFilter MUST allow users to filter the activity stream for OpenRegister events

A class implementing `OCP\Activity\IFilter` MUST be registered so users can view only OpenRegister activity in the activity sidebar.

#### Scenario: Filter appears in activity sidebar
- **GIVEN** the OpenRegister app is enabled
- **WHEN** a user opens the Activity app sidebar
- **THEN** a filter entry titled `t('openregister', 'Open Register')` SHALL appear
- **AND** the filter SHALL display the OpenRegister app icon
- **AND** selecting the filter SHALL show only events from the `openregister` app

#### Scenario: Filter returns correct activity types
- **GIVEN** the filter is applied
- **WHEN** `filterTypes()` is called
- **THEN** it SHALL return `['openregister_objects', 'openregister_registers', 'openregister_schemas']`
- **AND** `allowedApps()` SHALL return `['openregister']`

### Requirement: ActivitySettings subclasses MUST allow per-type notification configuration

Three `ActivitySettings` subclasses MUST be registered so users can independently configure stream and email notification preferences for object, register, and schema activities.

#### Scenario: Object activity setting
- **GIVEN** the activity settings page
- **WHEN** OpenRegister settings are displayed
- **THEN** a setting with identifier `'openregister_objects'` and name `t('openregister', 'Object changes')` SHALL appear
- **AND** it SHALL be in the group `'openregister'` with group name `t('openregister', 'Open Register')`
- **AND** stream SHALL be enabled by default
- **AND** mail SHALL be disabled by default
- **AND** both stream and mail SHALL be user-changeable

#### Scenario: Register activity setting
- **GIVEN** the activity settings page
- **WHEN** OpenRegister settings are displayed
- **THEN** a setting with identifier `'openregister_registers'` and name `t('openregister', 'Register changes')` SHALL appear
- **AND** it SHALL share the group `'openregister'`

#### Scenario: Schema activity setting
- **GIVEN** the activity settings page
- **WHEN** OpenRegister settings are displayed
- **THEN** a setting with identifier `'openregister_schemas'` and name `t('openregister', 'Schema changes')` SHALL appear
- **AND** it SHALL share the group `'openregister'`

### Requirement: Activity components MUST be registered via info.xml

The provider, settings, and filter MUST be declared in `appinfo/info.xml` under the `<activity>` section so Nextcloud auto-discovers them.

#### Scenario: info.xml declares activity components
- **GIVEN** the `appinfo/info.xml` file
- **WHEN** Nextcloud reads app metadata
- **THEN** the `<activity>` section SHALL contain:
  - `<provider>OCA\OpenRegister\Activity\Provider</provider>`
  - `<setting>OCA\OpenRegister\Activity\Setting\ObjectSetting</setting>`
  - `<setting>OCA\OpenRegister\Activity\Setting\RegisterSetting</setting>`
  - `<setting>OCA\OpenRegister\Activity\Setting\SchemaSetting</setting>`
  - `<filter>OCA\OpenRegister\Activity\Filter</filter>`

### Requirement: The ActivityEventListener MUST be registered for all entity events

A single event listener class MUST handle all nine OpenRegister entity events and delegate to the `ActivityService` for publishing.

#### Scenario: Listener is registered for all events
- **GIVEN** the `Application::register()` method
- **WHEN** the app boots
- **THEN** `$context->registerEventListener()` SHALL be called for:
  - `ObjectCreatedEvent::class` -> `ActivityEventListener::class`
  - `ObjectUpdatedEvent::class` -> `ActivityEventListener::class`
  - `ObjectDeletedEvent::class` -> `ActivityEventListener::class`
  - `RegisterCreatedEvent::class` -> `ActivityEventListener::class`
  - `RegisterUpdatedEvent::class` -> `ActivityEventListener::class`
  - `RegisterDeletedEvent::class` -> `ActivityEventListener::class`
  - `SchemaCreatedEvent::class` -> `ActivityEventListener::class`
  - `SchemaUpdatedEvent::class` -> `ActivityEventListener::class`
  - `SchemaDeletedEvent::class` -> `ActivityEventListener::class`

#### Scenario: Listener dispatches to correct service methods
- **GIVEN** an `ObjectCreatedEvent` is received by the listener
- **WHEN** `handle()` is called
- **THEN** it SHALL call `ActivityService::publishObjectCreated()` with the object from the event
- **AND** the same dispatch pattern SHALL apply for all nine event types

### Requirement: i18n MUST be applied to all user-visible strings

All user-visible strings in the Provider, Filter, and Settings MUST use `IL10N` / `IFactory` for translation. Dutch and English translations MUST be provided as minimum per ADR-005.

#### Scenario: Activity subjects are translated
- **GIVEN** a user with Nextcloud locale set to `nl`
- **WHEN** the activity stream displays an `object_created` event
- **THEN** the parsed subject SHALL use Dutch translation (e.g., `'Object aangemaakt: Omgevingsvergunning'`)

#### Scenario: Filter name is translated
- **GIVEN** a user with locale `nl`
- **WHEN** the activity filter list is displayed
- **THEN** the OpenRegister filter name SHALL be `'Open Register'` (same in both languages as it is a product name)

#### Scenario: Setting names are translated
- **GIVEN** a user with locale `nl`
- **WHEN** the activity settings page shows OpenRegister settings
- **THEN** the setting names SHALL be the Dutch translations:
  - `'Object wijzigingen'` for object setting
  - `'Register wijzigingen'` for register setting
  - `'Schema wijzigingen'` for schema setting

### Requirement: Tier-2 Object Activity Read Surface
The service MUST resolve NC Activity entries linked to an OR object via the `[or:{objectUuid}]` subject marker, apply optional type / actor / date-range filters, return a bounded cursor-paginated page ordered newest-first, and return an empty result set (never throw) when the NC Activity app is not installed or a query fails.

`ActivityFilterService::getActivityEntries()` MUST match entries by the `[or:{objectUuid}]` marker in the `activity.subject` column, MUST apply the optional exact `type`, exact `actor` (`affecteduser`), and `after` (Unix-timestamp lower bound) filters when supplied, MUST clamp the requested page size into `[1, MAX_LIMIT]` defaulting to `DEFAULT_LIMIT`, MUST page descending by `timestamp` then `activity_id` using a strict-less-than cursor, and MUST return `{ results, total, nextCursor }` where `total` is the filter-set count ignoring the cursor and `nextCursor` is null on the last page. When the Activity app is unavailable the result MUST be `{ results: [], total: 0, nextCursor: null }`, and any DB error MUST be logged and degraded to an empty result rather than propagated.

#### Scenario: Filtered page returned with next cursor
- **GIVEN** an OR object has more linked activity entries than one page
- **WHEN** `getActivityEntries()` is called with a type filter and a page limit
- **THEN** only marker-matched entries of that type MUST be returned, newest-first
- **AND** `nextCursor` MUST carry the oldest returned entry's timestamp so the next call resumes correctly

#### Scenario: Activity app absent degrades to empty
- **GIVEN** the NC Activity app is not installed
- **WHEN** `getActivityEntries()` is called
- **THEN** the result MUST be `{ results: [], total: 0, nextCursor: null }` without throwing

### Requirement: RegisterSetting MUST expose the concrete contract values consumed by the NC Activity app

The `RegisterSetting` ActivitySettings subclass MUST report the following concrete values so the NC Activity settings page renders the row with the correct identifier, group membership, priority order, and default-enabled flags.

#### Scenario: RegisterSetting concrete values
- **GIVEN** an instance of `OCA\OpenRegister\Activity\Setting\RegisterSetting` constructed with an `IL10N` localization service
- **WHEN** the NC Activity settings page queries the setting
- **THEN** the methods SHALL return:
  - `getIdentifier()` = `'openregister_registers'`
  - `getName()` = `$l->t('Register changes')`
  - `getGroupIdentifier()` = `'openregister'`
  - `getGroupName()` = `$l->t('Open Register')`
  - `getPriority()` = `52`
  - `canChangeStream()` = `true`
  - `isDefaultEnabledStream()` = `true`
  - `canChangeMail()` = `true`
  - `isDefaultEnabledMail()` = `false`

### Requirement: An ActivityProvider MUST be exposed as an OpenRegister integration provider for the NC Activity app

In addition to the NC Activity `IProvider` (which parses raw activity events into rich subjects), OpenRegister MUST expose a second-surface `ActivityProvider` that participates in the OR integration-provider registry. This surface lets OR objects show their related NC Activity rows in the registry tab UI. The class MUST extend `AbstractIntegrationProvider` and use the `MarkerLookupTrait`.

#### Scenario: ActivityProvider declares its integration contract
- **GIVEN** an instance of `OCA\OpenRegister\Service\Integration\Providers\ActivityProvider`
- **WHEN** the integration-provider registry queries its contract methods
- **THEN** the methods SHALL return:
  - `getId()` = `'activity'`
  - `getLabel()` = `$l10n->t('Activity')`
  - `getIcon()` = `'Timeline'` (Material Design icon name)
  - `getGroup()` = `'workflow'`
  - `getRequiredApp()` = `'activity'`
  - `getStorageStrategy()` = `'query-time'`
  - `isEnabled()` = `$appManager->isInstalled('activity')`

### Requirement: ActivityProvider MUST list NC Activity rows linked to an OR object via a `[or:{uuid}]` marker

`ActivityProvider::list($register, $schema, $objectId, $filters = [])` MUST return the NC Activity rows whose `oc_activity.subject` column contains the marker `[or:{objectId}]`. The marker convention is the "link-table-on-upstream" variant of the registry storage strategy — the marker lives in the NC Activity table, not in an OR-owned link table. When the NC Activity app is disabled, the method MUST return `[]` instead of querying the DB.

#### Scenario: Disabled NC Activity app returns empty list
- **GIVEN** the NC Activity app is not installed
- **WHEN** `ActivityProvider::list('reg', 'sch', '<uuid>')` is called
- **THEN** the method SHALL return `[]` without querying the database

#### Scenario: Marker query returns mapped leaf rows
- **GIVEN** the NC Activity app is enabled
- **AND** `oc_activity` contains rows whose `subject` column includes the substring `[or:abc-123]`
- **WHEN** `ActivityProvider::list('reg', 'sch', 'abc-123')` is called
- **THEN** the method SHALL invoke `MarkerLookupTrait::findByMarker(db: ..., table: 'activity', markerColumn: 'subject', marker: '[or:abc-123]', extraColumns: ['type', 'affecteduser', 'timestamp', 'object_id'], idColumn: 'activity_id')`
- **AND** SHALL return one entry per matched row shaped as `{id: <activity_id>, title: <subject>, url: '/index.php/apps/activity/<activity_id>', data: <full row>}`

### Requirement: ActivityProvider::health() MUST report NC Activity app availability

`ActivityProvider::health()` MUST return a registry-health response that reflects whether the upstream NC Activity app is installed. Because no per-user authentication is required to query `oc_activity`, the `authStatus` SHALL always be `'configured'`.

#### Scenario: Health response when NC Activity is installed
- **GIVEN** the NC Activity app is installed
- **WHEN** `ActivityProvider::health()` is called
- **THEN** it SHALL return `['status' => 'ok', 'authStatus' => 'configured', 'message' => null]`

#### Scenario: Health response when NC Activity is missing
- **GIVEN** the NC Activity app is not installed
- **WHEN** `ActivityProvider::health()` is called
- **THEN** it SHALL return `['status' => 'unavailable', 'authStatus' => 'configured', 'message' => 'NC Activity app is not installed']`

### Requirement: The account page MUST expose a paginated per-user activity feed with a type filter

The account-page `ActivitySection.vue` component MUST render a paginated list of the current user's activity events, sourced from `GET /apps/openregister/api/user/me/activity`. The list MUST support offset-based paging (default page size 25) and a single-select type filter with the options `'create' | 'update' | 'delete'`.

#### Scenario: Initial load
- **GIVEN** the section is mounted (or the type filter is changed)
- **WHEN** `loadActivity()` runs
- **THEN** it SHALL reset `offset = 0` and `activities = []`
- **AND** SHALL call `GET /apps/openregister/api/user/me/activity?_limit=25&_offset=0` (with `&type=<filter>` appended when a filter is selected)
- **AND** SHALL replace the in-memory `activities` array with `data.results` and set `total = data.total ?? 0`

#### Scenario: Load more
- **GIVEN** `activities.length < total`
- **WHEN** the "Load more" button is clicked
- **THEN** `loadMore()` SHALL increment `offset` by `limit` and call `fetchActivity()`
- **AND** the new page's `results` SHALL be appended to the existing array (not replaced)

#### Scenario: Network errors are swallowed
- **GIVEN** the API call fails
- **WHEN** `fetchActivity()` runs
- **THEN** the error SHALL be silently caught
- **AND** `loading` SHALL be reset to `false` in the `finally` block

## Current Implementation Status

**Not yet implemented.** The following existing infrastructure supports this feature:

- All 9 entity events (`ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, `RegisterCreatedEvent`, `RegisterUpdatedEvent`, `RegisterDeletedEvent`, `SchemaCreatedEvent`, `SchemaUpdatedEvent`, `SchemaDeletedEvent`) are already dispatched by the existing services.
- `Application::register()` already has a `registerEventListeners()` method where the new listener registrations will be added.
- `IUserSession` is already available throughout the service layer for author resolution.
- The Pipelinq app (`pipelinq/lib/Activity/`) provides a working reference implementation of the same pattern within the Conduction codebase.

**Not yet implemented:**
- `lib/Activity/Provider.php` (IProvider)
- `lib/Activity/ProviderSubjectHandler.php` (subject text mapping)
- `lib/Activity/Filter.php` (IFilter)
- `lib/Activity/Setting/ObjectSetting.php` (ActivitySettings)
- `lib/Activity/Setting/RegisterSetting.php` (ActivitySettings)
- `lib/Activity/Setting/SchemaSetting.php` (ActivitySettings)
- `lib/Service/ActivityService.php` (event publishing)
- `lib/Listener/ActivityEventListener.php` (event-to-activity bridge)
- `appinfo/info.xml` `<activity>` section
- Translation strings for all subjects, settings, and filter

## Standards & References

- Nextcloud Activity Manager API: `OCP\Activity\IManager` (NC 6+)
- Nextcloud Activity Provider API: `OCP\Activity\IProvider` (NC 11+)
- Nextcloud Activity Filter API: `OCP\Activity\IFilter` (NC 11+)
- Nextcloud Activity Settings API: `OCP\Activity\ActivitySettings` (NC 20+)
- Nextcloud Activity documentation: https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/activity.html
- ADR-005: Dutch and English required for all UI strings
- Reference implementation: `pipelinq/lib/Activity/` (same codebase)

## Cross-References

- `event-driven-architecture` -- OpenRegister's existing event system that this feature builds on
- `audit-trail-immutable` -- Activity provider complements the immutable audit trail with user-facing visibility
- `notificatie-engine` -- Future notification engine may leverage activity events
- `i18n-infrastructure` -- Translation infrastructure for PHP strings
