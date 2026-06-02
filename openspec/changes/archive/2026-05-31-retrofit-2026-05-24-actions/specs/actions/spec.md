---
retrofit_extensions:
  - REQ-006
  - REQ-007
  - REQ-008
  - REQ-009
  - REQ-010
---
# Actions Specification — Delta (retrofit-2026-05-24-actions)

This delta appends five REQs to the `actions` capability covering behaviors that the first retrofit pass (`retrofit-2026-05-01-actions`) deferred or left uncovered. The archive step will merge these into the main spec under the same `## Requirements` section.

## Requirements

### REQ-006: The system SHALL restrict Actions mutation endpoints to administrator group members

`ActionsController::requireAdmin()` is invoked from every Actions write surface (`create`, `update`, `patch` (via `update`), `destroy`, `test`, `migrateFromHooks`). It checks the active `IUserSession` user and returns a `JSONResponse` denial:

- **No active user** → HTTP 401 with `{ "error": "Authentication required" }`
- **User not in admin group** (per `IGroupManager::isAdmin($uid)`) → HTTP 403 with `{ "error": "Forbidden: Actions management is admin-only" }`
- **Admin user** → returns `null`, allowing the calling controller method to continue.

The list (`index`), show (`show`), and `logs` endpoints carry `#[NoAdminRequired]` and intentionally do NOT call `requireAdmin()` — read access to action definitions and their execution logs is available to any authenticated user.

The admin check is enforced **in the controller body**, not via a route-level `@AuthorizedAdminSetting` or middleware. The mutation methods carry no `#[NoAdminRequired]` attribute, so Nextcloud's framework also denies non-admin access — `requireAdmin()` is intentional defence-in-depth so a future refactor that silently re-adds `#[NoAdminRequired]` does not open the surface.

#### Scenario: Unauthenticated mutation attempt

- **GIVEN** an unauthenticated client
- **WHEN** they POST to `/api/actions`
- **THEN** the framework rejects the request before reaching the controller (no `#[NoAdminRequired]`) and returns 401 / redirect to login

#### Scenario: Authenticated non-admin mutation attempt

- **GIVEN** an authenticated user who is not in the admin group
- **WHEN** they POST to `/api/actions`
- **THEN** the framework rejects with 403; if reached, `requireAdmin()` would also return HTTP 403 with `{ "error": "Forbidden: Actions management is admin-only" }`

#### Scenario: Admin reads action list

- **GIVEN** an authenticated non-admin user
- **WHEN** they GET `/api/actions`
- **THEN** the request succeeds (200) because `index` carries `#[NoAdminRequired]` and never calls `requireAdmin()`

### REQ-007: The system SHALL provide a dry-run test endpoint that reports event/schema/register/filter match without executing the workflow

`ActionsController::test()` (POST `/api/actions/{id}/test`) is admin-only (REQ-006). It accepts a JSON body with at minimum `eventType`, optional `schemaUuid`, `registerUuid`, and arbitrary payload fields used by `filterConditions`. It delegates to `ActionService::testAction()` which:

1. Loads the action via `ActionMapper::find($id)`.
2. Evaluates four independent match predicates on the supplied sample payload:
   - `eventMatch` = `$action->matchesEvent($eventType)`
   - `schemaMatch` = `$action->matchesSchema($schemaUuid)`
   - `registerMatch` = `$action->matchesRegister($registerUuid)`
   - `filterMatch` — for each entry in the action's `filterConditions` array, the value at the dot-notation key in `samplePayload` (via `getNestedValue`) is compared against the expected value. Array-typed `expected` is treated as "one of"; scalar `expected` is treated as strict equality.
3. Computes overall `matched = eventMatch && schemaMatch && registerMatch && filterMatch`.
4. Returns an array (NOT a `JSONResponse` — the controller wraps it) with: `matched`, `action` (serialised), the four match booleans, `filterReasons` (human-readable strings for any failing filter conditions), and `builtPayload` (the original sample payload when matched, `null` otherwise).

