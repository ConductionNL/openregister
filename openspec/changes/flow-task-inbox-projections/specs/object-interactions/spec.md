## MODIFIED Requirements

### Requirement: Tasks on Objects via CalDAV VTODO

The system SHALL provide a `TaskService` that creates, reads, updates, and deletes CalDAV VTODO items linked to OpenRegister objects. Each VTODO MUST include `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, and `X-OPENREGISTER-OBJECT` custom properties, plus an RFC 9253 LINK property pointing back to the object API endpoint. Tasks MUST be stored in a VTODO-supporting calendar via `OCA\DAV\CalDAV\CalDavBackend`.

VTODOs SHALL fall into two classes, distinguished by whether they carry an
ENGINE TASK identity property:

- **Standalone VTODOs** carry no engine task identity. They are user-created
  tasks on an object, the VTODO is their store, and every behaviour in this
  requirement applies to them unchanged.
- **Projected VTODOs** carry an engine task identity. For these the VTODO is
  NOT the store: the engine task record is, and `TaskService` acts as the
  PROJECTION WRITER. Their lifecycle, assignee and outcome are governed by
  `flow-task-projections`, not by this capability.

`TaskService` SHALL refuse to create a projected VTODO through the
object sub-resource endpoints. A projected VTODO is created only by the
projection, so that a VTODO carrying an engine task identity always
corresponds to a task the engine authorized into existence.

Deleting a projected VTODO through this capability's endpoints SHALL NOT
delete the engine task. The projection SHALL be restored on the next
reconciliation, because a task is not cancelled by removing the reminder of
it.

The assignee of a task SHALL NOT be encoded in, or recovered from, the
VTODO `DESCRIPTION`. Any assignee carried on a VTODO SHALL be a
machine-readable identity resolvable to a Nextcloud account.

#### Scenario: Create a task linked to an object
- **GIVEN** an OpenRegister object with UUID `abc-123` in register 5, schema 12
- **WHEN** a POST request is sent to `/api/objects/5/12/abc-123/tasks` with body `{"summary": "Review documents", "due": "2026-03-01T17:00:00Z", "priority": 1}`
- **THEN** a VTODO MUST be created in a VTODO-supporting calendar with:
  - `X-OPENREGISTER-REGISTER:5`
  - `X-OPENREGISTER-SCHEMA:12`
  - `X-OPENREGISTER-OBJECT:abc-123`
  - `LINK;LINKREL="related";VALUE=URI:/apps/openregister/api/objects/5/12/abc-123`
  - `STATUS:NEEDS-ACTION`, `PRIORITY:1`, `SUMMARY:Review documents`, `DUE:20260301T170000Z`
- **AND** the VTODO MUST carry no engine task identity property
- **AND** the response MUST return HTTP 201 with the task as JSON including `id`, `uid`, `calendarId`, `summary`, `description`, `status`, `priority`, `due`, `completed`, `created`, `objectUuid`, `registerId`, `schemaId`
- @e2e exclude covered by TaskService creation unit tests

#### Scenario: List tasks for an object
- **GIVEN** 3 VTODOs exist with `X-OPENREGISTER-OBJECT:abc-123`
- **WHEN** a GET request is sent to `/api/objects/5/12/abc-123/tasks`
- **THEN** the response MUST return `{"results": [...], "total": 3}` with all 3 tasks
- **AND** each task MUST include: `id` (URI), `uid`, `calendarId`, `summary`, `description`, `status`, `priority`, `due`, `completed`, `created`, `objectUuid`, `registerId`, `schemaId`
- @e2e exclude covered by TaskService listing unit tests

#### Scenario: Update task status to completed
- **GIVEN** a standalone VTODO linked to object `abc-123` with status `NEEDS-ACTION`
- **WHEN** a PUT request is sent with `{"status": "completed"}`
- **THEN** the VTODO STATUS MUST be set to `COMPLETED`
- **AND** the `COMPLETED` timestamp MUST be set to the current UTC time
- **AND** the `X-OPENREGISTER-*` properties MUST remain unchanged
- **AND** the response MUST return the updated task as JSON
- @e2e exclude covered by TaskService update unit tests

#### Scenario: Delete a task
- **GIVEN** a standalone VTODO linked to object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/5/12/abc-123/tasks/{taskId}`
- **THEN** the VTODO MUST be removed from the calendar via `CalDavBackend::deleteCalendarObject()`
- **AND** the response MUST return `{"success": true}`
- @e2e exclude covered by TaskService deletion unit tests

