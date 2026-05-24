---
retrofit_extensions:
  - REQ-010
  - REQ-011
  - REQ-012
  - REQ-013
  - REQ-014
---
# contacts-actions Specification (delta)

This delta extends the existing `contacts-actions` capability with five new REQs describing observed behavior of the per-object contact-link CRUD, reverse lookup, match-API enrichment, integration-registry adapter, and the frontend tab. Drafted retroactively from code — see ghost change `retrofit-2026-05-24-contacts-actions`.

## Requirements

### REQ-010: The system SHALL provide per-object contact-link CRUD via dual storage (link table + vCard custom properties)

OpenRegister objects may be linked to existing Nextcloud vCard contacts or to newly created contacts via the `ContactsController` REST surface backed by `ContactService`. Each link is materialised in **two places** so the relationship survives deletion of either side independently:

1. A row in `openregister_contact_links` (`ContactLink` entity), carrying `objectUuid`, `registerId`, `contactUid`, `addressbookId`, `contactUri`, cached `displayName` / `email`, an optional `role`, plus the `linkedBy` user UID and `linkedAt` timestamp.
2. Two custom properties on the vCard itself: `X-OPENREGISTER-OBJECT` (the object UUID) and, when set, `X-OPENREGISTER-ROLE` (the role label).

The controller exposes four endpoints under `/api/objects/{register}/{schema}/{id}/contacts` (annotated `@NoAdminRequired` / `@NoCSRFRequired`):

- `GET ` — `ContactsController::index` lists links for the object. Each row carries the canonical `ContactLink::jsonSerialize()` payload enriched best-effort with `phone`, `org`, and `avatarUrl` resolved from the underlying vCard via the CardDAV backend.
- `POST ` — `ContactsController::create` either links an existing contact (payload carries `addressbookId` + `contactUri`) or creates a new contact and links it (payload carries `fullName`, optional `email`, `phone`, `role`). The controller picks the path based on which fields are present and returns HTTP 400 if neither shape is satisfied.
- `PUT /{contactUid}` — `ContactsController::update` is currently a stub: it validates the object and then returns HTTP 501 with the message `"Role update not yet supported. Unlink and relink with the new role."`. See REQ-010 notes.
- `DELETE /{contactUid}` — `ContactsController::destroy` removes the link.

All four endpoints first call `ContactsController::validateObject()` which sets the schema/register/object on `ObjectService` and returns the resolved `ObjectEntity`. A null result produces HTTP 404 `Object not found`.

The supporting service methods on `ContactService`:

- `linkContact(objectUuid, registerId, addressbookId, contactUri, role?)` — verifies the vCard exists, parses `UID`, `FN`, `EMAIL` from it, adds the `X-OPENREGISTER-*` properties, persists the updated card via `CardDavBackend::updateCard()`, and inserts a `ContactLink` row.
- `createAndLinkContact(objectUuid, registerId, data)` — generates a random 32-char UID, builds a vCard 3.0 with `BEGIN/VERSION/UID/FN/EMAIL?/TEL?/X-OPENREGISTER-OBJECT/X-OPENREGISTER-ROLE?/END`, writes it to the user's first addressbook via `CardDavBackend::createCard()`, and inserts the link row.
- `updateRole(linkId, role)` — looks up the link, attempts to rewrite the vCard's `X-OPENREGISTER-ROLE` (failures are logged and swallowed), and persists the new role on the link row.
- `unlinkContact(linkId)` — looks up the link by integer id, best-effort removes the `X-OPENREGISTER-*` vCard properties (any `Throwable` is caught and logged at warning level so orphan-row recovery still works when the vCard is gone), then deletes the link row.
- `deleteLinksForObject(objectUuid)` — iterates all links for an object, performs the same best-effort vCard cleanup per link, then bulk-deletes via `ContactLinkMapper::deleteByObjectUuid()`.
- `findUserAddressbook()` — returns the first addressbook from `CardDavBackend::getAddressBooksForUser('principals/users/{uid}')`, or `null` if the user has none.

#### Scenario: List contacts for an object

- **GIVEN** an OpenRegister object `obj-uuid-1` with two linked vCard contacts
- **WHEN** a `GET /api/objects/register-slug/schema-slug/obj-uuid-1/contacts` is made
- **THEN** the response SHALL be HTTP 200 with body `{ "results": [...], "total": 2 }`
- **AND** each entry SHALL carry the link-row fields plus `phone`, `org`, and `avatarUrl` resolved from the vCard (with `null` values when the vCard or any of those fields is unavailable)

