# generic-integrations Specification

## Purpose
TBD - created by archiving change integration-shares. Update Purpose after archive.
## Requirements
### Requirement: Shares Provider Registration

`SharesProvider` SHALL be registered with id='shares', group='core', requiredApp=null (NC core), storage='query-time'.

#### Scenario: Provider always present

- **GIVEN** any Nextcloud install
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** exactly ONE provider with id='shares' MUST be included
- **AND** its `getRequiredApp()` MUST return `null`

---

### Requirement: Query-Time Aggregation

The provider SHALL NOT maintain its own link table. Shares SHALL be queried live from `OCP\Share\IManager::getSharesBy()` filtered by the object's linked files. The legacy `MarkerLookupTrait`-on-`share.note` path MUST be removed.

#### Scenario: List queries IManager per linked file

- **GIVEN** an OR object with two linked files, each carrying a user share
- **WHEN** `SharesProvider::list()` is invoked
- **THEN** `IManager::getSharesBy()` MUST be called for each linked file
- **AND** the returned rows MUST union shares across files, deduplicated by share id

---

### Requirement: Read + Revoke Only

The tab SHALL support list and revoke. Create / update share flows SHALL delegate to the NC Files UI.

#### Scenario: Revoke deletes the share

- **GIVEN** a share exists on an object's linked file
- **WHEN** the user clicks revoke in `CnSharesTab`
- **THEN** `IManager::deleteShare()` MUST be called
- **AND** the share MUST disappear from the tab

---

### Requirement: Group-By Display

The tab SHALL group shares by type: user / group / public link / federated.

#### Scenario: Mixed types grouped

- **GIVEN** one user share, one group share, and one public link on an object
- **WHEN** `CnSharesTab` renders
- **THEN** rows MUST be grouped into three distinct sections labelled by type

---

### Requirement: Widget Surfaces

Per umbrella AD-6 / AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); the dashboard surfaces SHALL show a count headline.

#### Scenario: Dashboard count headline

- **GIVEN** an object with 3 user shares + 1 public link
- **WHEN** `CnSharesCard` renders on `surface='detail-page'`
- **THEN** a count headline MUST surface totals by type
- **AND** the most-recent share MUST appear as a secondary row

