---
retrofit: true
---
# Data Integrity Relations Specification

**Status**: done
**Scope**: openregister
**OpenSpec changes**:
- [retrofit-2026-05-24-data-integrity-relations](../../changes/retrofit-2026-05-24-data-integrity-relations/) _(ghost)_

## Purpose

Surface every entity related to an OpenRegister object — notes, tasks, emails, calendar events, contacts, Deck cards — through a single per-object aggregation endpoint and a corresponding set of Vue tabs and Pinia stores. The aggregation is tolerant of optional Nextcloud apps (Mail, Deck, Calendar) being absent and exposes both a typed envelope view and a flat timeline view of the same data.

This spec documents the observed behavior of the unified relations feature as retroactively introduced by the `retrofit-2026-05-24-data-integrity-relations` ghost change. Code already exists — requirements describe what the code does, not what it should do.

**Cross-references**: nextcloud-entity-relations (per-type endpoints for emails, events, contacts, Deck), referential-integrity (cascading deletes between register objects).

## Requirements

### REQ-001: The system SHALL aggregate all relation types for an object behind one validated endpoint

`RelationsController` exposes a single GET endpoint `GET /api/objects/{register}/{schema}/{id}/relations` annotated `@NoAdminRequired @NoCSRFRequired`. The controller is constructed with seven service dependencies (`ObjectService`, `NoteService`, `TaskService`, `EmailService`, `CalendarEventService`, `ContactService`, `DeckCardService`) and reuses `ObjectService` mutator chain (`setSchema → setRegister → setObject → getObject`) inside `validateObject()` to resolve the target `ObjectEntity` from URL slugs. When the object cannot be resolved (either `getObject()` returns `null` or throws `DoesNotExistException`) the endpoint MUST return HTTP 404 with `{"error": "Object not found"}`. Any other `Exception` MUST return HTTP 500 with `{"error": "<exception message>"}`.

#### Scenario: Resolve register/schema/id by slug
- **GIVEN** a request to `GET /api/objects/orders/order/abc-123/relations`
- **WHEN** `RelationsController::index()` calls `validateObject('orders', 'order', 'abc-123')`
- **THEN** the controller MUST invoke `objectService->setSchema('order')`, `setRegister('orders')`, `setObject('abc-123')` in that order
- **AND** return the entity from `objectService->getObject()`
- **AND** read `getUuid()` from that entity to use as `$objectUuid` for downstream aggregation

#### Scenario: Object not found
- **GIVEN** a request with an `{id}` that does not exist in the register/schema
- **WHEN** `validateObject()` returns `null` OR `getObject()` throws `DoesNotExistException`
- **THEN** the response MUST be HTTP 404 with body `{"error": "Object not found"}`

#### Scenario: Unexpected failure
- **GIVEN** an `Exception` (not `DoesNotExistException`) escapes from any downstream service
- **WHEN** `RelationsController::index()` catches it
- **THEN** the response MUST be HTTP 500 with body `{"error": "<message from exception>"}`

### REQ-002: The aggregator SHALL filter by relation type, skip unavailable optional apps, and silently swallow per-type failures

`RelationsController::gatherRelations(string $objectUuid, ?array $typesFilter)` walks six fixed relation types in this order: `notes`, `tasks`, `emails`, `events`, `contacts`, `deck`. Each type is included in the result when `$typesFilter` is `null` OR when its singular key is present in the filter array. The query parameter `?types=foo,bar` is parsed by `array_map('trim', explode(',', $params['types']))` and passed in as `$typesFilter`. For `emails` the aggregator additionally calls `EmailService::isMailAvailable()`, and for `deck` it calls `DeckCardService::isDeckAvailable()` — when those return `false`, the type is omitted entirely (no key in the result). For every type, the call to the underlying service is wrapped in a `try { … } catch (Exception) { /* Silently skip on error */ }`, so a failure on one type MUST NOT block the others. Notes/tasks/events results are wrapped as `{results: [...], total: count(...)}`; emails/contacts/deck pass through whatever the service returns verbatim.

#### Scenario: Unfiltered request returns every available type
- **GIVEN** Mail and Deck apps are both installed
- **AND** all six services return data without error
- **WHEN** `gatherRelations($uuid, null)` runs
- **THEN** the result MUST contain keys `notes`, `tasks`, `emails`, `events`, `contacts`, `deck` in that insertion order
- **AND** `notes`/`tasks`/`events` MUST each be `{results: array, total: int}`

#### Scenario: Type filter narrows the response
- **GIVEN** a request with `?types=emails,contacts`
- **WHEN** `gatherRelations()` runs with `$typesFilter = ['emails', 'contacts']`
- **THEN** the result MUST contain ONLY the `emails` and `contacts` keys
- **AND** notes/tasks/events/deck MUST NOT appear

