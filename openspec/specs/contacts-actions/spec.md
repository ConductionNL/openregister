---
status: done
retrofit_extensions:
  - REQ-010
  - REQ-011
  - REQ-012
  - REQ-013
  - REQ-014
---
# contacts-actions Specification

## Purpose

@e2e exclude Nextcloud Contacts IContactsMenuProvider backend — covered by PHPUnit
TBD - created by archiving change contacts-actions. Update Purpose after archive.

The capability now also covers the inbound+management surface added by `retrofit-2026-05-24-contacts-actions` (REQ-010..REQ-014): per-object contact-link CRUD via dual storage, reverse lookup, match-API deep-link enrichment, the `ContactsProvider` integration-registry adapter, and the `ContactsTab` frontend tab with graceful 501 degradation.
## Requirements
### Requirement: OpenRegister MUST register a ContactsMenu provider

The app MUST implement `OCP\Contacts\ContactsMenu\IProvider` as `ContactsMenuProvider` and register it in `Application::register()` via `$context->registerContactsMenuProvider()`. The provider SHALL process contact entries, match them to OpenRegister entities, and add action links to the contacts menu popup.

#### Scenario: Provider is registered and processes contact entries
- **GIVEN** the OpenRegister app is enabled
- **WHEN** a user clicks on a contact name in Nextcloud (e.g., in the top-bar contacts menu or in the Contacts app)
- **THEN** the `ContactsMenuProvider::process()` method SHALL be called with the `IEntry` object
- **AND** the provider SHALL extract the contact's email address(es), full name, and organization from the entry
- **AND** the provider SHALL call `ContactMatchingService::matchContact()` with the extracted metadata

#### Scenario: Provider registration in Application
- **GIVEN** the `Application::register()` method in `lib/AppInfo/Application.php`
- **WHEN** the app boots
- **THEN** `$context->registerContactsMenuProvider(ContactsMenuProvider::class)` SHALL be called
- **AND** the provider SHALL be injectable via Nextcloud DI with constructor injection of `ContactMatchingService`, `DeepLinkRegistryService`, `IURLGenerator`, `IL10N`, and `LoggerInterface`

### Requirement: ContactMatchingService MUST match contacts to OpenRegister entities

A shared `ContactMatchingService` SHALL match contact metadata (email, name, organization) to OpenRegister objects across all registers and schemas. The service is the core matching engine used by both the contacts-actions provider and the mail-sidebar integration.

#### Scenario: Match by email address
- **GIVEN** a contact with email address `jan.devries@gemeente.nl`
- **AND** an OpenRegister object in schema "Medewerkers" has a property `email` with value `jan.devries@gemeente.nl`
- **WHEN** `ContactMatchingService::matchByEmail('jan.devries@gemeente.nl')` is called
- **THEN** the service SHALL search across all registers and schemas for objects with email-type properties matching the given address (case-insensitive)
- **AND** it SHALL return an array of matched objects with their register, schema, and object metadata

#### Scenario: Match by display name
- **GIVEN** a contact with display name `Jan de Vries`
- **AND** an OpenRegister object in schema "Personen" has properties `voornaam: Jan` and `achternaam: de Vries`
- **WHEN** `ContactMatchingService::matchByName('Jan de Vries')` is called
- **THEN** the service SHALL search for objects with name-type properties that fuzzy-match the given display name
- **AND** the matching SHALL be secondary to email matching (email is the primary key)

#### Scenario: Match by organization
- **GIVEN** a contact with organization field `Gemeente Tilburg`
- **AND** an OpenRegister object in schema "Organisaties" has a property `naam` with value `Gemeente Tilburg`
- **WHEN** `ContactMatchingService::matchByOrganization('Gemeente Tilburg')` is called
- **THEN** the service SHALL search for organization-type objects matching the given organization name
- **AND** the results SHALL be returned alongside person matches, tagged with match type `organization`

#### Scenario: Combined matching via matchContact
- **GIVEN** a contact entry with email `jan@example.nl`, name `Jan de Vries`, and organization `Gemeente Tilburg`
- **WHEN** `ContactMatchingService::matchContact(email: 'jan@example.nl', name: 'Jan de Vries', organization: 'Gemeente Tilburg')` is called
- **THEN** the service SHALL execute email matching first (highest confidence)
- **AND** then name matching (medium confidence)
- **AND** then organization matching (lowest confidence)
- **AND** results SHALL be deduplicated by object UUID
- **AND** each result SHALL include a `matchType` field (`email`, `name`, `organization`) and a `confidence` score

#### Scenario: No matches found
- **GIVEN** a contact with email `unknown@nowhere.test`
- **WHEN** `ContactMatchingService::matchContact()` is called
- **THEN** it SHALL return an empty array
- **AND** the contacts menu SHALL display no OpenRegister actions for this contact

### Requirement: APCu caching MUST be used for entity lookups

The `ContactMatchingService` MUST cache entity lookup results in APCu to ensure the contacts menu popup renders within the 200ms performance budget.

