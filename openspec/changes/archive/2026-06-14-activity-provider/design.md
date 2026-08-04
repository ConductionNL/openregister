# Design: Activity Provider

## Approach

Implement a Nextcloud Activity integration for OpenRegister using the standard `OCP\Activity` API. The integration consists of four layers:

1. **Event Listener** (`ActivityEventListener`) -- Listens to OpenRegister's existing `EventDispatcher` events and translates them into Nextcloud Activity events via `IManager::publish()`.
2. **Activity Service** (`ActivityService`) -- Central service encapsulating the `IManager::generateEvent()` + `publish()` logic with proper error handling and user resolution.
3. **Activity Provider** (`Provider`) -- Implements `IProvider::parse()` to convert stored activity events into human-readable entries with rich subject parameters.
4. **Activity Settings & Filter** -- `ActivitySettings` subclasses and `IFilter` implementation for user-configurable notifications and stream filtering.

The design leverages existing infrastructure:
- **Events**: Reuses all existing `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, `RegisterCreatedEvent`, `RegisterUpdatedEvent`, `RegisterDeletedEvent`, `SchemaCreatedEvent`, `SchemaUpdatedEvent`, `SchemaDeletedEvent` events -- no new events needed.
- **User context**: Uses `IUserSession` to determine the acting user (author).
- **URL generation**: Uses `IURLGenerator` for constructing deep links to objects, registers, and schemas in the activity stream.
- **Entity metadata**: Reads entity title/name directly from the event's entity object (`ObjectEntity::getTitle()` or `getName()`, `Register::getTitle()`, `Schema::getTitle()`).

## Architecture

```
OpenRegister Event System (existing)
    |
    v
ActivityEventListener (new, registered via registerEventListener)
    |-- handles ObjectCreatedEvent     --> ActivityService::publishObjectCreated()
    |-- handles ObjectUpdatedEvent     --> ActivityService::publishObjectUpdated()
    |-- handles ObjectDeletedEvent     --> ActivityService::publishObjectDeleted()
    |-- handles RegisterCreatedEvent   --> ActivityService::publishRegisterCreated()
    |-- handles RegisterUpdatedEvent   --> ActivityService::publishRegisterUpdated()
    |-- handles RegisterDeletedEvent   --> ActivityService::publishRegisterDeleted()
    |-- handles SchemaCreatedEvent     --> ActivityService::publishSchemaCreated()
    |-- handles SchemaUpdatedEvent     --> ActivityService::publishSchemaUpdated()
    |-- handles SchemaDeletedEvent     --> ActivityService::publishSchemaDeleted()
    |
    v
ActivityService (new)
    |-- generateEvent() + publish() via OCP\Activity\IManager
    |-- resolves author via IUserSession
    |-- builds subject parameters array
    |-- sets object link via IURLGenerator
    |
    v
Nextcloud Activity App (stores + displays)
    |
    v
Provider (new, IProvider)
    |-- parse() converts stored events to rich subjects
    |-- delegates to ProviderSubjectHandler for subject text
    |
    v
Filter (new, IFilter)           Settings (new, ActivitySettings subclasses)
    |-- filters stream by OR        |-- ObjectSetting (object CRUD)
    |                                |-- RegisterSetting (register CRUD)
                                     |-- SchemaSetting (schema CRUD)
