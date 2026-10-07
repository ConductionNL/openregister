# Tasks: events-at-the-level-of-change

## 1. Schema events

- [x] 1.1 Audit every `SchemaUpdatedEvent` listener for work that relies on firing on every import.
- [x] 1.2 `SchemaMapper::update()` dispatches only on a real change; `EntityChangeDetector` shared with registers.
- [x] 1.3 `SchemaImportInstaller` and `ImportHandler::installWhenNoEventFired()` keep the flow and webhook installs on an unchanged import.
- [x] 1.4 Tests: `SchemaMapperUpdateEventTest`, `ImportHandlerUnchangedSchemaInstallsTest` (real flow and webhook installers).

## 2. Object path

- [x] 2.1 Audit object create/update/patch/delete/bulk/import and object-event listeners for schema or register writes and events.
- [x] 2.2 `ObjectCrudFiresOnlyObjectEventsTest` pins the result.

## 3. Translatable PATCH

- [x] 3.1 `restoreStringTypedValues()` leaves translatable properties alone; test reproduces the reported nesting.