The dry-run **does not** invoke the workflow engine, **does not** create an `ActionLog`, and **does not** update statistics. Failures return: 404 if the action does not exist; 500 with the exception message on any other error.

#### Scenario: Successful dry-run on a matching event

- **GIVEN** an active action scoped to schema `schema-abc` with `eventType='ObjectCreatedEvent'` and no filter conditions
- **WHEN** an admin POSTs to `/api/actions/7/test` with body `{ "eventType": "ObjectCreatedEvent", "schemaUuid": "schema-abc", "registerUuid": null }`
- **THEN** the response is HTTP 200 with `{ "matched": true, "eventMatch": true, "schemaMatch": true, "registerMatch": true, "filterMatch": true, "filterReasons": [], "builtPayload": <the sample> }`
- **AND** no `ActionLog` row is created and the workflow engine is never called

#### Scenario: Filter condition mismatch surfaces reason

- **GIVEN** an action with `filterConditions: { "object.status": "published" }`
- **WHEN** an admin POSTs to `/api/actions/{id}/test` with body where `object.status = "draft"`
- **THEN** the response contains `filterMatch: false`, `matched: false`, and `filterReasons: ["filter_condition mismatch: object.status expected 'published', got 'draft'"]`
- **AND** `builtPayload: null`

#### Scenario: Array-typed expected value

- **GIVEN** an action with `filterConditions: { "object.priority": ["high", "critical"] }`
- **WHEN** an admin POSTs `/api/actions/{id}/test` with `object.priority = "normal"`
- **THEN** `filterMatch` is `false` and `filterReasons` contains `"filter_condition mismatch: object.priority expected one of [high, critical], got 'normal'"`

### REQ-008: The system SHALL migrate inline schema hooks to Action entities idempotently

`ActionsController::migrateFromHooks($schemaId)` (POST `/api/actions/migrate-hooks/{schemaId}`) is admin-only (REQ-006). It delegates to `ActionService::migrateFromHooks($schemaId)` which:

1. Loads the schema via `SchemaMapper::find($schemaId)`. Returns 404 on `DoesNotExistException`.
2. Reads `$schema->getHooks()` (a `?array`). If null or empty, returns `{ "created": [], "skipped": [], "errors": [] }`.
3. For each hook entry, resolves:
   - `name` from `hook['id']` or falls back to `"Hook {$index} for {$schemaName}"`
   - `eventKey` from `hook['event']` (defaulting to `'creating'`)
   - `eventType` from `self::HOOK_EVENT_MAP[$eventKey]` (a class-constant mapping legacy hook event keys like `creating` → `ObjectCreatingEvent`); falls back to the raw `eventKey` when not mapped
4. Performs a **duplicate check**: lists all `status='active'` actions and skips when any existing action has the same `name`, matches the resolved `eventType` (`matchesEvent`), and contains the target schema's UUID in its `schemasArray`.
5. On non-duplicate: calls `createAction()` (REQ-001) with the hook's fields mapped to the Action contract — including `status='active'` (NOT the default `'draft'`).
6. Records each outcome in the report: `created[]` (the serialised new Action), `skipped[]` (`{ name, reason: 'duplicate' }`), or `errors[]` (`{ hook: <original>, error: <exception message> }`).

Returns the report as the response body (HTTP 200). Per-hook exceptions are caught and logged in `errors[]`; the loop continues. Other exceptions (e.g. schema load failure) bubble to the controller which returns 404 or 500.

**Note**: The migration is intended to be **idempotent** — re-running it yields the same created/skipped report given the same schema state. Idempotency relies on the duplicate-detection triple (name + eventType match + schema UUID in scope). Renaming a hook after migration would create a duplicate — surfaced here, not enforced.

**Note**: The duplicate-check `findAll(filters: ['status' => 'active'])` returns every active action across all schemas, not just the migrated schema. For large action sets the check is O(N) per hook; acceptable for the one-shot migration use case, would be a problem if called in a loop.

#### Scenario: First migration creates actions

