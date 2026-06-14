---
status: done
---

# generic-integrations Specification

## Purpose

@e2e exclude Nextcloud shares provider backend — covered by PHPUnit
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

Every registered widget SHALL render correctly in four surfaces: `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`. The `surface` SHALL be passed as a prop to the widget component. Optional surface-specific components (`widgetCompact`, `widgetExpanded`, `widgetEntity`) SHALL be used when present; otherwise the registry SHALL fall back to the main `widget`.

#### Scenario: Default widget renders on all surfaces

- **GIVEN** an integration registers only `widget: FooCard` with no surface-specific variants
- **WHEN** the widget is rendered on any of the four surfaces
- **THEN** `FooCard` MUST be rendered with the `surface` prop set appropriately
- **AND** `FooCard` MAY branch on `surface` internally

#### Scenario: Surface-specific widget overrides the default

- **GIVEN** an integration registers `widget: FooCard` AND `widgetEntity: FooChip`
- **WHEN** the widget is rendered with `surface='single-entity'`
- **THEN** `FooChip` MUST be rendered (not `FooCard`)

#### Scenario: A new surface added in the future falls back to the main widget

- **GIVEN** a future surface name `email-digest` is added to the registry's surface enum
- **WHEN** an existing integration that did not declare `widgetEmailDigest` is rendered with `surface='email-digest'`
- **THEN** the main `widget` component MUST be rendered with `surface='email-digest'`
- **AND** no error MUST be thrown

#### Scenario: Widget render failure is isolated

- **GIVEN** an integration's widget component throws an error during render
- **WHEN** it is mounted on a dashboard alongside other widgets
- **THEN** the failing widget MUST render a fallback "Widget unavailable" state with the integration id and error message
- **AND** other widgets on the same dashboard MUST continue to render normally
- **AND** the error MUST be logged for debugging

### Requirement: Reference-Property Auto-Rendering

When a JSON schema property declares `referenceType: <integration-id>`, frontend form and detail components (`CnFormDialog`, `CnDetailGrid`) SHALL render the matching integration's `single-entity` widget surface inline next to the property, passing the entity id as `entityId`.

#### Scenario: Schema property with referenceType renders integration widget

- **GIVEN** a schema with property `assignedHandler: { type: 'string', referenceType: 'contacts' }` and an object with `assignedHandler: 'vcard-uuid-123'`
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the `assignedHandler` row MUST contain the `contacts` integration's `single-entity` widget (or fallback `widget`) with `entityId='vcard-uuid-123'`
- **AND** the widget MUST receive `surface='single-entity'`

#### Scenario: Schema property without referenceType renders normally

- **GIVEN** a schema with property `notes: { type: 'string' }` (no `referenceType`)
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the `notes` value MUST be rendered as plain text — no integration widget invoked

#### Scenario: Reference to a missing entity renders a broken-link placeholder

- **GIVEN** a schema property `assignedHandler: { type: 'string', referenceType: 'contacts' }` and an object with `assignedHandler: 'vcard-uuid-deleted'`
- **AND** the referenced vCard has been deleted from the NC address book
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the widget MUST render a "Reference unavailable" placeholder with the id visible to admins (not to end users)
- **AND** the render MUST NOT throw

#### Scenario: Reference to a provider whose required NC app is uninstalled

- **GIVEN** a schema property with `referenceType: 'deck'` and the NC Deck app is not installed
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the widget MUST render a "Deck not installed" placeholder with admin-only install hint
- **AND** the render MUST NOT throw

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

### Requirement: Constants Removed

`LinkedEntityService::TYPE_COLUMN_MAP` and `Schema::VALID_LINKED_TYPES` SHALL be absent from the codebase after this change.

#### Scenario: Grep confirms absence

- **WHEN** the codebase is grep'd for `TYPE_COLUMN_MAP` or `VALID_LINKED_TYPES`
- **THEN** zero matches MUST exist in OR core or `@conduction/nextcloud-vue`