#### Scenario: Link an existing contact

- **GIVEN** an existing vCard at `(addressbookId=3, contactUri='jan.vcf')`
- **WHEN** a `POST /api/objects/.../contacts` is made with body `{ "addressbookId": 3, "contactUri": "jan.vcf", "role": "applicant" }`
- **THEN** `ContactService::linkContact()` SHALL add `X-OPENREGISTER-OBJECT` and `X-OPENREGISTER-ROLE` to the vCard
- **AND** a new `ContactLink` row SHALL be inserted with `linkedBy` set to the current user UID and `linkedAt` set to "now"
- **AND** the response SHALL be HTTP 201 with the serialised link

#### Scenario: Create a new contact and link it

- **GIVEN** an authenticated user with at least one addressbook
- **WHEN** a `POST /api/objects/.../contacts` is made with body `{ "fullName": "Jan de Vries", "email": "jan@example.nl", "role": "handler" }`
- **THEN** `ContactService::createAndLinkContact()` SHALL generate a random hexadecimal UID, build a minimal vCard 3.0 string, and write it to the user's first addressbook
- **AND** a `ContactLink` row SHALL be inserted with the cached `displayName` and `email`
- **AND** the response SHALL be HTTP 201

#### Scenario: Neither link-data nor create-data supplied

- **GIVEN** an authenticated user
- **WHEN** a `POST /api/objects/.../contacts` is made with body `{ "role": "advisor" }`
- **THEN** the controller SHALL return HTTP 400 with body `{ "error": "Either addressbookId+contactUri or fullName is required" }`

#### Scenario: Object not found

- **GIVEN** a request with a register/schema/id triple that resolves to no object
- **WHEN** any of `index`, `create`, `update`, or `destroy` is invoked
- **THEN** the response SHALL be HTTP 404 with body `{ "error": "Object not found" }`

#### Scenario: Cleanup links when an object is removed

- **GIVEN** an object with multiple linked contacts
- **WHEN** `ContactService::deleteLinksForObject(objectUuid)` is called
- **THEN** each link's `X-OPENREGISTER-OBJECT` and `X-OPENREGISTER-ROLE` vCard properties SHALL be removed best-effort (CardDAV exceptions are logged and swallowed)
- **AND** all link rows for that object SHALL be deleted via `ContactLinkMapper::deleteByObjectUuid()`

**Notes**:
- `ContactsController::update()` returns HTTP 501 even though `ContactService::updateRole()` is fully implemented. The controller's body comment says "Role updates are not yet supported with the generic metadata column approach. Unlink and relink with the new role as a workaround." The spec describes the observed 501 — the wired-but-unused service path is left undocumented at controller level.
- `ContactsController::destroy()` passes **two strings** (`$object->getUuid()`, `$contactUid`) to `ContactService::unlinkContact()`, which has signature `unlinkContact(int $linkId): void`. This is a confirmed runtime type error on every DELETE call. The spec describes the **intent** (remove a link) rather than the broken wiring — fixing the bug is out of scope for this retrofit; an issue SHOULD be filed.
- `createAndLinkContact` writes to `$addressbook['id']` from `findUserAddressbook()`. There is no per-user preference for *which* addressbook — always the first one returned by CardDAV, which may be non-deterministic if the user has multiple.

### REQ-011: The system SHALL provide reverse lookup of OpenRegister objects linked to a contact

A contact-centric endpoint returns every OpenRegister object linked to a given vCard contact UID, enabling consuming surfaces (e.g. the contacts-menu provider, future reverse-lookup flyouts) to list "what is this contact involved in" without iterating object-by-object.

Endpoint: `GET /api/contacts/{contactUid}/objects` (`ContactsController::objects`, `@NoAdminRequired`, `@NoCSRFRequired`).

Backed by `ContactService::getObjectsForContact(contactUid)` which delegates to `ContactLinkMapper::findByContactUid()` and maps each row through `ContactLink::jsonSerialize()`.

#### Scenario: Reverse-lookup happy path

- **GIVEN** a vCard with UID `ABCD-1234` linked to three OpenRegister objects across different schemas
- **WHEN** a `GET /api/contacts/ABCD-1234/objects` is made
- **THEN** the response SHALL be HTTP 200 with body `{ "results": [...3 link rows...], "total": 3 }`

