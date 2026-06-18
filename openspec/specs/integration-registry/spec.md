---
status: done
---

# integration-registry Specification

## Purpose
Provides a pluggable integration registry that lets OpenRegister link external and Nextcloud services to objects through a common `IntegrationProvider` contract, auto-registered via DI tags and resolvable through the `IntegrationRegistry` service. Ships the eight built-in NC integrations (files, notes, tasks, calendar, mail, contacts, deck, talk) while preserving their legacy linking behavior, validates schema `linkedTypes` against the registry, routes external providers through OpenConnector, advertises enabled integrations via OCS capabilities, and supplies a CI parity gate, a scaffold script, and an OCC listing command.
## Requirements
### Requirement: Legacy Deck and Note link services MUST preserve their non-provider behavior @e2e exclude backend Deck/Note link services (enrichment, reverse-lookup, cascade cleanup, author guard) via NC app APIs — covered by PHPUnit

The system MUST retain, for the migration to the `deck` and `notes`
`IntegrationProvider`s, the behavior of the legacy flat services. `DeckCardService`
MUST list an object's Deck links enriched best-effort with `dueDate`, `labels`,
and `assignees` resolved from the live Deck card (degrading to `null`/`[]` when
Deck is disabled or the card is deleted), MUST support linking an existing card
or creating a new one, MUST support board-scoped reverse lookup
(`getObjectsForBoard`), and MUST delete all links for an object on cleanup.
`NoteService` MUST back object notes with Nextcloud `ICommentsManager` comments
under objectType `openregister`, and MUST enforce an author-only edit guard on
note updates while allowing cleanup-time bulk deletion.

#### Scenario: Deck link enrichment degrades gracefully when a card is gone
- **GIVEN** an object with a Deck link whose underlying card has been deleted from Deck
- **WHEN** `DeckCardService::getCardsForObject()` runs
- **THEN** the stale link MUST still be returned in `results`
- **AND** its `dueDate` MUST be `null` and `labels`/`assignees` MUST be empty arrays (no exception)

#### Scenario: Reverse lookup of objects linked to a board
- **GIVEN** several objects with Deck links on board id `42`
- **WHEN** `DeckCardService::getObjectsForBoard(42)` is called
- **THEN** the method MUST return the serialized link rows for every object linked to a card on that board

#### Scenario: Object delete cascades Deck link cleanup
- **GIVEN** an object with three Deck links
- **WHEN** the object is deleted and `deleteLinksForObject($uuid)` runs
- **THEN** all three link rows MUST be removed and the deleted count MUST be returned

#### Scenario: A note can only be edited by its author
- **GIVEN** a note created by user `alice` on an object
- **WHEN** user `bob` calls `NoteService::updateNote()` for that note
- **THEN** the service MUST reject the update ("You can only edit your own notes")
- **AND** when `alice` updates it the comment message MUST be saved via `ICommentsManager`

#### Scenario: Object delete cascades note cleanup
- **GIVEN** an object with several notes (comments) under objectType `openregister`
- **WHEN** `NoteService::deleteNotesForObject($uuid)` runs
- **THEN** every comment for that object MUST be deleted via `ICommentsManager::deleteCommentsAtObject`

### Requirement: The legacy CalDAV task link service MUST preserve VTODO linking semantics @e2e exclude backend CalDAV/VTODO task link service (calendar resolution, cross-calendar listing, typed errors) — covered by PHPUnit

`TaskService` MUST link Nextcloud CalDAV VTODO items to OpenRegister objects for
the migration to the `tasks` `IntegrationProvider`. It MUST create VTODOs
carrying `X-OPENREGISTER-REGISTER` / `X-OPENREGISTER-SCHEMA` /
`X-OPENREGISTER-OBJECT` properties plus an RFC 9253 `LINK` property, MUST list an
object's tasks by scanning the user's first VTODO-supporting calendar and
matching on the object UUID, MUST list all of a user's tasks across every
VTODO-supporting calendar with optional status/assignee filtering and due-date
ordering, and MUST support updating and deleting a task by calendar id + URI.

#### Scenario: Creating a task links it back to the object
- **GIVEN** a user with a VTODO-supporting calendar and an object `obj-1` titled `Aanvraag X`
- **WHEN** `TaskService::createTask($registerId, $schemaId, 'obj-1', 'Aanvraag X', $data)` is called
- **THEN** a VTODO MUST be stored carrying `X-OPENREGISTER-OBJECT:obj-1` plus register/schema properties
- **AND** an RFC 9253 `LINK;LINKREL="related"` property MUST reference the object's API path

#### Scenario: Listing tasks for an object filters by the linking property
- **GIVEN** several VTODOs in the calendar, only some carrying `X-OPENREGISTER-OBJECT:obj-1`
- **WHEN** `TaskService::getTasksForObject('obj-1')` is called
- **THEN** only VTODOs whose `objectUuid` equals `obj-1` MUST be returned