### Requirement: Registry-Driven Behaviour Unchanged

All integration discovery and schema validation SHALL continue to function via `IntegrationRegistry`.

#### Scenario: Existing schemas continue to validate

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes"]`
- **WHEN** the schema is saved after this change
- **THEN** validation MUST succeed via `IntegrationRegistry::listIds()`

### Requirement: Pre-Removal Grep Sweep

A grep sweep of the ConductionNL organisation SHALL be run before the removal commit, and any remaining references outside OR core MUST be migrated before removal.

#### Scenario: External callers migrated before removal

- **GIVEN** the W25 sweep is preparing to remove `TYPE_COLUMN_MAP`
- **WHEN** `git grep -lE 'TYPE_COLUMN_MAP|VALID_LINKED_TYPES'` is run across the Conduction org repositories
- **THEN** every match outside OR core MUST be migrated to the registry-driven equivalent before the removal commit lands

### Requirement: Global Bootstrap Entry

OpenRegister SHALL ship a small webpack entry
`openregister-integration-global.js` that imports and calls
`ensureIntegrationRegistry()` exactly once per page load.

#### Scenario: Bootstrap entry is idempotent

- **GIVEN** the global bootstrap script is included twice on the same page
- **WHEN** both copies execute
- **THEN** only one shared registry instance MUST exist on `window`
- **AND** builtin descriptors MUST NOT be registered twice

### Requirement: Shared Registry via nc-vue Primitives

`ensureIntegrationRegistry()` SHALL resolve the shared registry through
`getSharedRegistry(window)` (converge-not-clobber + install-if-needed)
from `@conduction/nextcloud-vue` rather than a per-bundle module singleton.

#### Scenario: Foreign-app useIntegrationRegistry sees populated registry

- **GIVEN** the global bootstrap has run on an OpenCatalogi publication page
- **WHEN** OpenCatalogi's bundle calls `useIntegrationRegistry()`
- **THEN** the returned registry MUST be the same window-global instance
- **AND** the registry MUST contain every builtin + leaf descriptor that was
  registered or queued before the call

### Requirement: BeforeTemplateRenderedEvent Listener

OpenRegister SHALL register an `IntegrationGlobalScriptListener` on `BeforeTemplateRenderedEvent` that unconditionally calls `Util::addInitScript('openregister', 'openregister-integration-global')` on every full-page render, so the bootstrap is present even when OpenRegister's own SPA is not loaded.

#### Scenario: Bootstrap loads on a consuming app's page

- **GIVEN** a user opens an OpenCatalogi publication detail page
- **WHEN** the template renders
- **THEN** the page MUST include `openregister-integration-global.js`
- **AND** the `window.OCA.OpenRegister.integrations` registry MUST be
  installed before any consuming-app bundle runs

### Requirement: Leaf Queue Drain on Foreign Pages

Descriptors queued via `window.OCA.OpenRegister.integrations.register(...)` from a leaf app's bundle SHALL be drained into the shared registry once the bootstrap installs it, regardless of whether OpenRegister's main bundle has loaded.

#### Scenario: OpenConnector sync-contract tab renders on an OpenCatalogi page

- **GIVEN** OpenConnector's Path-2 component bundle has queued a
  `sync-contract` descriptor on a page served by OpenCatalogi
- **WHEN** the global bootstrap installs + populates the shared registry
- **THEN** the queued descriptor MUST be drained into the shared registry
- **AND** the "Synced from" tab/widget MUST render in
  OpenCatalogi's `CnObjectSidebar` instance

### Requirement: Zero Changes Required in Consuming Apps

This change SHALL NOT require any code change in consuming apps
(OpenCatalogi, OpenConnector, etc.) for the shared registry to work.

#### Scenario: OpenCatalogi unchanged still hosts the shared registry

- **GIVEN** OpenCatalogi has zero source changes
- **WHEN** the user opens a publication detail page after this change ships
- **THEN** the shared registry MUST be installed + populated
- **AND** the integration widgets MUST render through OpenCatalogi's existing
  `CnObjectSidebar` / `CnDetailPage` mount points

