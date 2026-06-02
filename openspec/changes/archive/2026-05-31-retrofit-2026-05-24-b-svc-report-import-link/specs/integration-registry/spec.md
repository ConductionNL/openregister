# Integration Registry (retrofit delta)

These requirements document the **legacy pre-ADR-019 flat link services**
(`DeckCardService`, `NoteService`, `TaskService`) that still exist alongside the
new `IntegrationProvider` registry introduced by the `pluggable-integration-registry`
change. They are recorded here so the provider migration preserves the
non-provider behavior these flat services carry (board search, comment-backed
notes with an author-edit guard, CalDAV VTODO linking). The registry contract
itself (`IntegrationProvider`, `IntegrationRegistry`, the 8 builtin providers,
parity gate) is owned by `pluggable-integration-registry` and is NOT re-specified
here.

## ADDED Requirements

### Requirement: Legacy Deck and Note link services MUST preserve their non-provider behavior

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

### Requirement: The legacy CalDAV task link service MUST preserve VTODO linking semantics

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