---

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'shares'` SHALL render `CnSharesCard` at `surface='single-entity'` showing a share-type chip.

#### Scenario: Single-entity chip

- **GIVEN** a schema property carrying `referenceType: 'shares'`
- **WHEN** a form / detail renderer mounts it
- **THEN** the registry MUST resolve `CnSharesCard` for `surface='single-entity'`
- **AND** the chip MUST display the share-type icon plus a target label

---

### Requirement: Permission Inheritance

`SharesProvider::requiresPermission()` SHALL return `null`. Share visibility per user is governed by NC Share Manager transitively.

#### Scenario: Per-user visibility honored

- **GIVEN** a share visible only to user A
- **WHEN** user B invokes `SharesProvider::list()` against the same object
- **THEN** the row MUST NOT appear in the response
- **AND** `requiresPermission()` MUST return `null`

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When the underlying NC core sharing subsystem is unreachable, the provider SHALL return an empty list and report degraded health rather than leaking generic errors.

#### Scenario: Share subsystem unreachable

- **GIVEN** `OCP\Share\IManager` is unavailable (binding misconfigured / runtime failure)
- **WHEN** `SharesProvider::list()` is invoked
- **THEN** the method MUST return `[]`
- **AND** `health()` MUST surface the documented degraded shape rather than throwing

#### Scenario: User lacks share-management permission

- **GIVEN** a user viewing object shares but lacking the NC permission to revoke a specific share
- **WHEN** `CnSharesTab` renders that share
- **THEN** the revoke action MUST be disabled with a tooltip "Only the share owner can revoke"
- **AND** listing the share MUST still succeed

### Requirement: Integration list responses MUST be normalised into a canonical pagination envelope

Provider `list()` methods MAY return either a flat row array or a partial envelope (`{items|results, total?, nextCursor?}`); the dispatch layer MUST normalise any such value into the single canonical envelope `{items, total, nextCursor}` via `PaginatedResult::fromMixed()` before it reaches the client. Normalisation MUST be permissive: a flat list MUST yield `total = count(items)` and `nextCursor = null`; a `results` key MUST be treated as an alias of `items`; an absent `total` MUST fall back to the item count; a non-array value MUST yield an empty envelope (`items = []`, `total = 0`). The serialised form MUST additionally mirror `items` under a `results` key for backward-compatible frontend readers. This requirement documents existing behavior implemented by `lib/Service/Integration/PaginatedResult.php`.

#### Scenario: Flat list is wrapped into a single-page envelope

- **GIVEN** a provider's `list()` returns a flat array of 3 rows
- **WHEN** the value is passed through `PaginatedResult::fromMixed()`
- **THEN** the result MUST be `{items: <the 3 rows>, total: 3, nextCursor: null}`

#### Scenario: Partial envelope with results alias and explicit total is preserved

- **GIVEN** a provider returns `{results: [row], total: 42, nextCursor: '50'}`
- **WHEN** the value is normalised
- **THEN** `items` MUST equal `[row]`
- **AND** `total` MUST equal `42`
- **AND** `nextCursor` MUST equal `'50'`

#### Scenario: Absent total falls back to the item count

- **GIVEN** a provider returns `{items: [rowA, rowB]}` with no `total`
- **WHEN** the value is normalised
- **THEN** `total` MUST equal `2`
- **AND** `nextCursor` MUST be `null`

#### Scenario: Non-array value yields an empty envelope

- **GIVEN** a provider returns `null` (or a scalar)
- **WHEN** the value is normalised
- **THEN** the result MUST be `{items: [], total: 0, nextCursor: null}`

#### Scenario: Serialised envelope mirrors items under results

- **GIVEN** a normalised envelope with `items = [row]`
- **WHEN** it is serialised via `toArray()`
- **THEN** the array MUST contain both `items` and `results` equal to `[row]`
- **AND** MUST contain `total` and `nextCursor`

### Requirement: Object-Scoped Integration Link REST Contract

The system MUST expose a uniform object-scoped link REST surface so each
integration provider links its external resources (reports, pages, work
packages, polls, rooms, map favourites, wiki pages, mail messages) to an
OpenRegister object through one consistent contract. Every object-scoped link
controller MUST mount its routes under
`/api/objects/{register}/{schema}/{id}/{provider}` and MUST implement: a `GET`
list of linked resources, a `POST` to link an existing resource by id, an
optional `POST` create-and-link, and a `DELETE` unlink keyed by the provider's
resource id. Provider picker-source endpoints (the resources the current user
may link, plus any create-cascade parents) MUST be exposed under
`/api/integrations/{provider}/...`.

The list and picker responses MUST use the `{results, total}` envelope.
Successful link and create-and-link responses MUST return HTTP `201` with the
serialised link row; successful unlink MUST return `{success: true}`. When the
required Nextcloud app backing the provider is not installed, every endpoint
MUST short-circuit with HTTP `501` and a body `{error, code:
"APP_NOT_AVAILABLE"}`. When the target object cannot be resolved from
`(register, schema, id)`, the endpoint MUST return HTTP `404` with
`{error: "Object not found"}`. Service-layer exceptions MUST be mapped to HTTP
status by exception code (`400`/`401`/`404`/`409`/`503`), defaulting to `400`.

These controllers MUST authorize against the active user session (no admin
gate) and MUST rely on the backing service to scope resources to that user;
the contract therefore assumes a user-owned provider, and any provider whose
resources are NOT user-scoped MUST add its own authorization rather than
inherit this contract's session-only default.

#### Scenario: List linked resources for an object
- **GIVEN** an authenticated user and an object resolvable from `(register, schema, id)`
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/{id}/{provider}`
- **THEN** the response MUST be a `{results, total}` envelope listing the resources linked to that object