### Requirement: Integration Provider Contract

The system SHALL define a PHP interface `OCA\OpenRegister\Service\Integration\IntegrationProvider` that every backend integration implements. The interface SHALL be relations-shaped (generic "linked thing" terminology) so that future unification with `RelationsService` (object↔object) is possible without breaking the contract.

#### Rationale

A uniform contract is the entire point of a registry. Each integration ships a vertical slice (provider + tab + widget); the contract guarantees they can be composed without core knowing about them individually.

#### Scenario: A new integration implements the contract

- **GIVEN** a developer wants to add a new integration named `forms`
- **WHEN** they create a class `FormsProvider implements IntegrationProvider`
- **THEN** their class MUST implement: `getId()`, `getLabel()`, `getIcon()`, `getGroup()`, `getRequiredApp()`, `getStorageStrategy()`, `getOpenConnectorSource()`, `isEnabled()`, `requiresPermission()`, `authRequirements()`, `list()`, `get()`, `create()`, `update()`, `delete()`, `health()`
- **AND** the class MUST be registered as a DI-tagged service with tag `IntegrationProvider`
- **AND** `IntegrationRegistry::list()` MUST return the new provider on the next request

#### Scenario: A provider with no required app is always available

- **GIVEN** a provider returns `null` from `getRequiredApp()`
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the provider MUST be included in the result regardless of which Nextcloud apps are installed

#### Scenario: A provider whose required NC app is missing is hidden

- **GIVEN** a provider returns `'deck'` from `getRequiredApp()` and the Deck app is not installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the provider MUST be excluded from the result
- **AND** `GET /api/integrations` MUST NOT list it
- **AND** `CnObjectSidebar` MUST NOT render its tab

---

### Requirement: Storage Strategy Enum

`IntegrationProvider::getStorageStrategy()` SHALL return one of four values: `'magic-column'`, `'link-table'`, `'external'`, `'query-time'`. Any other value SHALL be rejected by the registry at registration time.

- `magic-column` — link stored as a column on the OR object row (legacy built-ins).
- `link-table`   — link stored in a dedicated `openregister_*_links` table.
- `external`     — no local persistence; CRUD routed through OpenConnector. Provider SHALL also implement `getOpenConnectorSource()` returning a non-null source id.
- `query-time`   — no local persistence; the source system is queried live on every `list()` call. Mutation methods SHALL throw `NotImplementedException`.

#### Scenario: query-time provider rejects mutation

- **GIVEN** a provider returning `'query-time'` from `getStorageStrategy()`
- **WHEN** `create()`, `update()`, or `delete()` is invoked
- **THEN** the provider MUST throw `NotImplementedException`
- **AND** the controller layer MUST translate the exception to HTTP 501 with a body that names the source-of-truth NC service

#### Scenario: query-time provider exceeding timeout returns degraded surface

- **GIVEN** a `query-time` provider whose upstream service does not respond within the per-render timeout (default 2s)
- **WHEN** the surface (tab or widget) renders
- **THEN** the surface MUST render the degraded "source slow — retrying in background" state
- **AND** the request MUST NOT block the page beyond the timeout
- **AND** a structured log entry MUST be emitted with `{integration, surface, timeout_ms}`

#### Scenario: external provider missing OpenConnector source id is rejected

- **GIVEN** a provider returning `'external'` from `getStorageStrategy()` and `null` from `getOpenConnectorSource()`
- **WHEN** the registry resolves it
- **THEN** the registry MUST reject the provider with a clear error and exclude it from `getEnabled()`

---

### Requirement: External Provider Failure Modes

`ExternalIntegrationRouter` and `external` providers SHALL distinguish three failure modes via `ProviderUnavailableException` with a `details.cause` field of `'openconnector-down' | 'openconnector-source-missing' | 'upstream-service-down'`. Auth status SHALL be checked lazily — providers MUST NOT issue a health probe to OpenConnector on every render. A 401 from the upstream service SHALL be translated to `ProviderAuthException` with a `reconnectUrl`.