#### Scenario: Contact has no linked objects

- **GIVEN** a vCard UID with no `openregister_contact_links` rows
- **WHEN** the endpoint is called
- **THEN** the response SHALL be HTTP 200 with body `{ "results": [], "total": 0 }`

#### Scenario: Service-level exception

- **GIVEN** the underlying mapper throws an unexpected `Exception`
- **WHEN** the endpoint catches it
- **THEN** the response SHALL be HTTP 500 with body `{ "error": "<exception message>" }`

**Notes**: The reverse lookup returns raw link rows (no vCard enrichment, no object enrichment). Consuming surfaces are expected to resolve the object titles / register-schema metadata themselves when needed.

### REQ-012: The system SHALL enrich contact-match results with deep-link URLs and icons

When a contact is matched against OpenRegister entities through `/api/contacts/match`, the controller MUST enrich each raw match with a navigation URL and an icon path resolved via `DeepLinkRegistryService`. This makes the match payload directly consumable by frontend UIs (contacts-menu popup, mail-sidebar, reverse-lookup flyouts) without a follow-up resolver call.

The matching surface itself (`ContactsController::match`) is already specified by an existing requirement in this spec (it covers `?email=…&name=…&organization=…` and the underlying `ContactMatchingService::matchContact()`). This REQ specifies only the **enrichment layer** added on top of the raw match results.

`ContactsController::enrichMatches(array $matches): array` SHALL, for each match:

1. Extract `register.id` and `schema.id` (defaulting to `0` if absent).
2. Set `match['url']` to `DeepLinkRegistryService::resolveUrl($registerId, $schemaId, $match)` — typically the consuming app's route (e.g. Procest's `/apps/procest/#/zaken/{uuid}`) when a deep link is registered, falling back to the generic OpenRegister object detail view otherwise.
3. Set `match['icon']` to `DeepLinkRegistryService::resolveIcon($registerId, $schemaId)` — the consuming app's icon when a deep link is registered, falling back to OpenRegister's app icon otherwise.

The enriched payload is wrapped as `{ "matches": [...], "total": <count> }` and returned with HTTP 200.

Failure modes:

- If `email` and `name` are both empty, the controller short-circuits with HTTP 400 `{ "error": "At least email or name must be provided", "matches": [], "total": 0 }`.
- If `ContactMatchingService::matchContact()` throws, the controller logs `'[ContactsAPI] Match failed: {error}'` at error level and returns HTTP 500 `{ "error": "Internal server error", "matches": [], "total": 0 }`.

The supporting helper `ContactMatchingService::getRelatedObjectCounts(matches)` groups matches by `schema.title` and returns `{ "<schemaTitle>": <count>, ... }`, intended for the count-badge use case in the contacts-menu popup. Unknown schema titles bucket under `"Unknown"`.

#### Scenario: Match-API call returns enriched results

- **GIVEN** an active deep link registration mapping schema `Zaken` to Procest
- **AND** a match for `email=jan@example.nl` returns one raw object in schema `Zaken`
- **WHEN** `GET /api/contacts/match?email=jan%40example.nl` is called
- **THEN** the response body SHALL be `{ "matches": [{...raw match..., "url": "/apps/procest/#/zaken/<uuid>", "icon": "<procest-icon-path>"}], "total": 1 }`

#### Scenario: No deep link → fallback URL and icon

- **GIVEN** a matched object in a schema with no deep-link registration
- **WHEN** the match is enriched
- **THEN** `match.url` SHALL be the generic OpenRegister detail-view URL
- **AND** `match.icon` SHALL be OpenRegister's default app icon path

#### Scenario: Missing both `email` and `name` query params

- **GIVEN** a request `GET /api/contacts/match?organization=Acme`
- **WHEN** the controller checks the inputs
- **THEN** the response SHALL be HTTP 400 with body `{ "error": "At least email or name must be provided", "matches": [], "total": 0 }`

#### Scenario: Internal matching failure

- **GIVEN** `ContactMatchingService::matchContact()` throws `Exception` mid-request
- **WHEN** the controller catches it
- **THEN** the response SHALL be HTTP 500 with body `{ "error": "Internal server error", "matches": [], "total": 0 }`
- **AND** the error SHALL be logged with the structured key `[ContactsAPI] Match failed: {error}`

