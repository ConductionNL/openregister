---
status: implemented
---

# Activity Provider

## Purpose

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