#### Scenario: OpenConnector NC app disabled

- **GIVEN** the OpenConnector NC app is disabled
- **WHEN** `OpenProjectProvider::list()` is called
- **THEN** the call MUST throw `ProviderUnavailableException`
- **AND** `details.cause` MUST equal `'openconnector-down'`
- **AND** the UI MUST render "Connector unavailable — admin: enable OpenConnector"

#### Scenario: OpenConnector source for the provider is missing

- **GIVEN** OpenConnector is enabled but no source named `openproject` is configured
- **WHEN** `OpenProjectProvider::list()` is called
- **THEN** `details.cause` MUST equal `'openconnector-source-missing'`
- **AND** the admin UI MUST link to OpenConnector's source-creation flow

#### Scenario: Upstream service unreachable

- **GIVEN** OpenConnector reaches `openproject` but the OpenProject API is unreachable
- **WHEN** `OpenProjectProvider::list()` is called
- **THEN** `details.cause` MUST equal `'upstream-service-down'`
- **AND** the UI MUST render "OpenProject offline — last seen <timestamp>"

#### Scenario: Lazy auth — token refresh handled by OpenConnector

- **GIVEN** an `external` provider whose tokens are refreshable via OpenConnector
- **WHEN** the provider invokes an upstream call without first calling `health()`
- **THEN** the provider MUST trust OpenConnector to refresh tokens silently
- **AND** only when the upstream returns 401 MUST the provider raise `ProviderAuthException`

---

### Requirement: Three-Stage Visibility Filter

The system SHALL filter integrations through three independent stages — registry existence, schema relevance, component context — before rendering. Each stage SHALL be observable so a developer can determine why a given integration is or isn't shown.

#### Rationale

Hardcoded visibility logic is the source of the current rigidity. Splitting the decision into three stages with distinct ownership (system / schema author / page author) lets each evolve independently.

#### Storage Model

```
Stage 1: IntegrationRegistry::getEnabled() — filtered by Provider::isEnabled()
Stage 2: Schema.configuration.linkedTypes — explicit whitelist (absent = empty)
Stage 3: Component prop excludeIntegrations — per-render override
```

#### Scenario: Schema with empty linkedTypes shows no integrations

- **GIVEN** a schema with `configuration.linkedTypes` absent or set to `[]`
- **WHEN** `CnObjectSidebar` renders for an object of that schema
- **THEN** no integration tabs MUST be shown
- **AND** the audit/tags built-in tabs (which are integrations themselves) MUST also be hidden unless the schema explicitly lists them

#### Scenario: Schema linkedTypes acts as a whitelist

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes", "calendar"]`
- **WHEN** `CnObjectSidebar` renders for an object of that schema
- **THEN** only the `files`, `notes`, and `calendar` tabs MUST be shown (assuming each is enabled in the registry)

#### Scenario: Component-level exclusion overrides schema relevance

- **GIVEN** a schema with `linkedTypes: ["files", "notes", "calendar"]`
- **WHEN** `<CnObjectSidebar :exclude-integrations="['calendar']">` renders
- **THEN** only `files` and `notes` tabs MUST be shown

#### Scenario: Schema validator accepts any registered integration id

- **GIVEN** an integration `forms` is registered via DI tag
- **WHEN** a schema is saved with `configuration.linkedTypes: ["forms"]`
- **THEN** `Schema::validateLinkedTypesValue()` MUST accept the value without error
- **AND** the validation MUST NOT consult the deprecated `Schema::VALID_LINKED_TYPES` constant

---

### Requirement: Widget-Parity Hard Rule

The system SHALL refuse to merge a change that registers an `IntegrationProvider` (frontend or backend) without a corresponding tab AND widget component. The check SHALL run in pre-commit, repository CI, and the hydra quality gate.

A `tab` or `widget` value is considered "set" only when **all** of the following hold:

- the registration object has the key (the key is present, not omitted),
- the value is not `null` and not `undefined`,
- `typeof value === 'function'` (a Vue component constructor or async-component factory) — plain object literals, primitives, and `false` MUST be rejected.

#### Rationale

User explicit preference — every integration gets both surfaces. Enforcing it at registration time prevents drift; making it a CI gate prevents merges from sneaking past local hooks. Without an executable definition of "set", a registration like `{tab: null, widget: FooCard}` could pass a naive presence check while breaking at render time.

#### Scenario: A registration without a widget fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab })` (missing `widget`)
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with an error naming the integration id and the missing component
- **AND** the CI workflow `integration-parity` MUST fail
- **AND** the hydra quality gate MUST report the failure under gate `integration-parity`

#### Scenario: A registration with `widget: null` fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab, widget: null })`
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with an error naming the integration id and that `widget` is null

#### Scenario: A registration with a non-component value fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab, widget: {} })` (object literal, not a function)
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with an error stating that `widget` is not a Vue component (typeof !== 'function')

#### Scenario: A registration without a tab fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', widget: FooCard })` (missing `tab`)
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with the same shape of error

#### Scenario: A complete registration passes

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab, widget: FooCard, label: 'Foo', icon: 'Foo' })`
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit zero

---

### Requirement: External Integration Routing via OpenConnector

Providers with `getStorageStrategy() === 'external'` SHALL route their CRUD operations through OpenConnector instead of a local storage table. The umbrella SHALL provide an `ExternalIntegrationRouter` service that handles dispatch + auth-status surfacing.

#### Scenario: External provider create call routes through OpenConnector

- **GIVEN** an external provider `openproject` with `storage: external` and an OpenConnector source `openproject-instance-1`
- **WHEN** `POST /api/objects/{register}/{schema}/{id}/openproject` is called
- **THEN** `ExternalIntegrationRouter` MUST resolve the provider's OpenConnector source
- **AND** call OpenConnector's create operation with the request payload + object context (register/schema/id)
- **AND** return the OpenConnector response shape unchanged

#### Scenario: External provider with missing credentials returns auth-status in health

- **GIVEN** an external provider whose OpenConnector source has no configured OAuth tokens
- **WHEN** `GET /api/integrations/openproject` is called
- **THEN** the response MUST include `health.status: 'unavailable'` AND `health.authStatus: 'missing'`
- **AND** the admin UI MUST surface a "Configure" button linking to OpenConnector's credential setup

---

### Requirement: Auth Requirements Declaration

`IntegrationProvider::authRequirements()` SHALL return the auth model and a config schema for credentials. OpenRegister SHALL surface unconfigured/expired auth in the admin UI and via the OCS capabilities response.

#### Scenario: Built-in NC integration declares no auth

- **GIVEN** the built-in `notes` provider
- **WHEN** `authRequirements()` is called
- **THEN** the response MUST be `['type' => 'none']`

#### Scenario: External integration declares OAuth2

- **GIVEN** an `openproject` provider
- **WHEN** `authRequirements()` is called
- **THEN** the response MUST be `['type' => 'oauth2', 'configSchema' => [...]]` describing the required credential fields

---

### Requirement: Per-Integration RBAC

`IntegrationProvider::requiresPermission()` SHALL return either `null` (default — inherit from object RBAC + NC app permissions) or a permission string. Permission strings SHALL be evaluated against `AuthorizationService` for the current user on the object before the integration is included in any list/read response.

The permission-string vocabulary recognised by `AuthorizationService` for integration gating is:

- `'admin'` — the user is a member of the Nextcloud admin group (`IGroupManager::isAdmin($userId)`).
- `'audit.view'` — the user has the OR-internal audit-view role on the object.
- A custom string starting with `<app-id>.` — delegated to that app's permission resolver.

Unknown permission strings SHALL be treated as "deny" and SHALL log a warning identifying the integration and the unrecognised string.

#### Scenario: Provider with no extra permission inherits object access

- **GIVEN** a user with read access to an object
- **AND** a provider returning `null` from `requiresPermission()`
- **WHEN** the provider is listed for the object
- **THEN** the provider MUST appear in `CnObjectSidebar` and `/api/integrations`

#### Scenario: Provider with a required permission is filtered

- **GIVEN** a user with read access to an object but lacking the `audit.view` permission on it
- **AND** an `audit-trail` provider returning `'audit.view'` from `requiresPermission()`
- **WHEN** the provider list is computed for the user/object
- **THEN** the `audit-trail` provider MUST be excluded from the result

---

### Requirement: OCS Capabilities Advertising

The system SHALL include an `integrations` block in the response from `/ocs/v2.php/cloud/capabilities` containing one entry per registered + enabled integration. Each entry SHALL be redacted per caller role:

- **All authenticated users** see only the public block: `{id, label, group, enabled, surfaces}`.
- **Admins** additionally see the sensitive block: `{requiresPermission, authStatus, openConnectorSource}`.

The sensitive fields SHALL be omitted (not set to `null`) for non-admin callers so that introspection cannot distinguish "field hidden" from "field unset". This protects against leaking infrastructure-configuration gaps (`authStatus: 'expired'` on OAuth-backed integrations) and the permission model (`requiresPermission` strings) to regular users.

#### Rationale

OCS capabilities is reachable by every authenticated NC user. Without role-based redaction, a non-admin learning that `authStatus: 'expired'` on `openproject` is told "this org has an OpenProject integration that nobody has reconnected" — disclosure of infrastructure state. Likewise, leaking permission strings (`audit.view`, `admin`, custom roles) reveals the org's RBAC topology. Admins need the full block to operate; everyone else only needs presence + label to render the UI.

#### Scenario: Non-admin caller sees only the public block

- **GIVEN** the registry has an enabled `openproject` integration with `authStatus: 'expired'` and `requiresPermission: null`
- **WHEN** a non-admin user calls `GET /ocs/v2.php/cloud/capabilities`
- **THEN** the `openproject` entry MUST contain exactly the fields `{id, label, group, enabled, surfaces}`
- **AND** the fields `requiresPermission`, `authStatus`, `openConnectorSource` MUST be absent from the entry

#### Scenario: Admin caller sees the full block

- **GIVEN** the same registry state
- **WHEN** an admin user calls `GET /ocs/v2.php/cloud/capabilities`
- **THEN** the `openproject` entry MUST contain `{id, label, group, enabled, surfaces, requiresPermission, authStatus, openConnectorSource}`

#### Scenario: Capabilities response advertises the registry

- **GIVEN** the registry has 8 enabled integrations
- **WHEN** `GET /ocs/v2.php/cloud/capabilities` is called
- **THEN** the response MUST include `data.capabilities.openregister.integrations` as an array of 8 objects
- **AND** each object MUST include at minimum the public-block fields documented above

---

### Requirement: Tags and Audit-Trail as First-Class Integrations

The umbrella SHALL ship `tags` and `audit-trail` as `IntegrationProvider` implementations. Both SHALL declare `getRequiredApp(): null` (always-available) and `getGroup(): 'core'`. Neither SHALL be special-cased in `CnObjectSidebar` rendering — they flow through the same registry + three-stage filter as every other integration.

#### Rationale

Historically these two were hardcoded tabs. Promoting them to first-class integrations makes the registry the single source of truth for what appears in the sidebar, eliminates special cases in rendering, and exposes the parity gap (neither has a card widget today) the umbrella must fill.

#### Scenario: Tags provider appears in the registry

- **GIVEN** the umbrella change is applied
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the result MUST include a provider with `id='tags'`, `group='core'`, `requiredApp=null`

#### Scenario: Audit-trail provider requires admin permission

- **GIVEN** the umbrella change is applied
- **WHEN** `IntegrationRegistry::getEnabled()` is called on behalf of a non-admin user
- **THEN** the `audit-trail` provider MUST be excluded per its `requiresPermission(): 'audit.view'` declaration

---

### Requirement: Registration Collision Handling

Registering an integration id that is already present SHALL be detected. On the PHP side, two DI-tagged providers with the same `getId()` SHALL cause the container build to fail. On the JS side, calling `integrations.register({ id: 'foo', ... })` when `foo` is already registered SHALL throw synchronously in development mode and log a warning (keeping the first registration) in production mode.

#### Rationale

Silent overwrite produces the worst debugging experience. Dev-mode throw catches it during development; production warn-and-keep prevents a single misbehaving app from breaking an entire NC deployment.

#### Scenario: Duplicate JS registration in dev mode throws

- **GIVEN** an integration `forms` is already registered
- **WHEN** another call is made: `integrations.register({ id: 'forms', ... })` in development mode
- **THEN** the call MUST throw a `IntegrationCollisionError` synchronously
- **AND** the error message MUST name the id and point at both registration sites

#### Scenario: Duplicate JS registration in production warns

- **GIVEN** an integration `forms` is already registered and the runtime is in production mode
- **WHEN** another call is made: `integrations.register({ id: 'forms', ... })`
- **THEN** the call MUST log a warning via `console.warn`
- **AND** the first registration MUST remain in effect (second is ignored)

#### Scenario: Duplicate DI tag fails container build

- **GIVEN** two services tagged `IntegrationProvider` both return `'forms'` from `getId()`
- **WHEN** the NC DI container is built
- **THEN** container build MUST fail with a clear error naming the id and both service class names

---

### Requirement: Error-Handling Contract

Provider methods that fail SHALL surface errors through a documented contract, not as generic 500s. `list()`, `get()`, `create()`, `update()`, `delete()` SHALL throw one of: `ProviderUnavailableException` (underlying system down), `ProviderAuthException` (credentials missing/expired), `ProviderNotFoundException` (entity doesn't exist), or `ProviderValidationException` (payload rejected). `ObjectsController` SHALL map these to HTTP statuses (503, 401, 404, 422) with a consistent JSON error body.

#### Scenario: Underlying NC app unreachable returns 503

- **GIVEN** the `deck` integration's backing Deck app crashes mid-request
- **WHEN** `GET /api/objects/{register}/{schema}/{id}/deck` is called
- **THEN** `DeckProvider::list()` MUST throw `ProviderUnavailableException`
- **AND** the response MUST be HTTP 503 with body `{"error": "Integration unavailable", "integration": "deck", "details": "..."}`

#### Scenario: External auth expired returns 401 with reconnect hint

- **GIVEN** an external provider `openproject` whose OpenConnector source has expired OAuth tokens
- **WHEN** `POST /api/objects/.../openproject` is called
- **THEN** `OpenProjectProvider::create()` MUST throw `ProviderAuthException`
- **AND** the response MUST be HTTP 401 with body including a `reconnectUrl` field

#### Scenario: Unknown integration id returns 404

- **GIVEN** a request targets integration id `foobar` that is not registered
- **WHEN** `GET /api/objects/.../foobar` is called
- **THEN** the response MUST be HTTP 404 with body `{"error": "Unknown integration", "integration": "foobar"}`

---

### Requirement: Pagination on List Endpoints

List operations (`IntegrationProvider::list()` and `GET /api/objects/.../{integrationId}`) SHALL support pagination via `limit` and `offset` query parameters. Default limit SHALL be 20; maximum limit SHALL be 100. Responses SHALL include `total` (the unfiltered count) and `hasMore` (boolean convenience flag).

#### Scenario: Default pagination

- **GIVEN** an object with 150 linked emails
- **WHEN** `GET /api/objects/{register}/{schema}/{id}/email` is called without pagination params
- **THEN** the response MUST include the first 20 emails
- **AND** MUST include `{total: 150, limit: 20, offset: 0, hasMore: true}`

#### Scenario: Explicit limit capped at 100

- **GIVEN** a caller requests `?limit=500`
- **WHEN** the list endpoint executes
- **THEN** the response MUST return at most 100 rows
- **AND** `limit` in the response metadata MUST equal `100`

---

### Requirement: Migration of Existing Schemas

A one-time data migration SHALL populate `configuration.linkedTypes` on every schema where the field is currently absent, setting it to `["files", "notes", "tasks", "tags", "audit-trail"]` — the five historically-hardcoded built-ins. This preserves user-visible behavior for existing deployments after the registry-driven filter is activated.

#### Rationale

Before this change, `CnObjectSidebar` showed all 5 hardcoded tabs regardless of `linkedTypes`. After, `linkedTypes` becomes the authoritative per-schema whitelist. Without the migration, every schema whose `linkedTypes` was absent (the common case) would lose all sidebar tabs on upgrade. Auto-populating preserves behavior and lets schema authors narrow the list when they want.

#### Scenario: Existing schema without linkedTypes is migrated

- **GIVEN** a schema saved before this change with no `configuration.linkedTypes`
- **WHEN** the migration runs as part of the release
- **THEN** the schema's `configuration.linkedTypes` MUST be set to `["files", "notes", "tasks", "tags", "audit-trail"]`
- **AND** the schema MUST be saved with a migration-audit entry recording the change

#### Scenario: Existing schema with linkedTypes is not touched

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes"]` already set
- **WHEN** the migration runs
- **THEN** the schema MUST NOT be modified