- **GIVEN** schema `42` with two inline hooks `{ id: "notify", event: "creating", engine: "n8n", workflowId: "wf-1" }` and `{ id: "audit", event: "deleted", engine: "n8n", workflowId: "wf-2" }`
- **WHEN** an admin POSTs to `/api/actions/migrate-hooks/42`
- **THEN** the response is HTTP 200 with `{ "created": [<notify-action>, <audit-action>], "skipped": [], "errors": [] }`
- **AND** both new actions have `status='active'` and `schemas: ["<schema-42-uuid>"]`

#### Scenario: Re-running the migration is idempotent

- **GIVEN** schema `42` whose hooks were already migrated by a previous run
- **WHEN** an admin POSTs `/api/actions/migrate-hooks/42` a second time
- **THEN** the response is `{ "created": [], "skipped": [{ name: "notify", reason: "duplicate" }, { name: "audit", reason: "duplicate" }], "errors": [] }`

#### Scenario: Unknown schema

- **GIVEN** schema id `99999` does not exist
- **WHEN** an admin POSTs `/api/actions/migrate-hooks/99999`
- **THEN** the response is HTTP 404 with `{ "error": "Schema not found" }`

### REQ-009: The system SHALL expose paginated execution logs and per-action statistics

`ActionsController::logs($id)` (GET `/api/actions/{id}/logs`) carries `#[NoAdminRequired]` — log retrieval is available to any authenticated user. It accepts optional query parameters `_limit` (default 25) and `_offset` (default 0).

It calls `ActionLogMapper::findByActionId($id, $limit, $offset)` for the page of log rows and `ActionLogMapper::getStatsByActionId($id)` for aggregate counts, then returns:

```json
{
  "results":   [<serialized ActionLog>, ...],
  "total":     <stats.total>,
  "statistics": { "total": ..., ... }
}
```

The `statistics` object is the full mapper-supplied aggregate (count by status, etc. — exact shape is defined by `ActionLogMapper::getStatsByActionId()`, not by the controller). `total` is duplicated at the top level for client convenience.

Exceptions return HTTP 500 with `{ "error": "Failed to retrieve action logs" }`. The endpoint does **not** validate that the action ID exists before querying logs — a non-existent action ID returns an empty `results` and a zero `statistics`, not 404. Surfaced as a minor observable quirk, not fixed.

#### Scenario: Paginated log retrieval

- **GIVEN** action `7` has 60 execution log rows
- **WHEN** an authenticated user GETs `/api/actions/7/logs?_limit=10&_offset=20`
- **THEN** the response contains 10 log rows, `total=60`, and `statistics.total=60`

#### Scenario: Defaults when limit/offset are omitted

- **GIVEN** a GET to `/api/actions/7/logs` with no `_limit` or `_offset`
- **WHEN** the controller handles the request
- **THEN** it calls `findByActionId(actionId: 7, limit: 25, offset: 0)`

#### Scenario: Action ID with no logs

- **GIVEN** action id `99999` has no log rows (whether the action exists or not)
- **WHEN** a GET to `/api/actions/99999/logs` is made
- **THEN** the response is HTTP 200 with `{ "results": [], "total": 0, "statistics": { ... } }` — NOT 404

### REQ-010: The system SHALL support pagination, field-based filtering, and substring search on the list endpoint

`ActionsController::index()` (GET `/api/actions`) accepts these query parameters:

- **Pagination**: `_limit` (int, no default), `_offset` (int, no default), `_page` (int, 1-based). When `_page` and `_limit` are both set, `_offset` is computed as `($_page - 1) * $_limit` — taking precedence over any explicit `_offset`.
- **Filters**: only the whitelisted field names `status`, `event_type`, `engine`, `enabled`, `mode` are forwarded to `ActionMapper::findAll(filters: …)`. Any other query parameter is ignored at the filter level (still reaches `$params` but is dropped).
- **Search**: `_search` is **NOT** delegated to the mapper — it is applied in PHP after the page is loaded. A non-empty `_search` value (case-insensitive) is matched against each action's `name` and `slug` via `str_contains`. Matches across both fields are unioned.