#### Scenario: Link an existing resource returns 201
- **GIVEN** an authenticated user and a valid resource id in the request body
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/{id}/{provider}`
- **THEN** the link row MUST be created and the response MUST be HTTP `201` carrying the serialised link

#### Scenario: Unlink returns success envelope
- **GIVEN** an existing link between an object and a provider resource
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/{id}/{provider}/{resourceId}`
- **THEN** the link MUST be removed and the response MUST be `{success: true}`

#### Scenario: Backing app not installed yields 501
- **GIVEN** the Nextcloud app backing the provider is not installed
- **WHEN** any of the provider's link endpoints is invoked
- **THEN** the response MUST be HTTP `501` with body `{error, code: "APP_NOT_AVAILABLE"}`

#### Scenario: Unresolvable object yields 404
- **GIVEN** a `(register, schema, id)` triple that does not resolve to an object
- **WHEN** any object-scoped link endpoint is invoked
- **THEN** the response MUST be HTTP `404` with `{error: "Object not found"}`

#### Scenario: Service exception codes map to HTTP status
- **GIVEN** the backing service throws an exception carrying code `409` (duplicate link)
- **WHEN** the controller catches it via its `mapException()` helper
- **THEN** the response status MUST be `409`
- **AND** an exception code outside `{400,401,404,409,503}` MUST default to HTTP `400`

### Requirement: Tier-2 Integration Leaf Link Controller Contract

The system MUST expose object-scoped integration "leaf" link controllers that
share one uniform REST contract so a Nextcloud entity (bookmark, Cospend
project/bill, Deck card, Flow operation, Form/submission, Photos album,
TimeManager entry, Activity entry, mail message) can be linked to an
OpenRegister object. Each leaf controller MUST resolve the target object from
the `{register}/{schema}/{id}` path triple and MUST return `404` with an
`{error}` body when the object does not resolve. The contract comprises:

- a **list** verb (`index`, `GET /api/objects/{register}/{schema}/{id}/{leaf}`)
  returning `{results, total}`;
- a **link-existing** verb (`link`/`create`,
  `POST /api/objects/{register}/{schema}/{id}/{leaf}`) returning `201` with the
  link's JSON serialization, validating the required reference id in the body
  and returning `400` when it is missing;
- where the backing app supports creation, a **create-and-link** verb
  (`createNew`/`createAndLink`/`create`,
  `POST /api/objects/{register}/{schema}/{id}/{leaf}/new`) returning `201`;
- an **unlink** verb (`destroy`,
  `DELETE /api/objects/{register}/{schema}/{id}/{leaf}/{entityId}`) returning a
  success body; and
- one or more **picker-source discovery** verbs
  (`available` / `boards` / `stacks` / `operations` / `types` / `actors`,
  under `/api/integrations/{leaf}/...`) that surface candidate entities for the
  link modal without leaking the backing app's internals.

Every verb MUST degrade gracefully when the backing Nextcloud app is not
installed by returning HTTP `501` with the envelope
`{error, code: "APP_NOT_AVAILABLE"}`. Service-layer exceptions MUST be mapped
to HTTP status by exception code (`409` conflict, `404` not found, `503`
unavailable, `400` bad request). Read-only leaves (Activity) MUST expose only
the list + discovery verbs and omit link/create/unlink. Admin-gated leaves
(Flow) MUST restrict mutating verbs to admins while leaving list read-only for
all authenticated users.

#### Scenario: List linked entities for an object
- **GIVEN** an OpenRegister object resolvable from `{register}/{schema}/{id}` and the backing app installed
- **WHEN** `GET /api/objects/{register}/{schema}/{id}/{leaf}` is called
- **THEN** the response MUST be HTTP 200 with a `{results, total}` body
- **AND** an unresolvable object MUST return HTTP 404 with an `{error}` body