#### Scenario: Schema with stale integration id logs but does not fail

- **GIVEN** a schema with `linkedTypes: ["calendar"]` on an instance where the `calendar` provider is not yet registered
- **WHEN** the schema is loaded
- **THEN** validation MUST NOT reject on read
- **AND** a warning MUST be logged identifying the schema and the stale id
- **AND** the `calendar` tab MUST simply not render (stage 1 registry filter drops it)

#### Scenario: Schema with mid-rollout linkedTypes ids (umbrella deployed, leaf pending)

- **GIVEN** a schema with `linkedTypes: ["mail", "calendar"]` after the umbrella deploys
- **AND** the `mail` and `calendar` leaf providers have not yet merged (only the 5 built-ins are registered)
- **WHEN** `CnObjectSidebar` renders for an object of that schema
- **THEN** stage 1 registry filter MUST drop `mail` and `calendar` (no tabs rendered for those types)
- **AND** the schema MUST NOT be modified by the umbrella's migration (auto-population only fires when `linkedTypes` is absent — present-but-stale is left intact for the leaves to satisfy on merge)
- **AND** an admin-visible dashboard notice (or warning log per schema) MUST identify the schema and the unregistered ids so admins can audit during rollout

---

### Requirement: Backwards Compatibility

The change SHALL preserve the public API of `CnObjectSidebar` and the existing `LinkedEntityService` shape. Existing consumers (apps, scripts, docs) SHALL continue to function with zero code changes after the schema migration has run.

#### Scenario: Existing CnObjectSidebar consumer works unchanged

- **GIVEN** an app using `<CnObjectSidebar :hidden-tabs="['tasks']" object-type="case" :object-id="id" />`
- **AND** the schema migration has populated `linkedTypes` on the corresponding schema
- **WHEN** the app upgrades `@conduction/nextcloud-vue` to the version including this change
- **THEN** the sidebar MUST render with the same 5 tabs minus `tasks`
- **AND** no console warnings or errors MUST appear

#### Scenario: Existing schema with linkedTypes works unchanged

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes"]` saved before this change
- **WHEN** the schema is loaded after the change
- **THEN** `linkedTypes` validation MUST pass
- **AND** the schema MUST continue to limit integrations to the same two types

#### Scenario: Schema author can narrow the migrated defaults

- **GIVEN** a schema was migrated to `linkedTypes: ["files", "notes", "tasks", "tags", "audit-trail"]`
- **WHEN** the schema author edits the list down to `["files", "notes"]`
- **THEN** the schema MUST save without error
- **AND** subsequent renders MUST honour the narrower whitelist