**Notes**:
- `enrichMatches` is private — the only caller is `match()`. Future consumers SHOULD route through the controller endpoint, not call the service directly, to receive enriched payloads.
- The match-API short-circuit treats `organization` alone as insufficient input, even though `ContactMatchingService::matchContact()` accepts a non-null `organization`. This is observed behavior — flagged as a possible future relaxation but not changed here.

### REQ-013: The system SHALL expose contacts via the generic-integrations IntegrationProvider contract

A `ContactsProvider` (extending `AbstractIntegrationProvider`) adapts `ContactService` to the generic-integrations registry so any consuming surface (UI tabs, dashboards, MCP tools, future agents) can list/update/delete contact links uniformly across all configured integrations without coupling to `ContactsController`.

Provider metadata:

- `getId()` → `"contacts"`
- `getLabel()` → translated string `"Contacts"`
- `getIcon()` → `"AccountBox"` (Material Design Icon name)
- `getGroup()` → `"comms"`
- `getRequiredApp()` → `"contacts"` (the NC Contacts app)
- `getStorageStrategy()` → `"link-table"` (matching `openregister_contact_links` semantics)
- `isEnabled()` → `IAppManager::isInstalled('contacts')`

Provider operations:

- `list(register, schema, objectId, filters=[])` — delegates to `ContactService::getContactsForObject($objectId)` and returns `$result['results']` directly. Any `Throwable` from the service is caught and the method returns `[]` so the registry tab degrades gracefully (per AD-23 of the integration architecture). The `register`, `schema`, and `filters` parameters are accepted to satisfy the interface contract but are unused — CardDAV scope is per-user, not per-register.
- `update(register, schema, objectId, entityId, payload)` — only the role can be updated through the registry path. The provider calls `ContactService::updateRole(linkId: (int)$entityId, role: (string)($payload['role'] ?? ''))` and returns `$link->jsonSerialize()`. The vCard itself is owned by NC Contacts; richer updates are intentionally out of scope here.
- `delete(register, schema, objectId, entityId)` — calls `ContactService::unlinkContact(linkId: (int)$entityId)`. Because the underlying service is idempotent (tolerates a missing vCard), this path also recovers orphan link rows when a user has deleted the contact via NC Contacts.
- `health()` — returns `{ "status": "ok"|"unavailable", "authStatus": "configured", "message": null|"NC Contacts app is not installed" }` depending on whether NC Contacts is installed.

The provider intentionally does **not** implement `create()` — the consuming UI has two distinct flows (link existing vs. create new) which the dedicated `ContactsController::create` routes, and the registry's generic create() would lose that distinction.

#### Scenario: List linked contacts through the registry surface

- **GIVEN** a registered `ContactsProvider` and an object with two linked contacts
- **WHEN** the integration registry invokes `provider.list('any-register', 'any-schema', 'obj-uuid-1')`
- **THEN** the provider SHALL return the enriched results array from `ContactService::getContactsForObject('obj-uuid-1')`

#### Scenario: Service failure degrades to empty list

- **GIVEN** `ContactService::getContactsForObject()` throws (e.g. CardDAV backend unavailable)
- **WHEN** the provider's `list()` catches the `Throwable`
- **THEN** the provider SHALL return `[]` so the integration tab renders an empty state instead of crashing

#### Scenario: Update role through the registry surface

- **GIVEN** an existing contact link with id `42`
- **WHEN** the registry invokes `provider.update('r', 's', 'o', '42', ['role' => 'handler'])`
- **THEN** the provider SHALL call `ContactService::updateRole(linkId: 42, role: 'handler')` and return the link's JSON representation

#### Scenario: Delete via the registry surface is idempotent

- **GIVEN** a link row with id `42` whose vCard has been removed externally
- **WHEN** the registry invokes `provider.delete('r', 's', 'o', '42')`
- **THEN** the provider SHALL still drop the link row (the underlying service swallows the missing-card error and logs a warning)

#### Scenario: Health check reflects NC Contacts availability

- **GIVEN** NC Contacts is installed
- **WHEN** `provider.health()` is called
- **THEN** the response SHALL be `{ "status": "ok", "authStatus": "configured", "message": null }`
- **GIVEN** NC Contacts is not installed
- **WHEN** `provider.health()` is called
- **THEN** the response SHALL be `{ "status": "unavailable", "authStatus": "configured", "message": "NC Contacts app is not installed" }`