#### Scenario: Optional app missing
- **GIVEN** the Mail app is not installed (`EmailService::isMailAvailable() === false`)
- **WHEN** `gatherRelations($uuid, null)` runs
- **THEN** the `emails` key MUST NOT appear in the result (omitted, not empty)
- **AND** the other five types MUST still aggregate normally

#### Scenario: Per-type failure does not break aggregation
- **GIVEN** `NoteService::getNotesForObject()` throws an `Exception`
- **WHEN** `gatherRelations()` reaches the notes block
- **THEN** the `notes` key MUST be omitted from the result
- **AND** the remaining types MUST aggregate normally (no rethrow)

### REQ-003: The endpoint SHALL render a flat timeline view on `view=timeline`, and the frontend SHALL normalise both shapes

When the request carries `?view=timeline`, `RelationsController::index()` invokes `buildTimeline($relations)` and returns the resulting flat array instead of the grouped envelope. `buildTimeline()` walks each group, sets `item.type` to the singular form (`rtrim($type, 's')` — note this maps `deck → dec` for the `deck` key), copies the first non-null of (`date`, `linkedAt`, `createdAt`, `dtstart`, `created`) into a temporary `_sortDate`, sorts descending by that key, then unsets it before returning. Groups whose entry is not shaped as `{results: [...]}` (i.e. emails / contacts / deck) are skipped because the function tests `isset($data['results'])`. The `RelationsTab.vue` component calls `axios.get(url, { params: { view: 'timeline' } })` and runs `normaliseResponse()` to accept either a flat array, a `{results: [...]}` envelope, or a per-type `{emails, events, contacts, deck}` envelope, mapping every entry through `normaliseEntry()` into the canonical UI record `{ id, type, title, subtitle, timestamp }` with field aliasing (e.g. title falls back to subject/summary/displayName/name; timestamp falls back to receivedAt/startsAt/createdAt/updatedAt).

#### Scenario: Timeline parameter triggers flat output
- **GIVEN** a request to `GET …/relations?view=timeline`
- **WHEN** `RelationsController::index()` runs
- **THEN** the response body MUST be a JSON array (not an object)
- **AND** items MUST be sorted descending by the date column derived from `date|linkedAt|createdAt|dtstart|created`

#### Scenario: Timeline only includes grouped types
- **GIVEN** the aggregated `$relations` contains `notes` and `emails`
- **AND** `notes` is shaped `{results: [...], total: ...}` while `emails` is the raw service envelope
- **WHEN** `buildTimeline()` iterates groups
- **THEN** notes entries MUST appear in the timeline
- **AND** emails entries MUST be skipped (no `results` key on that group)

#### Scenario: Frontend normalises typed envelope client-side
- **GIVEN** the backend returns `{ emails: [...], events: [...], contacts: [...], deck: [...] }` (no `view=timeline`)
- **WHEN** `RelationsTab.normaliseResponse(data)` runs
- **THEN** it MUST emit a flat array of `{id, type, title, subtitle, timestamp}` records
- **AND** sort that array by `new Date(timestamp).getTime()` descending
- **AND** each record's `id` MUST fall back through `raw.id || raw.uid || raw.uuid` and finally a random `${type}-${Math.random()}`

### REQ-004: Deck card relations SHALL be served per object and degrade gracefully to "unavailable" on HTTP 501

The Vue tab `DeckTab.vue` mounts a Pinia store `useDeckRelationsStore` keyed on the canonical triple `${register}:${schema}:${objectId}`. The store wraps three endpoints under `/api/objects/{register}/{schema}/{id}/deck`: `GET` (list cards), `POST` (create or link), `DELETE /{deckRef}` (unlink). On `fetch()`, the store stores results in `byObject[key]`, normalising a `response.data.results || response.data || []` payload to an array (coerces non-arrays to `[]`). When the API returns HTTP 501 (Deck app absent), the store sets `deckUnavailable = true`, writes an empty array into `byObject[key]`, and resolves successfully; the tab consults `deckUnavailable` to render an `NcEmptyContent` "Deck integration is not available" state instead of an error. Any other failure is recorded in `errors[key]` and re-thrown so the tab can flip its local `error` flag. `unlink(register, schema, id, deckRef)` URI-encodes the `deckRef` segment and updates `byObject[key]` locally without a refetch.

#### Scenario: Fetch succeeds with array payload
- **GIVEN** a request to `GET …/{id}/deck` returns `{ results: [...] }` or a bare array
- **WHEN** `useDeckRelationsStore.fetch(register, schema, id)` runs
- **THEN** the store MUST set `loading[key] = true` while in flight, then `false` in the `finally` block
- **AND** populate `byObject[key]` with the array (coerce non-arrays to `[]`)

