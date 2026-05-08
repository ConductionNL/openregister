# Tasks: Activity Provider

## Activity Service (Backend Core)

- [ ] Create `lib/Service/ActivityService.php` with constructor injection of `OCP\Activity\IManager`, `OCP\IUserSession`, `OCP\IURLGenerator`, `Psr\Log\LoggerInterface`
- [ ] Implement `publishObjectCreated(ObjectEntity $object)` setting subject `'object_created'`, type `'openregister_objects'`, with parameters `['title' => $title, 'schemaTitle' => $schemaTitle, 'registerTitle' => $registerTitle]`, object type `'object'`, and link to `#/registers/{registerId}/schemas/{schemaId}/objects/{uuid}`
- [ ] Implement `publishObjectUpdated(ObjectEntity $newObject, ?ObjectEntity $oldObject)` with subject `'object_updated'` and same type/link pattern
- [ ] Implement `publishObjectDeleted(ObjectEntity $object)` with subject `'object_deleted'` and empty link (entity no longer exists)
- [ ] Implement `publishRegisterCreated(Register $register)` with subject `'register_created'`, type `'openregister_registers'`, parameters `['title' => $title]`, object type `'register'`, and link to `#/registers/{registerId}`
- [ ] Implement `publishRegisterUpdated(Register $register)` with subject `'register_updated'`
- [ ] Implement `publishRegisterDeleted(Register $register)` with subject `'register_deleted'` and empty link
- [ ] Implement `publishSchemaCreated(Schema $schema)` with subject `'schema_created'`, type `'openregister_schemas'`, parameters `['title' => $title]`, object type `'schema'`
- [ ] Implement `publishSchemaUpdated(Schema $schema)` with subject `'schema_updated'`
- [ ] Implement `publishSchemaDeleted(Schema $schema)` with subject `'schema_deleted'` and empty link
- [ ] Implement private `publish()` method encapsulating `generateEvent()` -> `setApp('openregister')` -> `setType()` -> `setAuthor()` -> `setTimestamp(time())` -> `setSubject()` -> `setObject()` -> `setLink()` -> `setAffectedUser()` -> `IManager::publish()` with try/catch logging
- [ ] Handle dual-notification for object events: if object has an `owner` that differs from the current user, publish a second event with `affectedUser` set to the owner
- [ ] Handle system-context (no user session): set author to empty string, use object owner as affected user if available

## Event Listener

