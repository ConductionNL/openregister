# event-driven-architecture

## ADDED Requirements

### Requirement: A schema save that changes nothing publishes nothing

The system SHALL dispatch `SchemaUpdatedEvent` from `SchemaMapper::update()` only
when the stored schema differs from the schema before the save, ignoring the
`updated` timestamp. A save that changes nothing SHALL NOT write a
`schema_updated` activity, SHALL NOT send a `schema-changed` notification and
SHALL NOT fire a schema webhook. A real change SHALL still do all three.

#### Scenario: an app re-imports an identical schema

- **GIVEN** the pipelinq `lead` schema is installed and unchanged
- **WHEN** pipelinq imports its register again
- **THEN** no `SchemaUpdatedEvent` is dispatched for `lead`
- @e2e exclude {covered by SchemaMapperUpdateEventTest, which drives the real update() with real Schema and SchemaUpdatedEvent classes}

#### Scenario: an import adds a property

- **GIVEN** the stored `lead` schema
- **WHEN** an import adds the property `stage`
- **THEN** exactly one `SchemaUpdatedEvent` is dispatched, carrying the old and the new schema
- @e2e exclude {covered by SchemaMapperUpdateEventTest}

### Requirement: An import of an unchanged schema still installs what it declares

The system SHALL run the flow importer and the notification webhook installer
for an imported schema whose saves dispatched no `SchemaUpdatedEvent`, so a
shipped flow or webhook that was deleted, or never installed, arrives on the
next import. When the import's saves dispatched an event, the import SHALL NOT
run them again.

#### Scenario: a deleted shipped flow comes back on re-import

- **GIVEN** a schema declaring the flow `Qualify` in `x-openregister-flows`, and no stored flow by that name
- **WHEN** the app imports the identical schema
- **THEN** the flow `Qualify` is stored, disabled and unowned, as on a first import
- @e2e exclude {covered by ImportHandlerUnchangedSchemaInstallsTest with the real SchemaFlowImportListener}

#### Scenario: a changed import does not install twice

- **GIVEN** an import whose save dispatched a `SchemaUpdatedEvent`
- **WHEN** the import finishes
- **THEN** the import itself installs nothing, because the listeners already did
- @e2e exclude {covered by ImportHandlerUnchangedSchemaInstallsTest}

### Requirement: Object CRUD fires object events only

Creating, updating, patching, deleting, bulk-saving or importing objects SHALL
dispatch object events (and the object notification) only. It SHALL NOT write a
schema or a register entity and SHALL NOT dispatch a schema or register event.
No listener of an object event SHALL do so either.

#### Scenario: a user edits a lead

- **GIVEN** a lead in the pipelinq register
- **WHEN** the user PATCHes it
- **THEN** one object update is published and no `SchemaUpdatedEvent` or `RegisterUpdatedEvent` is dispatched
- @e2e exclude {pinned by ObjectCrudFiresOnlyObjectEventsTest over the object path and every object-event listener; checked live on 2026-10-06 with one object_updated activity per PATCH}

### Requirement: A PATCH leaves an untouched translatable property as it is

A PATCH that does not name a translatable property SHALL save that property's
locale map unchanged.

#### Scenario: PATCHing a lead's stage keeps its title

- **GIVEN** a lead whose translatable `title` is stored as `{"nl": "[Demo] Webshop"}`
- **WHEN** the client PATCHes only `stage`
- **THEN** the title is still `{"nl": "[Demo] Webshop"}`, not the string `'{"nl":"[Demo] Webshop"}'`
- @e2e exclude {covered by SchemaTypeConverterTest::testAnUntouchedTranslatablePropertyKeepsItsLocaleMap}