#### Scenario: Task summary is required
- **GIVEN** a POST request to create a task with empty summary
- **WHEN** the controller validates the request
- **THEN** the API MUST return HTTP 400 with `{"error": "Task summary is required"}`
- @e2e exclude covered by TasksController validation unit tests

#### Scenario: An engine task cannot be forged through this endpoint
- **GIVEN** a POST request to `/api/objects/5/12/abc-123/tasks` whose payload attempts to set an engine task identity
- **WHEN** the request is processed
- **THEN** it MUST be refused
- **AND** no VTODO carrying an engine task identity MUST be created
- @e2e exclude covered by TasksController validation unit tests

#### Scenario: Deleting a projected VTODO does not cancel the task
- **GIVEN** a projected VTODO for an engine task in a non-terminal state
- **WHEN** it is deleted through this capability's endpoint
- **THEN** the engine task MUST remain in its current state
- **AND** the projection MUST be restored on the next reconciliation
- @e2e exclude covered by projection-reconciliation unit tests

### Requirement: Task Status Mapping

The system SHALL map CalDAV VTODO STATUS values to lowercase JSON strings for consistent API responses. The mapping MUST be bidirectional: incoming status values from the API MUST be converted to uppercase for CalDAV storage.

For a PROJECTED VTODO the wire mapping above still applies, but the VTODO
status is not the task's state. The system SHALL publish one mapping between
the engine task's lifecycle states and the four VTODO status values, and
SHALL apply that mapping in both directions: rendering the projection, and
interpreting a write-back.

The engine lifecycle has more states than the VTODO vocabulary can express,
so the mapping SHALL be lossy in the render direction and SHALL NOT be lossy
in the interpret direction: a VTODO status SHALL map to a REQUESTED
TRANSITION rather than to a state, and a status that names no legal
transition from the task's current state SHALL be refused rather than
applied.

#### Scenario: Status normalization on read
- **GIVEN** a VTODO with `STATUS:NEEDS-ACTION`
- **WHEN** the task is returned via the API
- **THEN** the `status` field MUST be `"needs-action"`
- @e2e exclude covered by status-mapping unit tests

#### Scenario: Status normalization on write
- **GIVEN** an API request with `{"status": "in-process"}`
- **WHEN** the task is updated
- **THEN** the VTODO STATUS MUST be set to `IN-PROCESS`
- @e2e exclude covered by status-mapping unit tests

#### Scenario: Complete status mapping table
- **GIVEN** the following CalDAV STATUS values
- **THEN** the mapping MUST be:
  - `NEEDS-ACTION` to/from `"needs-action"`
  - `IN-PROCESS` to/from `"in-process"`
  - `COMPLETED` to/from `"completed"`
  - `CANCELLED` to/from `"cancelled"`
- @e2e exclude covered by status-mapping unit tests

#### Scenario: A VTODO status maps to a transition, not to a state
- **GIVEN** a projected VTODO whose engine task is in a state from which the requested transition is not legal
- **WHEN** its status is changed in a calendar client
- **THEN** the change MUST be refused
- **AND** the engine task's state MUST NOT be set directly from the VTODO status
- @e2e exclude covered by write-back gate unit tests

### Requirement: Calendar Selection for Tasks

The system SHALL determine which CalDAV calendar to use by finding a calendar that supports VTODO components, checking the `supported-calendar-component-set` property on each calendar and handling object, string, and iterable component sets.