**Notes**:
- The provider's `update()` accepts an empty string as a role (no validation). This is intentional to mirror the registry contract; role allow-listing belongs to the controller / consumer.
- `authStatus` is hard-coded to `'configured'`. There is no separate OAuth or token check — contact access piggybacks on the Nextcloud session, so "installed" implies "authorised" at provider level.

### REQ-014: The frontend SHALL render a ContactsTab with graceful degradation when the Contacts app is missing

A Vue component (`src/components/object-relations/ContactsTab.vue`) renders linked contacts for an OpenRegister object in the detail view, backed by a Pinia store (`src/store/modules/object-relations/contacts.js`) that wraps the per-object endpoints registered under `appinfo/routes.php` as `contacts#…`.

Store responsibilities (`useContactRelationsStore`):

- State keyed by `${register}:${schema}:${id}`: `byObject`, `loading`, `errors`, plus a global `contactsUnavailable` flag.
- `fetch(register, schema, id)` — `GET`s the index endpoint, normalises the response to an array (accepts either `{results: [...]}` shape or a flat list), and stores it under the composite key. When the server returns HTTP 501, sets `contactsUnavailable=true`, stores an empty list, and resolves with `[]` — this is the graceful-degradation path for environments without NC Contacts.
- `createOrLink(register, schema, id, payload)` — `POST`s the payload and re-fetches the list.
- `unlink(register, schema, id, contactUid)` — `DELETE`s `/{contactUid}` then optimistically prunes the local list by matching on `c.uid || c.contactUid || c.id`.
- `get(register, schema, id)` — synchronous read of the cached list.

Tab responsibilities (`ContactsTab` component, props: `register`, `schema`, `objectId`):

- Calls `store.fetch(...)` immediately and on `objectId` change.
- Renders one of four states: loading icon, error empty-state, "Contacts integration is not available" empty-state (when `store.contactsUnavailable` is true), or the list of contacts.
- Each list item shows `fullName || displayName || email || '(unnamed)'`, the email, and the role (when present).
- "Add contact" toolbar button is hidden when `loading` or `contactsUnavailable`; clicking it emits `add-contact` to the parent (which owns the dialog).
- The unlink button per row calls `store.unlink(...)` and emits `contacts-changed` with the new list length on success.

#### Scenario: Fetch and render contacts

- **GIVEN** an object `obj-1` with two linked contacts
- **WHEN** `ContactsTab` mounts with `objectId='obj-1'`
- **THEN** the store SHALL call `GET /api/objects/{register}/{schema}/obj-1/contacts`
- **AND** the tab SHALL render two list items with name, email, and role

#### Scenario: Contacts app missing → graceful empty state

- **GIVEN** an environment where NC Contacts is not installed (server returns HTTP 501)
- **WHEN** the store's `fetch()` catches the 501
- **THEN** `contactsUnavailable` SHALL be set to `true`
- **AND** the tab SHALL render an `NcEmptyContent` with the message `"The Nextcloud Contacts app is not installed or enabled on this server."`
- **AND** the "Add contact" toolbar SHALL be hidden

#### Scenario: Unlink optimistic update

- **GIVEN** a contact list with three items and a contact with `uid="ABC"`
- **WHEN** the user clicks the unlink button on the `ABC` row
- **THEN** the store SHALL `DELETE /…/contacts/ABC` and, on success, prune the local list to two items
- **AND** the tab SHALL emit `contacts-changed` with the value `2`

#### Scenario: Network error during fetch

- **GIVEN** a non-501 network error from the index endpoint
- **WHEN** the store catches it
- **THEN** the error message SHALL be stored under `errors[k]` and re-thrown
- **AND** the tab's `fetchContacts()` SHALL set `error=true` and surface the error message in an empty-state

**Notes**:
- The store's spec-link comment points to `openspec/changes/nextcloud-entity-relations/specs/contact-relations/spec.md` (a different change). Once this retrofit lands, the canonical spec for these endpoints is the merged `contacts-actions/spec.md` and the comment SHOULD be updated — left out of scope here.
- The unlink path uses `contactUid` as the URL segment, but the backend `destroy` endpoint passes that value into `ContactService::unlinkContact(int $linkId)` which expects a numeric link id. The store's wire path is therefore broken end-to-end today (see REQ-010 notes). The frontend spec describes the intended behavior; the bug is flagged for separate fix.
- Avatars are not currently rendered in this tab (the `avatarUrl` enrichment from the service is unused at this surface). The single-entity widget surfaces it instead — that surface is specified elsewhere.
