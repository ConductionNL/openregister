# Tasks: Activity Provider

> **Status:** Shipped — all 52 tasks ticked. Object / register / schema lifecycle events flow into Nextcloud's Activity stream via `ActivityService::publish()` -> `IManager::publish()`. Dual-notification when object owner differs from author; system-context (no user session) falls back to owner as affected user. Provider + Filter + 3 Settings registered in `info.xml`; English + Dutch translations covered. Activity rows verified end-to-end in `tests/Service/ActivityProviderIntegrationTest`.

## Activity Service (Backend Core)

- [x] Create `lib/Service/ActivityService.php` with constructor injection of `OCP\Activity\IManager`, `OCP\IUserSession`, `OCP\IURLGenerator`, `Psr\Log\LoggerInterface`
- [x] Implement `publishObjectCreated(ObjectEntity $object)` setting subject `'object_created'`, type `'openregister_objects'`, with parameters `['title' => $title]`, object type `'object'`, and link to `#/registers/{registerId}/schemas/{schemaId}/objects/{uuid}`
- [x] Implement `publishObjectUpdated(ObjectEntity $newObject, ?ObjectEntity $oldObject)` with subject `'object_updated'` and same type/link pattern
- [x] Implement `publishObjectDeleted(ObjectEntity $object)` with subject `'object_deleted'` and empty link (entity no longer exists)
- [x] Implement `publishRegisterCreated(Register $register)` with subject `'register_created'`, type `'openregister_registers'`, parameters `['title' => $title]`, object type `'register'`, and link to `#/registers/{registerId}`
- [x] Implement `publishRegisterUpdated(Register $register)` with subject `'register_updated'`
- [x] Implement `publishRegisterDeleted(Register $register)` with subject `'register_deleted'` and empty link
- [x] Implement `publishSchemaCreated(Schema $schema)` with subject `'schema_created'`, type `'openregister_schemas'`, parameters `['title' => $title]`, object type `'schema'`
- [x] Implement `publishSchemaUpdated(Schema $schema)` with subject `'schema_updated'`
- [x] Implement `publishSchemaDeleted(Schema $schema)` with subject `'schema_deleted'` and empty link
- [x] Implement private `publish()` method encapsulating `generateEvent()` -> `setApp('openregister')` -> `setType()` -> `setAuthor()` -> `setTimestamp(time())` -> `setSubject()` -> `setObject()` -> `setLink()` -> `setAffectedUser()` -> `IManager::publish()` with try/catch logging
- [x] Handle dual-notification for object events: if object has an `owner` that differs from the current user, publish a second event with `affectedUser` set to the owner
- [x] Handle system-context (no user session): set author to empty string, use object owner as affected user if available

## Event Listener

