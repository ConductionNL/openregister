# contacts-actions Specification

## Purpose
TBD - created by archiving change contacts-actions. Update Purpose after archive.
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