#### Scenario: Cross-calendar task listing is filtered and ordered
- **GIVEN** a user with tasks across two VTODO-supporting calendars
- **WHEN** `TaskService::getAllUserTasks(status: 'needs-action', assignee: 'jan')` is called
- **THEN** only `needs-action` tasks whose description carries `Assigned to: jan` MUST be returned
- **AND** the results MUST be ordered by due date (soonest first, undated last) and paginated by limit/offset

#### Scenario: No suitable calendar raises a typed error
- **GIVEN** a user with no VTODO-supporting calendar
- **WHEN** `TaskService::createTask(...)` resolves the target calendar
- **THEN** the service MUST raise `NoVtodoCalendarException` rather than silently failing

### Requirement: The system MUST define an `IntegrationProvider` interface

OpenRegister MUST expose `OCA\OpenRegister\Service\Integration\IntegrationProvider` with the methods `getId(): string`, `getLabel(): string`, `getIcon(): string`, `isEnabled(): bool`, `getStorageStrategy(): string`, `authRequirements(): array`, `linkedColumnName(): ?string`, `query(string $objectUuid): iterable`, `mutate(string $objectUuid, array $payload): void`. The interface MUST be PHP-namespace-stable (changes are breaking).

#### Scenario: Implementing IntegrationProvider declares an id
- GIVEN a class `MyServiceProvider` implementing `IntegrationProvider`
- WHEN the class is loaded
- THEN `MyServiceProvider::getId()` MUST return a non-empty string
- AND the string MUST be unique across all registered providers in this OR install

### Requirement: The system MUST expose an `IntegrationRegistry` service

OpenRegister MUST expose `OCA\OpenRegister\Service\Integration\IntegrationRegistry` as a public DI-resolvable service with: `register(IntegrationProvider $p): void`, `listAll(): iterable<IntegrationProvider>`, `listEnabled(): iterable<IntegrationProvider>`, `listIds(): array<string>`, `getById(string $id): ?IntegrationProvider`, `requireById(string $id): IntegrationProvider`. `requireById()` MUST throw `IntegrationNotFoundException` for unknown ids. Any service implementing `IntegrationProvider` and tagged `IntegrationProvider` in the DI container MUST be auto-registered at boot.

#### Scenario: Auto-register a provider via DI tag
- GIVEN `MyServiceProvider` is declared with `<service>...<tag name="IntegrationProvider"/></service>` in the DI container
- WHEN the registry is resolved for the first time
- THEN `IntegrationRegistry::listAll()` MUST include `MyServiceProvider`
- AND `IntegrationRegistry::getById('my-service')` MUST return that instance

#### Scenario: Filter to enabled providers
- GIVEN two providers `Files` (enabled) and `Talk` (NC Talk app not installed, `isEnabled() === false`)
- WHEN `listEnabled()` is called
- THEN the iterable MUST yield `Files` only
- AND `listAll()` MUST yield both

#### Scenario: requireById throws on unknown id
- GIVEN `MyServiceProvider` is NOT registered
- WHEN `requireById('my-service')` is called
- THEN the registry MUST throw `IntegrationNotFoundException`
- AND the exception MUST expose `getId() === 'my-service'`

### Requirement: The 8 built-in NC types MUST be migrated to providers

OpenRegister MUST ship 8 builtin `IntegrationProvider` implementations under `lib/Service/Integration/Builtin/` for: `files`, `notes`, `tasks`, `calendar`, `mail`, `contacts`, `deck`, `talk`. Each MUST preserve the current `LinkedEntityService::TYPE_COLUMN_MAP` key as its `getId()` for backwards compatibility with existing schemas.

#### Scenario: Existing schema with `linkedTypes: ['files', 'notes']` keeps working
- GIVEN a schema configured with `configuration.linkedTypes = ['files', 'notes']` before this change
- WHEN OR is upgraded to the version shipping the registry
- THEN the schema MUST still load without validation errors
- AND `IntegrationRegistry::getById('files')` MUST return the `FilesProvider`
- AND `IntegrationRegistry::getById('notes')` MUST return the `NotesProvider`

### Requirement: Schema linkedTypes validation MUST consult the registry

`Schema::validateLinkedTypesValue()` MUST call `IntegrationRegistry::listIds()` instead of any hardcoded constant. Validation MUST be permissive on read (unknown ids generate a `LoggerInterface::warning()` but do NOT throw), strict on write (POST/PUT/PATCH adding an unknown id MUST return `400 Bad Request` with a structured error body pointing at the unknown id).

#### Scenario: Read with stale linkedTypes id
- GIVEN a stored schema has `configuration.linkedTypes = ['files', 'historic-removed-type']`
- AND no provider with id `historic-removed-type` is registered
- WHEN the schema is read via `GET /api/schemas/{id}`
- THEN the response MUST include the schema unchanged
- AND a warning MUST be logged: `LinkedTypes references unknown integration 'historic-removed-type'`