#### Scenario: Link an existing entity requires the reference id
- **GIVEN** a resolvable object
- **WHEN** the link verb is called without the required reference id in the body
- **THEN** the response MUST be HTTP 400 with an `{error}` body
- **AND** a valid link request MUST return HTTP 201 with the link's JSON serialization

#### Scenario: Graceful degradation when the backing app is absent
- **GIVEN** the backing Nextcloud app (e.g. Bookmarks, Cospend, Deck) is not installed
- **WHEN** any verb on the corresponding leaf controller is called
- **THEN** the response MUST be HTTP 501 with the body `{error, code: "APP_NOT_AVAILABLE"}`

#### Scenario: Picker-source discovery surfaces candidates without internals
- **GIVEN** the backing app is installed
- **WHEN** the discovery verb (`available`/`boards`/`stacks`/`operations`/`types`/`actors`) is called
- **THEN** the response MUST return the candidate entities visible to the current user as `{results, total}` (or the verb-specific shape)
- **AND** entities the current user cannot see MUST be omitted

#### Scenario: Read-only and admin-gated leaves restrict mutating verbs
- **GIVEN** the read-only Activity leaf and the admin-gated Flow leaf
- **WHEN** a non-admin attempts a mutating verb
- **THEN** the Activity leaf MUST expose no link/create/unlink verb at all
- **AND** the Flow leaf MUST reject the mutating verb for non-admins while still serving its list verb read-only

### Requirement: Tier-2 Dedicated-Mapper Link Services for Optional Peer Apps

The system SHALL provide a family of Tier-2 link services — `AnalyticsLinkService`,
`CalendarLinkService`, `DeckLinkService`, `EmailLinkService`, `FlowLinkService`, and
`PollLinkService` — that persist links between OpenRegister objects and entities owned by
an optional Nextcloud peer app (NC Analytics, Calendar, Deck, Mail, Flow/n8n, Polls) via a
dedicated link mapper rather than the report-name/marker conventions of the Tier-1
providers. Each service MUST resolve the peer app's own services lazily through
`\OCP\Server::get()` behind a `class_exists` guard and an `is*Available()` /
`IAppManager` check, so OpenRegister boots and degrades gracefully when the peer app is
not installed (ADR-019 AD-23).

Each service MUST expose a consistent orchestration surface:

- a **link** operation (`linkReport` / `linkEvent` / `linkCard` / `linkEmail` /
  `linkOperation` / `linkPoll`) that is idempotent — a duplicate link MUST raise a 409 —
  and caches the peer entity's display metadata on the link row at link time;
- a **create-and-link** operation where the peer app supports creation
  (`createAndLinkReport` / `createAndLinkEvent` / `createAndLinkCard` /
  `createAndLinkPoll`) that creates the peer entity then links it, surfacing peer-app
  failures as a 500 and empty required input as a 400;
- an **unlink** operation (`unlinkReport` / `unlinkEvent` / `unlinkCard` / `unlinkEmail` /
  `unlinkOperation` / `unlinkPoll`) that removes only the link row — the peer entity
  itself is left intact — and raises a 404 when no matching link exists;
- a **linked-list** read (`getLinkedReports` / `getLinkedEvents` / `getLinkedCards` /
  `getLinkedEmails` / `getLinkedOperations` / `getLinkedPolls`) that returns the link rows
  for an object, refreshing stale cached metadata from the peer app when available and
  returning the cached values unchanged when it is not;
- a **picker source** read (`getAvailableReports` / `getAvailableCalendars` /
  `getAvailableBoards` / `getAvailableAccounts` / `getAvailableOperations` /
  `getAvailablePolls`) that lists the current user's peer entities for selection,
  returning an empty array when the peer app is unavailable;
- optional **sub-resource pickers** for hierarchical peer apps
  (`getEventsForCalendar`, `getStacksForBoard`, `getMailboxesForAccount`,
  `getMessagesForMailbox`).