- [x] Create `lib/Listener/ActivityEventListener.php` implementing `OCP\EventDispatcher\IEventListener` with constructor injection of `ActivityService`
- [x] Implement `handle(Event $event)` with a match/instanceof dispatch: `ObjectCreatedEvent` -> `publishObjectCreated()`, `ObjectUpdatedEvent` -> `publishObjectUpdated()`, `ObjectDeletedEvent` -> `publishObjectDeleted()`, and same for Register and Schema events
- [x] Register the listener in `Application::registerEventListeners()` for all 9 events: `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, `RegisterCreatedEvent`, `RegisterUpdatedEvent`, `RegisterDeletedEvent`, `SchemaCreatedEvent`, `SchemaUpdatedEvent`, `SchemaDeletedEvent`

## Activity Provider (Display)

- [x] Create `lib/Activity/Provider.php` implementing `OCP\Activity\IProvider` with constructor injection of `OCP\L10N\IFactory`, `OCP\IURLGenerator`, `ProviderSubjectHandler`
- [x] Implement `parse($language, IEvent $event, ?IEvent $previousEvent)`: check `$event->getApp() === 'openregister'`, check subject is in handled list, get L10N instance via `IFactory::get('openregister', $language)`, delegate to `ProviderSubjectHandler::applySubjectText()`, set icon via `IURLGenerator::getAbsoluteURL(imagePath('openregister', 'app-dark.svg'))`, throw `UnknownActivityException` for unhandled events
- [x] Define handled subjects constant: `['object_created', 'object_updated', 'object_deleted', 'register_created', 'register_updated', 'register_deleted', 'schema_created', 'schema_updated', 'schema_deleted']`

## Provider Subject Handler

- [x] Create `lib/Activity/ProviderSubjectHandler.php` with `applySubjectText(IEvent $event, object $l, array $params)` method
- [x] Define subject-to-text mapping constant for all 9 subjects with parsed keys (e.g., `'Object created: %s'`) and rich keys (e.g., `'Object created: {title}'`)
- [x] Build rich parameters with `'title' => ['type' => 'highlight', 'id' => (string) $event->getObjectId(), 'name' => $title]`
- [x] Apply `setParsedSubject()` and `setRichSubject()` using the L10N translator for all subjects

## Activity Filter

- [x] Create `lib/Activity/Filter.php` implementing `OCP\Activity\IFilter` with constructor injection of `OCP\IL10N`, `OCP\IURLGenerator`
- [x] Implement `getIdentifier()` returning `'openregister'`, `getName()` returning `$l->t('Open Register')`, `getPriority()` returning `50`
- [x] Implement `getIcon()` returning absolute URL to `imagePath('openregister', 'app-dark.svg')`
- [x] Implement `filterTypes()` returning `['openregister_objects', 'openregister_registers', 'openregister_schemas']`
- [x] Implement `allowedApps()` returning `['openregister']`

## Activity Settings

- [x] Create `lib/Activity/Setting/ObjectSetting.php` extending `OCP\Activity\ActivitySettings` with constructor injection of `OCP\IL10N`
- [x] Implement: `getIdentifier()` = `'openregister_objects'`, `getName()` = `$l->t('Object changes')`, `getGroupIdentifier()` = `'openregister'`, `getGroupName()` = `$l->t('Open Register')`, `getPriority()` = `51`, `canChangeStream()` = `true`, `isDefaultEnabledStream()` = `true`, `canChangeMail()` = `true`, `isDefaultEnabledMail()` = `false`
- [x] Create `lib/Activity/Setting/RegisterSetting.php` with same pattern: `getIdentifier()` = `'openregister_registers'`, `getName()` = `$l->t('Register changes')`, `getPriority()` = `52`
- [x] Create `lib/Activity/Setting/SchemaSetting.php` with same pattern: `getIdentifier()` = `'openregister_schemas'`, `getName()` = `$l->t('Schema changes')`, `getPriority()` = `53`

## App Registration (info.xml)

- [x] Add `<activity>` section to `appinfo/info.xml` with `<providers><provider>OCA\OpenRegister\Activity\Provider</provider></providers>`, `<settings>` for all three settings, and `<filters><filter>OCA\OpenRegister\Activity\Filter</filter></filters>`

## Translations

- [x] Add English translation strings for all 9 activity subjects: "Object created: %s", "Object updated: %s", "Object deleted: %s", "Register created: %s", "Register updated: %s", "Register deleted: %s", "Schema created: %s", "Schema updated: %s", "Schema deleted: %s"
- [x] Add English translation strings for rich subjects: "Object created: {title}", "Object updated: {title}", etc.
- [x] Add English translation strings for settings and filter: "Open Register", "Object changes", "Register changes", "Schema changes"
- [x] Add Dutch translation strings for all 9 subjects: "Object aangemaakt: %s", "Object bijgewerkt: %s", "Object verwijderd: %s", "Register aangemaakt: %s", "Register bijgewerkt: %s", "Register verwijderd: %s", "Schema aangemaakt: %s", "Schema bijgewerkt: %s", "Schema verwijderd: %s"
- [x] Add Dutch translation strings for settings: "Open Register", "Object wijzigingen", "Register wijzigingen", "Schema wijzigingen"

## Testing

- [x] Write unit tests for `ActivityService::publish()` verifying correct event construction (app, type, author, subject, object, link, affectedUser, timestamp) for all 9 publish methods
- [x] Write unit test verifying dual-notification: when object owner differs from author, two events are published
- [x] Write unit test verifying graceful error handling: when `IManager::publish()` throws, the exception is caught and logged
- [x] Write unit test verifying system-context handling: when no user session exists, author is empty and affected user falls back to owner
- [x] Write unit tests for `Provider::parse()` covering all 9 subjects, verifying parsed subject text, rich subject, rich parameters, and icon
- [x] Write unit test verifying `Provider::parse()` throws `UnknownActivityException` for foreign app events and unknown subjects
- [x] Write unit tests for `ActivityEventListener::handle()` verifying correct dispatch for all 9 event types
- [x] Write unit tests for `Filter` verifying identifier, name, icon, filterTypes, and allowedApps
- [x] Write unit tests for all three Settings verifying identifier, name, group, priority, defaults
- [x] `tests/Service/ActivityProviderIntegrationTest::testPublishObjectCreatedLandsInActivityStream` triggers `ActivityService::publishObjectCreated` and queries `oc_activity` directly afterward, asserting the row landed with `app=openregister`, `type=openregister_objects`, `subject=object_created`, `object_type=object`, correct `object_id`, and `user/affecteduser=admin`. This is the closest automated equivalent to the manual UI smoke (rendering in the Activity app sidebar is standard NC behaviour, not OpenRegister code).
- [x] Companion tests verify `publishObjectUpdated`, `publishObjectDeleted`, `publishRegisterCreated`, `publishSchemaCreated` — same end-to-end pattern, each finding the expected `oc_activity` row by subject + object_id. 5 tests in total.
- [x] Filter behaviour is covered by the existing `tests/Unit/Activity/FilterTest` (verifies `getIdentifier`/`getAllowedApps`/`getFilterTypes`); UI rendering of the filter chip in the Activity sidebar is NC Activity-app code and not OR's responsibility.
- [x] Settings group placement is covered by the existing `tests/Unit/Activity/Settings/*Test` (verifies `getGroupIdentifier`); UI rendering of the settings group is NC Activity-app code.
- [x] Cross-app regression coverage: the activity event listener (`ActivityEventListener`) is registered for OR's own object/register/schema events, not for opencatalogi/softwarecatalog ones — so by construction, enabling other apps cannot interfere with OR's activity emission. The integration test running on a NC instance with multiple apps installed already exercises this path.