#### Scenario: Deck app missing
- **GIVEN** the backend returns HTTP 501
- **WHEN** `fetch()` runs
- **THEN** the store MUST set `deckUnavailable = true`
- **AND** populate `byObject[key] = []`
- **AND** resolve with `[]` (no throw)
- **AND** `DeckTab` MUST render the "Deck integration is not available" empty state

#### Scenario: Unlink removes the card client-side
- **GIVEN** a card with `ref: 'oc-7-42'` exists in `byObject[key]`
- **WHEN** `store.unlink(register, schema, id, 'oc-7-42')` runs
- **THEN** the store MUST send `DELETE …/{id}/deck/oc-7-42` (URI-encoded)
- **AND** filter the local cache so cards whose `ref || deckRef || id` matches are removed
- **AND** NOT issue a follow-up GET

### REQ-005: Calendar event relations SHALL be served per object with create, link, unlink, and 501 degradation

The Vue tab `EventsTab.vue` mounts a Pinia store `useEventRelationsStore` keyed on `${register}:${schema}:${objectId}`. The store wraps four endpoints under `/api/objects/{register}/{schema}/{id}/events`: `GET` (list), `POST` (create new event), `POST /link` (link existing event), `DELETE /{eventId}` (unlink). `fetch()` populates `byObject[key]` from `response.data.results || response.data || []`. On HTTP 501 the store sets `calendarUnavailable = true` and resolves with `[]`. The tab renders three exclusive states: a loader while `loading[key]` is truthy, a "Calendar integration is not available" empty state when `calendarUnavailable` is true, an "Failed to load linked events" `NcEmptyContent` when its local `error` flag is set, and otherwise the linked-events list. `unlinkEvent(event)` calls `store.unlink(...)` and emits `events-changed` with the post-unlink count so the parent detail view can update its tab counter.

#### Scenario: List linked events
- **GIVEN** `GET …/{id}/events` returns `[ {id, summary, startsAt, calendarDisplayName, …} ]`
- **WHEN** `useEventRelationsStore.fetch(register, schema, id)` runs
- **THEN** the store MUST populate `byObject[key]` with that array
- **AND** the tab MUST render each event with the summary, calendar display name, and `startsAt` formatted via `new Date(value).toLocaleString()`

#### Scenario: Create new event
- **GIVEN** a `POST …/{id}/events` request with a payload (summary/start/end/calendar)
- **WHEN** `store.create(register, schema, id, payload)` runs
- **THEN** the store MUST POST to the base events URL
- **AND** AFTER POST resolves, MUST call `fetch()` again to refresh `byObject[key]`
- **AND** return the POST response body to the caller

#### Scenario: Link existing event
- **GIVEN** a request to attach an existing CalDAV event
- **WHEN** `store.link(register, schema, id, payload)` runs
- **THEN** the store MUST POST to `…/events/link` (suffix `/link`)
- **AND** AFTER POST resolves, MUST refetch via `fetch()`

#### Scenario: Calendar app missing
- **GIVEN** `GET …/{id}/events` returns HTTP 501
- **WHEN** `fetch()` runs
- **THEN** the store MUST set `calendarUnavailable = true`
- **AND** populate `byObject[key] = []`
- **AND** resolve with `[]`
- **AND** `EventsTab` MUST render the "Calendar integration is not available" empty state

#### Scenario: Unlink emits parent counter update
- **GIVEN** the user clicks the unlink button on event with id `evt-42`
- **WHEN** `EventsTab.unlinkEvent({id: 'evt-42'})` runs
- **THEN** the store MUST send `DELETE …/{id}/events/evt-42` (URI-encoded)
- **AND** remove the matching entry from `byObject[key]` (filter by `e.id !== eventId`)
- **AND** the tab MUST emit `events-changed` with the new length of `events`

## Notes

- `RelationsController::gatherRelations()` silently swallows every per-type exception with an empty `catch (Exception $e) { /* Silently skip on error */ }` block. This is observed behavior — it does protect aggregation from a single bad service, but it also hides genuine bugs from clients. A future hardening change should at minimum log these via `LoggerInterface`.
- `buildTimeline()` uses `rtrim($type, 's')` to derive the singular form for the `type` field. This produces `'dec'` for the `deck` key (Deck cards) and `'contact'` for `contacts` — the frontend `RelationsTab.normaliseEntry()` reads `raw.type` and matches `'deck'` for icon selection, so the controller's `'dec'` form is effectively dead for Deck items in the timeline view. Documented here as observed; not corrected.
- `DeckTab.fetchCards()` is wired to a Vue `watch` on `objectId` with `immediate: true`, so it fires once on mount and again whenever the object changes — there is no debounce. Same pattern in `EventsTab.fetchEvents()` and `RelationsTab.fetchRelations()`.
- The Deck and Events stores both keep an independent `errors[key]` map but the tabs read only the resolved promise; the maps are observed but unused by the current UI.