#### Scenario: Cache hit for repeated email lookup
- **GIVEN** a previous call to `matchByEmail('jan@example.nl')` returned 3 matches
- **AND** the cache TTL (60 seconds) has not expired
- **WHEN** `matchByEmail('jan@example.nl')` is called again
- **THEN** the service SHALL return the cached result without querying the database
- **AND** the response time SHALL be under 10ms

#### Scenario: Cache miss triggers database query
- **GIVEN** no cached result exists for `info@bedrijf.nl`
- **WHEN** `matchByEmail('info@bedrijf.nl')` is called
- **THEN** the service SHALL query OpenRegister objects via `ObjectService::searchObjects()`
- **AND** the result SHALL be stored in APCu with key prefix `or_contact_match_` and TTL 60 seconds

#### Scenario: Cache invalidation on object save
- **GIVEN** an OpenRegister object with email `jan@example.nl` is updated
- **WHEN** `ObjectService::saveObject()` completes
- **THEN** the service SHALL invalidate the APCu cache entry for `jan@example.nl`
- **AND** the next lookup SHALL fetch fresh data from the database

### Requirement: Actions MUST be injected from the action registry

The `ContactsMenuProvider` MUST query the action registry for actions with `context: "contact"` and add them as `ILinkAction` entries to the contact's menu popup. Each action SHALL resolve its URL template with contact-specific placeholders.

#### Scenario: Action links appear in contacts menu
- **GIVEN** the action registry contains an action with `context: "contact"`, `label: "Bekijk zaken"`, and `url: "/apps/procest/#/zaken?contact={contactEmail}"`
- **AND** the contact's email is `jan@example.nl`
- **WHEN** the contacts menu is rendered for this contact
- **THEN** an `ILinkAction` SHALL be added with:
  - `setName('Bekijk zaken')`
  - `setHref('/apps/procest/#/zaken?contact=jan@example.nl')`
  - `setIcon(...)` using the action's configured icon
  - `setPriority(10)`

#### Scenario: URL template placeholder resolution
- **GIVEN** an action URL template `"/apps/openregister/#/objects?email={contactEmail}&name={contactName}&entity={entityId}"`
- **AND** the contact has email `jan@example.nl`, name `Jan de Vries`, and a matched entity with UUID `550e8400-e29b-41d4-a716-446655440000`
- **WHEN** the URL template is resolved
- **THEN** the placeholders `{contactEmail}`, `{contactName}`, and `{entityId}` SHALL be replaced with URL-encoded values
- **AND** `{contactId}` SHALL resolve to the contact's UID from the vCard if available

#### Scenario: No actions registered for contact context
- **GIVEN** no actions exist in the registry with `context: "contact"`
- **WHEN** the contacts menu is rendered
- **THEN** only the entity count badge SHALL be shown (if matches exist)
- **AND** a default "View in OpenRegister" action SHALL be added linking to the matched entity's detail page

#### Scenario: Multiple matched entities produce multiple action sets
- **GIVEN** a contact matches 2 OpenRegister entities (one person, one organization)
- **AND** there are 2 actions registered for `context: "contact"`
- **WHEN** actions are injected
- **THEN** each action SHALL be resolved for each matched entity separately
- **AND** the action label SHALL include the entity context (e.g., "Bekijk zaken (Jan de Vries)" and "Bekijk zaken (Gemeente Tilburg)")

### Requirement: Entity count badges MUST be shown in the contacts menu

When a contact matches OpenRegister entities, the provider MUST add a summary action showing the count of related objects grouped by schema type.

#### Scenario: Count badge for matched contact
- **GIVEN** a contact matches entities that are related to 3 cases, 1 lead, and 5 documents across different schemas
- **WHEN** the contacts menu popup is rendered
- **THEN** an `ILinkAction` SHALL be added with a summary label like `"3 zaken, 1 lead, 5 documenten"`
- **AND** the action SHALL link to an OpenRegister search filtered by the contact's email
- **AND** the action's priority SHALL be higher than individual action links (renders first)

#### Scenario: No matches produce no badge
- **GIVEN** a contact has no matching OpenRegister entities
- **WHEN** the contacts menu popup is rendered
- **THEN** no count badge or OpenRegister actions SHALL be added
- **AND** the contacts menu SHALL render normally without OpenRegister interference

### Requirement: A REST API endpoint MUST expose contact matching

A new API endpoint SHALL provide programmatic access to the contact matching service, enabling reuse by the mail-sidebar change and external integrations.

#### Scenario: Match by email via API
- **GIVEN** an authenticated user
- **WHEN** `GET /api/contacts/match?email=jan@example.nl` is called
- **THEN** the response SHALL return HTTP 200 with a JSON body containing:
  - `matches`: array of matched entities with `uuid`, `register`, `schema`, `title`, `matchType`, `confidence`
  - `total`: total number of matches
  - `cached`: boolean indicating whether the result was served from cache

#### Scenario: Match by name and email via API
- **GIVEN** an authenticated user
- **WHEN** `GET /api/contacts/match?email=jan@example.nl&name=Jan+de+Vries` is called
- **THEN** the response SHALL combine email and name matches, deduplicated by UUID
- **AND** email matches SHALL have higher confidence than name matches

