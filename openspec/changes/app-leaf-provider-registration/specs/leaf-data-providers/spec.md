## ADDED Requirements

### Requirement: An app-local storage strategy sources data from the sibling app

The integration-provider storage-strategy enum SHALL gain the value app-local,
meaning the leaf's data lives in the contributing app's own store and OpenRegister
persists none of it.

A provider declaring app-local MUST serve its read and optional write through its
own methods, which run in the contributing app's dependency-injection context
because the listener constructed the provider there. OpenRegister SHALL route the
call to the provider and MUST NOT store the returned data.

#### Scenario: An app-local list reads from the sibling app store

- **GIVEN** an app-local provider registered by a sibling app
- **WHEN** OpenRegister lists linked things for an object
- **THEN** the provider returns the items from the sibling app's own store and OpenRegister persists none of them

#### Scenario: Existing strategies are unchanged

- **GIVEN** a provider declaring magic-column, link-table, external, or query-time
- **WHEN** the registry routes a call
- **THEN** its behaviour is unchanged by the addition of app-local

### Requirement: A data provider lists items for an object

A data-provider leaf MUST implement a list method that returns the items the app
holds for a given register, schema, and object id, in the flat-list or the items,
total, and nextCursor envelope shape the integration-provider contract already
accepts.

The list method SHALL honour the common limit, page, and search filters when the
provider supports them and MUST ignore unknown filters rather than reject them.

#### Scenario: Notes are listed for an object

- **GIVEN** an app-local notes provider and an object that has notes in the app
- **WHEN** OpenRegister requests the list for that object
- **THEN** the object's notes are returned in the accepted list shape

#### Scenario: An object with no items returns an empty list

- **GIVEN** an app-local notes provider and an object with no notes
- **WHEN** OpenRegister requests the list for that object
- **THEN** an empty list is returned rather than an error

### Requirement: A data provider optionally supports adding a note

A data-provider leaf that supports writes SHALL implement a create method that
adds an item, such as a note, against a register, schema, and object id,
persisting it in the app's own store. Implementing create is optional for a
read-only leaf.

A read-only data-provider leaf SHALL let create throw a not-implemented error,
exactly as query-time providers do today, and the read path MUST remain usable
when create is not implemented.

#### Scenario: Adding a note persists it in the sibling app

- **GIVEN** an app-local notes provider that implements create
- **WHEN** OpenRegister adds a note against an object
- **THEN** the note is persisted in the app's own store and appears in a subsequent list

#### Scenario: A read-only data leaf refuses writes cleanly

- **GIVEN** an app-local provider that does not implement create
- **WHEN** OpenRegister attempts to add a note
- **THEN** a not-implemented error is surfaced and the list path still works

### Requirement: A data provider never invokes a business action

A data-provider leaf SHALL expose only read and linked-item write against an
object and MUST NOT be used to invoke a business action or command in the
contributing app.

Cross-app commands SHALL remain typed event contracts per ADR-041; the data
provider contract carries no verb and MUST NOT be extended into one.

#### Scenario: The provider contract exposes no command path

- **GIVEN** a data-provider leaf
- **WHEN** its contract is inspected
- **THEN** it offers list and linked-item write only, with no method that triggers a business action in the sibling app

@e2e exclude data-provider routing is backend-only — covered by PHPUnit