The OWNER of the calendar SHALL depend on the class of task:

- For a **standalone** task created through the object sub-resource
  endpoints, the calendar SHALL be the SESSION USER's first VTODO-supporting
  calendar.
- For a **projected** task, the calendar SHALL be the ASSIGNEE's first
  VTODO-supporting calendar. A projection SHALL NOT be written into the
  calendar of whoever happened to trigger the transition — the assignee owes
  the work, and it is their calendar the reminder belongs in.

A task with no resolved individual assignee SHALL NOT be projected into any
calendar.

Absence of a VTODO-supporting calendar SHALL be an error for a standalone
task and SHALL NOT be an error for a projection: the projection SHALL be
skipped and logged, and the task SHALL remain fully usable through every
other surface.

#### Scenario: Use first VTODO-supporting calendar
- **GIVEN** the user has calendars `personal` (VEVENT+VTODO) and `birthdays` (VEVENT only)
- **WHEN** a standalone task is created or listed
- **THEN** the service MUST use the `personal` calendar
- @e2e exclude covered by calendar-selection unit tests

#### Scenario: No VTODO-supporting calendar available
- **GIVEN** the user has no calendars that support VTODO
- **WHEN** a standalone task operation is attempted
- **THEN** the service MUST throw an Exception with message `"No VTODO-supporting calendar found for user {uid}"`
- **AND** the controller MUST return HTTP 500
- @e2e exclude covered by calendar-selection unit tests

#### Scenario: No user logged in
- **GIVEN** no user session is active
- **WHEN** a standalone task operation is attempted
- **THEN** the service MUST throw an Exception with message `"No user logged in"`
- @e2e exclude covered by calendar-selection unit tests

#### Scenario: A projection targets the assignee's calendar
- **GIVEN** an engine task assigned to a user, transitioned by a different user
- **WHEN** the projection runs
- **THEN** the VTODO MUST be written to the ASSIGNEE's calendar
- **AND** the transitioning user's calendar MUST NOT receive it
- @e2e exclude covered by projection unit tests

#### Scenario: A missing calendar skips the projection, not the task
- **GIVEN** an assignee with no VTODO-supporting calendar
- **WHEN** a task is assigned to them
- **THEN** the assignment MUST succeed
- **AND** the skipped projection MUST be logged naming the task
- @e2e exclude covered by projection failure-isolation unit tests

### Requirement: Task Compatibility with Nextcloud Tasks App

Tasks created through OpenRegister MUST be fully compatible with Nextcloud's Tasks app. The `X-OPENREGISTER-*` custom properties MUST NOT break standard CalDAV clients, which ignore unknown X- properties per RFC 5545. Users MUST be able to view OpenRegister-linked tasks in the Nextcloud Tasks app.

For a **standalone** VTODO, editing it in the Tasks app SHALL change the
task, because the VTODO is the store.

For a **projected** VTODO, editing it in the Tasks app SHALL be treated as a
REQUEST against the engine task. Completing it SHALL request the
corresponding lifecycle verb; any edit that names no lifecycle verb SHALL
leave the engine task unchanged and SHALL be overwritten by the next
projection render. An edit that is refused SHALL NOT be left standing in the
calendar.

A projected VTODO SHALL carry a `URL` property deep-linking to the engine
task's own form, so that a person who opens it in a task client can reach
the surface that can actually answer it.

#### Scenario: Task visible in Nextcloud Tasks app
- **GIVEN** a task created via OpenRegister's API on object `abc-123`
- **WHEN** the user opens the Nextcloud Tasks app
- **THEN** the task MUST appear in the user's calendar with its summary, due date, priority, and status
- @e2e exclude covered by CalDAV round-trip unit tests

#### Scenario: Task edited in Nextcloud Tasks app
- **GIVEN** a STANDALONE task linked to object `abc-123` is edited in the Nextcloud Tasks app (e.g., status changed to completed)
- **WHEN** the task is queried via OpenRegister's API
- **THEN** the updated status MUST be reflected in the API response
- **AND** the `X-OPENREGISTER-*` linking properties MUST remain intact
- @e2e exclude covered by CalDAV round-trip unit tests