- [ ] Create `lib/Listener/ActivityEventListener.php` implementing `OCP\EventDispatcher\IEventListener` with constructor injection of `ActivityService`
- [ ] Implement `handle(Event $event)` with a match/instanceof dispatch: `ObjectCreatedEvent` -> `publishObjectCreated()`, `ObjectUpdatedEvent` -> `publishObjectUpdated()`, `ObjectDeletedEvent` -> `publishObjectDeleted()`, and same for Register and Schema events
- [ ] Register the listener in `Application::registerEventListeners()` for all 9 events: `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, `RegisterCreatedEvent`, `RegisterUpdatedEvent`, `RegisterDeletedEvent`, `SchemaCreatedEvent`, `SchemaUpdatedEvent`, `SchemaDeletedEvent`

## Activity Provider (Display)

- [ ] Create `lib/Activity/Provider.php` implementing `OCP\Activity\IProvider` with constructor injection of `OCP\L10N\IFactory`, `OCP\IURLGenerator`, `ProviderSubjectHandler`
- [ ] Implement `parse($language, IEvent $event, ?IEvent $previousEvent)`: check `$event->getApp() === 'openregister'`, check subject is in handled list, get L10N instance via `IFactory::get('openregister', $language)`, delegate to `ProviderSubjectHandler::applySubjectText()`, set icon via `IURLGenerator::getAbsoluteURL(imagePath('openregister', 'app-dark.svg'))`, throw `UnknownActivityException` for unhandled events
- [ ] Define handled subjects constant: `['object_created', 'object_updated', 'object_deleted', 'register_created', 'register_updated', 'register_deleted', 'schema_created', 'schema_updated', 'schema_deleted']`

## Provider Subject Handler

- [ ] Create `lib/Activity/ProviderSubjectHandler.php` with `applySubjectText(IEvent $event, object $l, array $params)` method
- [ ] Define subject-to-text mapping constant for all 9 subjects with parsed keys (e.g., `'Object created: %s'`) and rich keys (e.g., `'Object created: {title}'`)
- [ ] Build rich parameters with `'title' => ['type' => 'highlight', 'id' => (string) $event->getObjectId(), 'name' => $title]`
- [ ] Apply `setParsedSubject()` and `setRichSubject()` using the L10N translator for all subjects

## Activity Filter

- [ ] Create `lib/Activity/Filter.php` implementing `OCP\Activity\IFilter` with constructor injection of `OCP\IL10N`, `OCP\IURLGenerator`
- [ ] Implement `getIdentifier()` returning `'openregister'`, `getName()` returning `$l->t('Open Register')`, `getPriority()` returning `50`
- [ ] Implement `getIcon()` returning absolute URL to `imagePath('openregister', 'app-dark.svg')`
- [ ] Implement `filterTypes()` returning `['openregister_objects', 'openregister_registers', 'openregister_schemas']`
- [ ] Implement `allowedApps()` returning `['openregister']`

## Activity Settings

- [ ] Create `lib/Activity/Setting/ObjectSetting.php` extending `OCP\Activity\ActivitySettings` with constructor injection of `OCP\IL10N`
- [ ] Implement: `getIdentifier()` = `'openregister_objects'`, `getName()` = `$l->t('Object changes')`, `getGroupIdentifier()` = `'openregister'`, `getGroupName()` = `$l->t('Open Register')`, `getPriority()` = `51`, `canChangeStream()` = `true`, `isDefaultEnabledStream()` = `true`, `canChangeMail()` = `true`, `isDefaultEnabledMail()` = `false`
- [ ] Create `lib/Activity/Setting/RegisterSetting.php` with same pattern: `getIdentifier()` = `'openregister_registers'`, `getName()` = `$l->t('Register changes')`, `getPriority()` = `52`
- [ ] Create `lib/Activity/Setting/SchemaSetting.php` with same pattern: `getIdentifier()` = `'openregister_schemas'`, `getName()` = `$l->t('Schema changes')`, `getPriority()` = `53`

## App Registration (info.xml)

- [ ] Add `<activity>` section to `appinfo/info.xml` with `<providers><provider>OCA\OpenRegister\Activity\Provider</provider></providers>`, `<settings>` for all three settings, and `<filters><filter>OCA\OpenRegister\Activity\Filter</filter></filters>`

## Translations

- [ ] Add English translation strings for all 9 activity subjects: "Object created: %s", "Object updated: %s", "Object deleted: %s", "Register created: %s", "Register updated: %s", "Register deleted: %s", "Schema created: %s", "Schema updated: %s", "Schema deleted: %s"
- [ ] Add English translation strings for rich subjects: "Object created: {title}", "Object updated: {title}", etc.
- [ ] Add English translation strings for settings and filter: "Open Register", "Object changes", "Register changes", "Schema changes"
- [ ] Add Dutch translation strings for all 9 subjects: "Object aangemaakt: %s", "Object bijgewerkt: %s", "Object verwijderd: %s", "Register aangemaakt: %s", "Register bijgewerkt: %s", "Register verwijderd: %s", "Schema aangemaakt: %s", "Schema bijgewerkt: %s", "Schema verwijderd: %s"
- [ ] Add Dutch translation strings for settings: "Open Register", "Object wijzigingen", "Register wijzigingen", "Schema wijzigingen"

## Testing

- [ ] Write unit tests for `ActivityService::publish()` verifying correct event construction (app, type, author, subject, object, link, affectedUser, timestamp) for all 9 publish methods
- [ ] Write unit test verifying dual-notification: when object owner differs from author, two events are published
- [ ] Write unit test verifying graceful error handling: when `IManager::publish()` throws, the exception is caught and logged
- [ ] Write unit test verifying system-context handling: when no user session exists, author is empty and affected user falls back to owner
- [ ] Write unit tests for `Provider::parse()` covering all 9 subjects, verifying parsed subject text, rich subject, rich parameters, and icon
- [ ] Write unit test verifying `Provider::parse()` throws `UnknownActivityException` for foreign app events and unknown subjects
- [ ] Write unit tests for `ActivityEventListener::handle()` verifying correct dispatch for all 9 event types
- [ ] Write unit tests for `Filter` verifying identifier, name, icon, filterTypes, and allowedApps
- [ ] Write unit tests for all three Settings verifying identifier, name, group, priority, defaults
- [ ] Manual test: create an object and verify the activity appears in the Activity app sidebar with correct title, icon, and link
- [ ] Manual test: update and delete objects, registers, and schemas and verify corresponding activities appear
- [ ] Manual test: verify the "Open Register" filter in the Activity sidebar correctly filters to only OpenRegister events
- [ ] Manual test: verify activity settings appear under "Open Register" group in Activity settings page
- [ ] Manual test: verify activity still functions correctly when opencatalogi and softwarecatalog apps are enabled (no regressions)