#### Scenario: Write with unknown linkedTypes id
- WHEN `PUT /api/schemas/{id}` is called with `configuration.linkedTypes = ['files', 'unknown-id']`
- THEN OR MUST return `400 Bad Request`
- AND the response body MUST include `error.unknownLinkedType: 'unknown-id'`
- AND no other validation MUST run after this failure (short-circuit)

### Requirement: External-strategy providers MUST route through OpenConnector

Providers returning `getStorageStrategy() === 'external'` MUST be dispatched via `OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter`. The router MUST forward `query()` and `mutate()` calls to the OpenConnector source declared by the provider; OR MUST NOT store credentials for external integrations.

#### Scenario: External provider's query goes through OpenConnector
- GIVEN a provider `OpenProjectProvider` with `getStorageStrategy() === 'external'` referencing OpenConnector source `openproject-prod`
- WHEN `OpenProjectProvider::query('object-uuid-123')` is called via the router
- THEN the router MUST dispatch the query to OpenConnector source `openproject-prod`
- AND OR's database MUST NOT receive any persistence write for this query
- AND the router MUST surface the source's auth status (OK / needs-reauth / disconnected) on the response envelope

### Requirement: Registered integrations MUST be advertised via OCS capabilities

The system MUST expose `openregister.integrations` in `GET /ocs/v2.php/cloud/capabilities` as the array of currently `isEnabled()`-true provider ids. Disabled providers (NC app not installed) MUST NOT appear.

#### Scenario: Capability lists enabled integrations
- GIVEN `Files` and `Notes` providers report `isEnabled() === true`, `Talk` reports `false`
- WHEN `GET /ocs/v2.php/cloud/capabilities` is called
- THEN the response body MUST include `openregister.integrations: ['files', 'notes']`
- AND the array MUST NOT include `'talk'`

### Requirement: A CI parity gate MUST enforce backend ↔ frontend pairing

A script `scripts/check-integration-parity.sh` MUST exit non-zero when:
1. Any backend `IntegrationProvider::getId()` lacks a matching frontend `OCA.OpenRegister.integrations.register({id})` call in `nextcloud-vue/src/integrations/`.
2. Any frontend registration lacks a matching backend provider.

The script MUST be invoked by `hydra/scripts/run-hydra-gates.sh` as gate #15 and by the OR repo's CI workflow. Per ADR-019, tab-only or widget-only integrations are CI-blocking.

#### Scenario: Backend without frontend fails the gate
- GIVEN a backend `MyServiceProvider` exists with `getId() === 'my-service'`
- AND `nextcloud-vue/src/integrations/index.js` does NOT call `register({id: 'my-service', ...})`
- WHEN `bash scripts/check-integration-parity.sh` runs
- THEN the script MUST exit with status code 1
- AND the script MUST print: `parity: backend 'my-service' has no frontend registration`

#### Scenario: Frontend without backend fails the gate
- GIVEN no backend provider with `getId() === 'orphan'` is registered
- AND `nextcloud-vue/src/integrations/index.js` calls `register({id: 'orphan', ...})`
- WHEN the script runs
- THEN the script MUST exit with status code 1
- AND the script MUST print: `parity: frontend 'orphan' has no backend provider`

### Requirement: A scaffold script MUST be provided for new integrations

OpenRegister MUST ship `scripts/scaffold-integration.sh <id>` generating: a stub `lib/Service/Integration/Builtin/{Id}Provider.php`, a stub frontend registration in `nextcloud-vue/src/integrations/{id}/index.js`, a unit-test stub at `tests/Unit/Service/Integration/Builtin/{Id}ProviderTest.php`, and a stub spec delta. The script MUST refuse to overwrite existing files; safe to re-run after deletion.

#### Scenario: Scaffold a new integration
- GIVEN no integration with id `wiki` exists
- WHEN `bash scripts/scaffold-integration.sh wiki` is run
- THEN the script MUST create `lib/Service/Integration/Builtin/WikiProvider.php` (PHP stub implementing `IntegrationProvider`)
- AND `nextcloud-vue/src/integrations/wiki/index.js` (FE registration stub)
- AND `tests/Unit/Service/Integration/Builtin/WikiProviderTest.php`
- AND a spec delta stub
- AND running it again MUST fail with "files already exist; delete first or run with --force"

### Requirement: An OCC console command MUST list registered integrations

OpenRegister MUST add `openregister:integrations:list` to `php occ` listing every registered provider with: id, label, enabled-state, storage-strategy, and auth-requirements summary.

#### Scenario: List integrations from the CLI
- GIVEN providers `Files` (enabled, native, no auth), `OpenProject` (enabled, external, OAuth-required), `Talk` (disabled — NC Talk not installed)
- WHEN `php occ openregister:integrations:list` is run
- THEN the output MUST include rows for all 3 providers
- AND the row for `OpenProject` MUST show `external (OpenConnector source)` for storage-strategy
- AND the row for `Talk` MUST show `disabled` for state
- AND no row MUST be missing from the output regardless of enabled state