#### Scenario: X-properties ignored by third-party CalDAV clients
- **GIVEN** a third-party CalDAV client syncs the user's calendar
- **WHEN** it encounters `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, `X-OPENREGISTER-OBJECT`
- **THEN** the client MUST ignore these properties per RFC 5545 section 3.8.8.2 (non-standard properties)
- @e2e exclude covered by CalDAV round-trip unit tests

#### Scenario: Projected task edited in Nextcloud Tasks app
- **GIVEN** a projected VTODO for an engine task assigned to the acting user
- **WHEN** the user marks it completed in the Tasks app
- **THEN** the engine task MUST be completed through its authorized lifecycle verb
- **AND** the projection MUST be re-rendered to match the resulting state
- @e2e completing the projected VTODO completes the engine task

#### Scenario: A projected task links to its form
- **GIVEN** a projected VTODO opened in a task client
- **WHEN** its `URL` property is followed
- **THEN** it MUST resolve to the engine task's form
- @e2e an assigned task appears in the assignee's calendar and links back

### Requirement: User-Wide Task Aggregate Endpoint

The system MUST expose a user-wide task aggregate endpoint that returns the
tasks the current session user owes, independent of any single object.

The aggregate SHALL be answered from the engine task inbox, not by walking
the user's calendars. It MUST resolve the caller from the session
(`IUserSession`), never from a request parameter, so the endpoint cannot be
used to read another user's tasks. Visibility MUST be enforced in the query:
the response MUST contain only tasks the caller may see, and the reported
total MUST NOT reveal the existence of tasks it excludes.

Filtering, sorting, pagination and the total MUST be performed by the query.
Filtering the aggregate in the application layer after retrieval is
forbidden: it silently drops rows outside the retrieved page and reports a
total that does not match the filter.

It MUST accept optional state, derived-overdue and pagination
(`_limit`/`limit` capped at 200, `_offset`/`offset`) filters. `assignee`
SHALL NO LONGER be accepted as a free-text filter over description prose; a
task's assignee is a resolved identity, and the aggregate is already scoped
to the caller. On error it MUST return HTTP 500 with an `error` message.

#### Scenario: List all of the current user's tasks
- **GIVEN** an authenticated user with engine tasks assigned to them and pooled to their groups
- **WHEN** a GET request is sent to `/api/tasks`
- **THEN** the response MUST list those tasks, resolved from `IUserSession::getUser()->getUID()`
- **AND** the response MUST NOT depend on any object register/schema/id
- **AND** no CalDAV calendar MUST be enumerated to produce it
- @e2e the user-wide task aggregate lists engine tasks

#### Scenario: Filter the aggregate by status and assignee
- **GIVEN** a GET request to `/api/tasks?status=active&assignee=jan`
- **WHEN** the controller reads the parameters
- **THEN** the lifecycle state filter MUST be applied by the inbox query
- **AND** the reported total MUST be the total matching the filter, not the number of rows returned
- **AND** `assignee` MUST NOT be honoured as a filter: the aggregate is already scoped to the caller, and a free-text assignee filter over description prose no longer exists
- @e2e exclude covered by TasksController unit tests

#### Scenario: Aggregate pagination caps the limit
- **GIVEN** a GET request to `/api/tasks?_limit=500`
- **WHEN** the controller computes the limit
- **THEN** the effective limit MUST be capped at 200
- @e2e exclude covered by TasksController unit tests

#### Scenario: The aggregate does not leak another user's tasks
- **GIVEN** a GET request to `/api/tasks` carrying a parameter naming another user
- **WHEN** the request is processed
- **THEN** the response MUST contain only the session user's visible tasks
- **AND** the reported total MUST NOT include tasks the caller may not see
- @e2e exclude covered by TasksController authorization unit tests
