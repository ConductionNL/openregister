## MODIFIED Requirements

### Requirement: Notes on Objects via ICommentsManager

The system SHALL provide a `NoteService` that wraps Nextcloud's `OCP\Comments\ICommentsManager` for creating, listing, and deleting notes (comments) on OpenRegister objects. Notes MUST be stored using `objectType: "openregister"` and `objectId: {uuid}`. The service MUST resolve actor display names via `OCP\IUserManager` and indicate whether the current user authored each note. When a note is created on an object, the note's ID MUST also be added to the object's `_notes` metadata column for reverse lookup consistency. When a note is deleted, its ID MUST be removed from `_notes`.

#### Scenario: Create a note on an object
- **GIVEN** an authenticated user `behandelaar-1` and an OpenRegister object with UUID `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/notes` with body `{"message": "Applicant called, will send documents tomorrow"}`
- **THEN** a comment MUST be created via `ICommentsManager::create()` with `actorType: "users"`, `actorId: "behandelaar-1"`, `objectType: "openregister"`, `objectId: "abc-123"`
- **AND** the response MUST return HTTP 201 with the note as JSON including `id`, `message`, `actorId`, `actorDisplayName`, `createdAt`, and `isCurrentUser: true`
- **AND** the note's `id` MUST be added to the object's `_notes` metadata column

#### Scenario: List notes with pagination
- **GIVEN** 15 notes exist on object `abc-123`
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/notes?limit=10&offset=0`
- **THEN** the response MUST return a JSON object with `results` (array of 10 note objects) and `total` (10, the count of returned results)
- **AND** each note MUST include: `id`, `message`, `actorType`, `actorId`, `actorDisplayName`, `createdAt`, `isCurrentUser`
- **AND** notes MUST be ordered newest-first (as returned by `ICommentsManager::getForObject()`)

#### Scenario: Delete a note
- **GIVEN** a note with ID 42 exists on object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/abc-123/notes/42`
- **THEN** the note MUST be removed via `ICommentsManager::delete()`
- **AND** the response MUST return HTTP 200 with `{"success": true}`
- **AND** the note's `id` MUST be removed from the object's `_notes` metadata column

#### Scenario: Create note on non-existent object
- **GIVEN** no object exists with the specified register/schema/id
- **WHEN** a POST request is sent to create a note
- **THEN** the API MUST return HTTP 404 with `{"error": "Object not found"}`

#### Scenario: Create note with empty message
- **GIVEN** an authenticated user and a valid object
- **WHEN** a POST request is sent with `{"message": ""}`
- **THEN** the API MUST return HTTP 400 with `{"error": "Note message is required"}`