```

## Files Affected

### New Files
- `lib/Activity/Provider.php` -- Main activity provider implementing `IProvider`. Constructor-injected with `IFactory` (L10N), `IURLGenerator`, `ProviderSubjectHandler`. The `parse()` method checks `$event->getApp() === 'openregister'`, validates the subject is in the handled list, then delegates to the subject handler for rich text formatting. Sets the app icon via `IURLGenerator::imagePath('openregister', 'app-dark.svg')`.

- `lib/Activity/ProviderSubjectHandler.php` -- Handles the mapping of activity subjects to human-readable parsed and rich subject strings. Uses a constant map for simple subjects (e.g., `object_created` -> `'Object created: {title}'`) and dedicated methods for subjects needing extra parameters (e.g., `object_updated` might include the schema name). Builds rich parameters with `type => 'highlight'` for entity titles.

- `lib/Activity/Filter.php` -- Implements `IFilter` for the activity sidebar. Returns identifier `'openregister'`, name `$l->t('Open Register')`, priority `50`, icon from `imagePath('openregister', 'app-dark.svg')`. `filterTypes()` returns all three OpenRegister activity types. `allowedApps()` returns `['openregister']`.

- `lib/Activity/Setting/ObjectSetting.php` -- Extends `ActivitySettings`. Identifier: `'openregister_objects'`. Group: `'openregister'` / `$l->t('Open Register')`. Controls activity stream and email notifications for object create/update/delete events. Stream enabled by default, mail disabled by default.

- `lib/Activity/Setting/RegisterSetting.php` -- Same pattern as ObjectSetting. Identifier: `'openregister_registers'`. Controls register CRUD activity.

- `lib/Activity/Setting/SchemaSetting.php` -- Same pattern. Identifier: `'openregister_schemas'`. Controls schema CRUD activity.

- `lib/Service/ActivityService.php` -- Central service for publishing activity events. Constructor-injected with `IManager`, `IUserSession`, `IURLGenerator`, `LoggerInterface`. Contains:
  - `publishObjectCreated(ObjectEntity $object)` -- publishes with subject `'object_created'`, type `'openregister_objects'`
  - `publishObjectUpdated(ObjectEntity $newObject, ?ObjectEntity $oldObject)` -- subject `'object_updated'`
  - `publishObjectDeleted(ObjectEntity $object)` -- subject `'object_deleted'`
  - `publishRegisterCreated(Register $register)` -- subject `'register_created'`, type `'openregister_registers'`
  - `publishRegisterUpdated(Register $register)` -- subject `'register_updated'`
  - `publishRegisterDeleted(Register $register)` -- subject `'register_deleted'`
  - `publishSchemaCreated(Schema $schema)` -- subject `'schema_created'`, type `'openregister_schemas'`
  - `publishSchemaUpdated(Schema $schema)` -- subject `'schema_updated'`
  - `publishSchemaDeleted(Schema $schema)` -- subject `'schema_deleted'`
  - Private `publish()` method encapsulating the `generateEvent()` -> `setApp()` -> `setType()` -> `setAuthor()` -> `setTimestamp()` -> `setSubject()` -> `setObject()` -> `setLink()` -> `setAffectedUser()` -> `publish()` flow.
  - All methods wrapped in try/catch to prevent activity failures from breaking core operations.

- `lib/Listener/ActivityEventListener.php` -- Event listener registered for all 9 entity events. Delegates to `ActivityService` methods. Implements `IEventListener` with a single `handle()` method that dispatches based on event class.

### Modified Files
- `lib/AppInfo/Application.php` -- Register the `ActivityEventListener` for all 9 events via `$context->registerEventListener()` in the existing `registerEventListeners()` method.

- `appinfo/info.xml` -- Add `<activity>` section with:
  ```xml
  <activity>
      <providers>
          <provider>OCA\OpenRegister\Activity\Provider</provider>
      </providers>
      <settings>
          <setting>OCA\OpenRegister\Activity\Setting\ObjectSetting</setting>
          <setting>OCA\OpenRegister\Activity\Setting\RegisterSetting</setting>
          <setting>OCA\OpenRegister\Activity\Setting\SchemaSetting</setting>
      </settings>
      <filters>
          <filter>OCA\OpenRegister\Activity\Filter</filter>
      </filters>
  </activity>
  ```

## Activity Subject Definitions

### Object Subjects
| Subject | Parsed Subject | Rich Subject | Parameters |
|---------|---------------|--------------|------------|
| `object_created` | `Object created: <title>` | `Object created: {title}` | `title` (highlight) |
| `object_updated` | `Object updated: <title>` | `Object updated: {title}` | `title` (highlight) |
| `object_deleted` | `Object deleted: <title>` | `Object deleted: {title}` | `title` (highlight) |

### Register Subjects
| Subject | Parsed Subject | Rich Subject | Parameters |
|---------|---------------|--------------|------------|
| `register_created` | `Register created: <title>` | `Register created: {title}` | `title` (highlight) |
| `register_updated` | `Register updated: <title>` | `Register updated: {title}` | `title` (highlight) |
| `register_deleted` | `Register deleted: <title>` | `Register deleted: {title}` | `title` (highlight) |

### Schema Subjects
| Subject | Parsed Subject | Rich Subject | Parameters |
|---------|---------------|--------------|------------|
| `schema_created` | `Schema created: <title>` | `Schema created: {title}` | `title` (highlight) |
| `schema_updated` | `Schema updated: <title>` | `Schema updated: {title}` | `title` (highlight) |
| `schema_deleted` | `Schema deleted: <title>` | `Schema deleted: {title}` | `title` (highlight) |

## Rich Parameter Format

All activity subjects use a `{title}` rich parameter:

```php
[
    'title' => [
        'type' => 'highlight',
        'id'   => (string) $event->getObjectId(),
        'name' => $entityTitle,
    ],
]
```

## Object Link Generation

Each activity event includes a link to the entity:
- **Objects**: `IURLGenerator::linkToRouteAbsolute('openregister.page.index') . '#/registers/{registerId}/schemas/{schemaId}/objects/{uuid}'`
- **Registers**: `IURLGenerator::linkToRouteAbsolute('openregister.page.index') . '#/registers/{registerId}'`
- **Schemas**: `IURLGenerator::linkToRouteAbsolute('openregister.page.index') . '#/registers/{registerId}/schemas/{schemaId}'` (if register context is available, otherwise just `#/schemas/{schemaId}`)

## Affected User Strategy

- **Object events**: The affected user is the current user (author). If the object has an `owner` field that differs from the author, the owner is ALSO notified (a second event is published for the owner).
- **Register/Schema events**: The affected user is the current user (these are typically admin operations).
- **System-initiated events** (e.g., background sync, API calls without user context): The affected user is set to the object owner if available, otherwise skipped (no activity published for system-only operations without a user context).

## Error Handling

- All `ActivityService::publish*()` methods wrap `IManager::publish()` in try/catch. Exceptions are logged at error level but NEVER propagated -- activity publishing failures MUST NOT break core OpenRegister operations.
- If `IUserSession::getUser()` returns null (system context), the author is set to empty string and affected user logic falls back to the object/register/schema owner.

## Performance Considerations

- Activity events are published synchronously within the request that triggers the entity event. This adds minimal overhead (single DB insert per activity event via IManager).
- The `ActivityEventListener` is lightweight -- it only extracts entity metadata and delegates to `ActivityService`.
- The `Provider::parse()` method is only called when activities are displayed (lazy rendering), not during event publishing.
- No additional database queries are needed during publishing -- all required data (title, ID, register/schema context) is available on the entity objects passed via events.