#### Scenario: Link is idempotent and caches peer metadata
- **GIVEN** the peer app is available and an object is not yet linked to a peer entity
- **WHEN** the service's link operation runs
- **THEN** a link row MUST be persisted with the peer entity's display metadata cached on it
- **AND** a second link of the same object-to-entity pair MUST raise a 409

#### Scenario: Unlink removes the link only
- **GIVEN** an object linked to a peer entity
- **WHEN** the service's unlink operation runs
- **THEN** the link row MUST be deleted
- **AND** the peer entity MUST remain in the peer app
- **AND** unlinking a non-existent link MUST raise a 404

#### Scenario: Graceful degradation when the peer app is uninstalled
- **GIVEN** the peer app is not installed
- **WHEN** the picker-source read runs
- **THEN** it MUST return an empty array rather than throwing
- **AND** the linked-list read MUST return the cached link-row values unchanged

#### Scenario: Linked-list refreshes stale cached metadata
- **GIVEN** a link row whose cached metadata is older than the service's staleness window and the peer app is available
- **WHEN** the linked-list read runs
- **THEN** the row's cached title/type/timestamps MUST be refreshed from the peer app
- **AND** a refresh failure MUST leave the cached values untouched (the peer entity may have been deleted)

### Requirement: Tier-2 Remote-Entity Link Service Contract
A Tier-2 link service MUST persist object↔remote-entity bindings in its own local link table, resolve its provider/router lazily so the service loads even when the backing app is absent, and expose five operations — link an existing remote entity, create-and-link a new one, unlink (binding only, never deleting the remote), list linked entities with stale-cache refresh, and browse available entities for the picker — each degrading gracefully when the backing app or upstream is unavailable.

Each link service (`XwikiLinkService`, `TalkLinkService`, `OpenProjectLinkService`, `BookmarkLinkService`, `CollectiveLinkService`) MUST:
- **link** an existing remote entity to an OR object, rejecting an empty reference (400), rejecting a duplicate binding (409), and caching the remote entity's display metadata at link time;
- **create-and-link** a new remote entity through the backing provider, then persist the binding with the returned canonical reference;
- **unlink** by removing only the local binding row (404 when no binding matches) and MUST NOT delete the remote entity itself, since other objects may still link it;
- **list** the linked entities for an object, refreshing a row's cached metadata from the upstream when a source is configured and the row's cache is older than the stale window, and MUST NOT throw when the upstream is down — stale rows MUST survive;
- **browse/picker** the available remote entities, returning a structured `{ unavailable, cause, results, total }` descriptor (rather than throwing) when the backing app is not installed or the upstream is unreachable.

Mutating operations MUST require an authenticated user (401 otherwise) and MUST surface an unconfigured-source / upstream-down condition as a 503 carrying the cause so the controller can render the integration banner.

#### Scenario: Link an existing remote entity
- **GIVEN** an authenticated user and a valid remote entity reference
- **WHEN** the link operation runs and no binding yet exists
- **THEN** a link row MUST be persisted with the canonical reference and cached display metadata
- **AND** a second identical link attempt MUST be rejected with 409

#### Scenario: Unlink leaves the remote entity intact
- **GIVEN** an existing binding between an object and a remote entity
- **WHEN** the unlink operation runs
- **THEN** only the local binding row MUST be removed
- **AND** the remote entity MUST remain available to any other linked objects

#### Scenario: Picker degrades when the backing app is absent
- **GIVEN** the backing app (or upstream) is unavailable
- **WHEN** the browse/picker operation runs
- **THEN** it MUST return `{ unavailable: true, cause, results: [], total: 0 }` without throwing

#### Scenario: List survives an upstream outage with stale rows
- **GIVEN** linked rows whose cached metadata is stale and the upstream is down
- **WHEN** the list operation runs
- **THEN** the stale rows MUST be returned as-is rather than the call failing

