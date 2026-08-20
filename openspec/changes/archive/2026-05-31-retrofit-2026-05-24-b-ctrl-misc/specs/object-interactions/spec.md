---
status: draft
---

# Object Interactions

## Purpose

Extend the object-interactions capability with two collaboration-attachment
surfaces the existing spec does not cover: (1) the user-wide task aggregate
endpoint (all of a user's CalDAV VTODOs across every calendar, not scoped to a
single object), and (2) Nextcloud Deck card linkage on objects. The existing
REQs already cover per-object Notes, Tasks, Tags, and the sub-resource pattern;
these added REQs cover the cross-calendar aggregate and the Deck integration.
Reverse-specced from the existing implementation.

## ADDED Requirements

### Requirement: User-Wide Task Aggregate Endpoint

The system MUST expose a user-wide task aggregate endpoint that returns every
CalDAV VTODO belonging to the current session user across all of their
VTODO-supporting calendars, independent of any single object. `TasksController::allUserTasks()`
MUST resolve the calendar set from the session user (`IUserSession`), never from
a request parameter, so the endpoint cannot be used to read another user's tasks.
It MUST accept optional `status`, `assignee`, and pagination (`_limit`/`limit`
capped at 200, `_offset`/`offset`) filters, where `assignee` is a free-text
filter applied within the caller's own task list and is NOT an identity claim.
On error it MUST return HTTP 500 with an `error` message.

#### Scenario: List all of the current user's tasks
- **GIVEN** an authenticated user with VTODOs spread across multiple calendars
- **WHEN** a GET request is sent to `/api/tasks`
- **THEN** `TasksController::allUserTasks()` MUST return the aggregate via `TaskService::getAllUserTasks()` resolved from `IUserSession::getUser()->getUID()`
- **AND** the response MUST NOT depend on any object register/schema/id

#### Scenario: Filter the aggregate by status and assignee
- **GIVEN** a GET request to `/api/tasks?status=needs-action&assignee=jan`
- **WHEN** the controller reads the parameters
- **THEN** `status` and `assignee` MUST be forwarded to `TaskService::getAllUserTasks()`
- **AND** `assignee` MUST be applied as a free-text filter within the caller's own task list, not as an identity claim

#### Scenario: Aggregate pagination caps the limit
- **GIVEN** a GET request to `/api/tasks?_limit=500`
- **WHEN** the controller computes the limit
- **THEN** the effective limit MUST be capped at 200

### Requirement: Deck Card Linkage on Objects

The system MUST provide object-scoped Nextcloud Deck card linkage as a
sub-resource of objects. `DeckController` MUST support listing the Deck cards
linked to an object, creating or linking a card to an object, and a reverse
lookup of every object linked to cards on a given board. When the Nextcloud Deck
app is not installed, every endpoint MUST return HTTP 501 with
`{"error": "Nextcloud Deck app is not installed", "code": "APP_NOT_AVAILABLE"}`.
Object-scoped operations MUST validate the object exists (HTTP 404 otherwise) via
`ObjectService` before delegating to `DeckCardService`.

#### Scenario: List Deck cards for an object
- **GIVEN** the Deck app is installed and object `abc-123` exists
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/deck`
- **THEN** the controller MUST validate the object and return `DeckCardService::getCardsForObject()` results

#### Scenario: Create or link a Deck card to an object
- **GIVEN** the Deck app is installed and object `abc-123` exists
- **WHEN** a POST request supplies card link/create data
- **THEN** `DeckCardService::linkOrCreateCard()` MUST be invoked and the link returned with HTTP 201
- **AND** a duplicate link MUST return HTTP 409, a missing target MUST return HTTP 404

#### Scenario: Reverse lookup objects on a board
- **GIVEN** the Deck app is installed
- **WHEN** a GET request is sent for objects linked to a board id
- **THEN** the response MUST return `{"results": [...], "total": N}` from `DeckCardService::getObjectsForBoard()`

#### Scenario: Deck app not installed
- **GIVEN** the Nextcloud Deck app is not installed
- **WHEN** any `DeckController` endpoint is called
- **THEN** the response MUST be HTTP 501 with `code: "APP_NOT_AVAILABLE"`