The controller computes `total` by re-querying `findAll(filters: $filters)` (no limit/offset) and applying the same search filter in PHP, then `count()`-ing. This means a single list call issues **two** mapper queries — one for the page, one for the unfiltered count. Surfaced as observable behavior, not optimised.

Each returned action is `jsonSerialize()`-ed. The response envelope is `{ "results": [...], "total": <int> }` (HTTP 200) or `{ "error": "Failed to list actions" }` (HTTP 500 on `\Exception`).

**Note**: `_search` being applied in PHP after pagination means a `_limit=10` request that page-loads 10 actions and filters out 7 of them via search will return only 3 results despite there being more matches on later pages. The mapper does not yet support full-text search on `name`/`slug`. Surfaced here, not fixed.

#### Scenario: Page-based pagination

- **GIVEN** 100 actions exist
- **WHEN** an authenticated user GETs `/api/actions?_page=3&_limit=10`
- **THEN** the controller calls `findAll(limit: 10, offset: 20, filters: [])`
- **AND** returns `{ "results": [<10 actions>], "total": 100 }`

#### Scenario: Whitelisted filters reach the mapper

- **GIVEN** a GET to `/api/actions?status=active&event_type=ObjectCreatedEvent&unknown_field=foo`
- **WHEN** the controller builds the filter map
- **THEN** it calls `findAll(filters: { "status": "active", "event_type": "ObjectCreatedEvent" })` — `unknown_field` is dropped

#### Scenario: Substring search on name and slug

- **GIVEN** four actions: `name="Notify CRM"`, `name="Notify Slack"`, `name="Audit Log"`, `name="Cleanup", slug="notify-cleanup"`
- **WHEN** a GET to `/api/actions?_search=notify` is made
- **THEN** the response includes three actions: `Notify CRM`, `Notify Slack`, and `Cleanup` (matched on slug)

#### Scenario: Page-then-filter ordering quirk

- **GIVEN** 50 actions named `Cleanup-1`..`Cleanup-50` and a search for `cleanup-49`
- **WHEN** a GET to `/api/actions?_search=cleanup-49&_limit=10&_page=1` is made
- **THEN** the response is `{ "results": [], "total": 1 }` — the mapper returns the first 10 actions (`Cleanup-1`..`Cleanup-10`), none match the search, but the unfiltered `total` count correctly reports 1 match

## Acceptance Criteria

- [ ] Mutation endpoints reject non-admin authenticated users with HTTP 403 (REQ-006)
- [ ] `POST /api/actions/{id}/test` returns match booleans, filter reasons, and built payload without invoking the workflow engine (REQ-007)
- [ ] `POST /api/actions/migrate-hooks/{schemaId}` is idempotent — re-running yields all `skipped[]` entries (REQ-008)
- [ ] `GET /api/actions/{id}/logs` returns paginated logs plus aggregate `statistics` (REQ-009)
- [ ] `GET /api/actions?_page=N&_limit=M` computes offset as `(N-1)*M` (REQ-010)
- [ ] `GET /api/actions?_search=…` matches against `name` and `slug` case-insensitively, applied in PHP after the mapper page (REQ-010)

## Notes

- **Two-query total**: `index()` issues two mapper queries to compute `total` (one for the page, one for the unfiltered count). Acceptable for current scale; would be replaced by a `countAll(filters:)` mapper method in future.
- **Post-page search filter**: `_search` is applied in PHP after pagination — results may be sparser than `_limit` suggests on early pages of large result sets.
- **Defence-in-depth admin check**: `requireAdmin()` duplicates the framework-level admin gate. The duplication is intentional — see REQ-006.
- **Logs endpoint does not validate action existence**: a non-existent action ID returns an empty result set with `total=0`, not 404. REQ-009 notes.
- **Hook duplicate-check scope**: REQ-008 lists ALL active actions to detect duplicates, not just actions targeting the migrated schema. O(N) per hook; acceptable for one-shot migration.