#### Scenario: Match by organization via API
- **GIVEN** an authenticated user
- **WHEN** `GET /api/contacts/match?organization=Gemeente+Tilburg` is called
- **THEN** the response SHALL return organization-type entity matches

#### Scenario: Unauthenticated request returns 401
- **GIVEN** no authentication credentials
- **WHEN** `GET /api/contacts/match?email=jan@example.nl` is called
- **THEN** the response SHALL be HTTP 401 Unauthorized

### Requirement: The provider MUST integrate with DeepLinkRegistryService for action URLs

When generating action URLs for matched entities, the provider MUST use `DeepLinkRegistryService::resolveUrl()` to determine the best URL for each entity, preferring consuming app deep links over raw OpenRegister URLs.

#### Scenario: Deep link to consuming app
- **GIVEN** a matched entity in schema "Zaken" with a deep link registered by Procest
- **WHEN** the default "View in OpenRegister" action URL is generated
- **THEN** the URL SHALL point to the Procest route (e.g., `/apps/procest/#/zaken/{uuid}`) instead of the OpenRegister generic view
- **AND** the action icon SHALL use Procest's app icon via `DeepLinkRegistryService::resolveIcon()`

#### Scenario: No deep link falls back to OpenRegister
- **GIVEN** a matched entity in a schema with no deep link registered
- **WHEN** the action URL is generated
- **THEN** the URL SHALL point to the OpenRegister object detail view
- **AND** the icon SHALL use `imagePath('openregister', 'app-dark.svg')`

### Requirement: URL template variables MUST support contact-specific placeholders

The deep link registry URL templates MUST be extended to support contact-specific placeholder variables beyond the existing object placeholders.

#### Scenario: Contact placeholders in URL templates
- **GIVEN** a deep link URL template `"/apps/crm/#/contacts/{contactEmail}/cases"`
- **WHEN** resolved for a contact with email `jan@example.nl`
- **THEN** `{contactEmail}` SHALL be replaced with `jan%40example.nl` (URL-encoded)

#### Scenario: All supported placeholders
- **GIVEN** a URL template with all contact placeholders
- **WHEN** resolved
- **THEN** the following placeholders SHALL be supported:
  - `{contactId}` -- the contact's vCard UID
  - `{contactEmail}` -- the contact's primary email address (URL-encoded)
  - `{contactName}` -- the contact's display name (URL-encoded)
  - `{entityId}` -- the matched OpenRegister entity's UUID

### Requirement: i18n MUST be applied to all user-visible strings

All user-visible strings in the `ContactsMenuProvider` and `ContactMatchingService` MUST use Nextcloud's `IL10N` translation system. Dutch and English translations MUST be provided as minimum per ADR-005.

#### Scenario: Action labels are translated
- **GIVEN** a user with Nextcloud locale set to `nl`
- **WHEN** the contacts menu shows the entity count badge
- **THEN** the label SHALL use Dutch translations (e.g., "3 zaken, 1 lead, 5 documenten")

#### Scenario: Default action label is translated
- **GIVEN** the default "View in OpenRegister" action
- **WHEN** rendered for a Dutch user
- **THEN** the label SHALL be "Bekijk in OpenRegister"

#### Scenario: API error messages are translated
- **GIVEN** a failed contact matching API call
- **WHEN** the error response is generated
- **THEN** error messages SHALL use `IL10N::t()` for translation

<!-- BEGIN retrofit-2026-05-24-contacts-actions (merged from openspec/changes/retrofit-2026-05-24-contacts-actions/specs/contacts-actions/spec.md) -->

### REQ-010: The system SHALL provide per-object contact-link CRUD via dual storage (link table + vCard custom properties)

The system SHALL provide per-object contact-link CRUD via dual storage (link table + vCard custom properties). OpenRegister objects may be linked to existing Nextcloud vCard contacts or to newly created contacts via the `ContactsController` REST surface backed by `ContactService`. Each link is materialised in **two places** so the relationship survives deletion of either side independently:

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

The system SHALL provide reverse lookup of OpenRegister objects linked to a contact. A contact-centric endpoint returns every OpenRegister object linked to a given vCard contact UID, enabling consuming surfaces (e.g. the contacts-menu provider, future reverse-lookup flyouts) to list "what is this contact involved in" without iterating object-by-object.

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

The system SHALL expose contacts via the generic-integrations IntegrationProvider contract. A `ContactsProvider` (extending `AbstractIntegrationProvider`) adapts `ContactService` to the generic-integrations registry so any consuming surface (UI tabs, dashboards, MCP tools, future agents) can list/update/delete contact links uniformly across all configured integrations without coupling to `ContactsController`.

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

The frontend SHALL render a ContactsTab with graceful degradation when the Contacts app is missing. A Vue component (`src/components/object-relations/ContactsTab.vue`) renders linked contacts for an OpenRegister object in the detail view, backed by a Pinia store (`src/store/modules/object-relations/contacts.js`) that wraps the per-object endpoints registered under `appinfo/routes.php` as `contacts#…`.

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

<!-- END retrofit-2026-05-24-contacts-actions -->
