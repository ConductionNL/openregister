# Tasks: Contacts Actions

## ContactMatchingService (Shared Service)

- [x] Create `lib/Service/ContactMatchingService.php` with constructor injection of `ObjectService`, `SchemaMapper`, `RegisterMapper`, `ICacheFactory`, `LoggerInterface`; initialize distributed cache via `$cacheFactory->createDistributed('openregister_contacts')` in constructor
- [x] Implement `matchByEmail(string $email): array` that searches across all registers and schemas for objects containing the given email address using `ObjectService::searchObjects()` with `{'_search': $email}`, post-filters results to confirm the email appears in email-like properties (property name containing "email", "e-mail", "mail"), and assigns confidence `1.0`
- [x] Implement APCu caching in `matchByEmail()`: check cache key `or_contact_match_email_{sha256(strtolower($email))}` before querying; store results with TTL 60 seconds; return cached results with `cached: true` flag
- [x] Implement `matchByName(?string $name): array` that splits the display name into parts, searches via `ObjectService::searchObjects()` with `{'_search': $name}`, post-filters to confirm name parts appear in name-like properties (naam, name, voornaam, achternaam, firstName, lastName), and assigns confidence `0.7` for full match or `0.4` for partial match; cache with key `or_contact_match_name_{sha256}`
- [x] Implement `matchByOrganization(?string $organization): array` that searches for organization-type objects via `ObjectService::searchObjects()`, post-filters on organization-like properties (organisatie, organization, bedrijf, company, naam) in organization-typed schemas, and assigns confidence `0.5`; cache with key `or_contact_match_org_{sha256}`
- [x] Implement `matchContact(string $email, ?string $name = null, ?string $organization = null): array` that calls `matchByEmail()` first, then `matchByName()` and `matchByOrganization()` if provided, deduplicates results by object UUID keeping the highest confidence match, and returns the combined sorted array
- [x] Implement `getRelatedObjectCounts(array $matches): array` that groups matched entities by schema title and returns an associative array of counts (e.g., `['Zaken' => 3, 'Leads' => 1, 'Documenten' => 5]`)
- [x] Implement `invalidateCache(string $email): void` that deletes the APCu cache entry for the given email address; also implement `invalidateCacheForObject(array $object): void` that extracts email-like property values from the object and invalidates each

## ContactsMenuProvider

- [x] Create `lib/Contacts/ContactsMenuProvider.php` implementing `OCP\Contacts\ContactsMenu\IProvider` with constructor injection of `ContactMatchingService`, `DeepLinkRegistryService`, `IURLGenerator`, `IL10N`, `LoggerInterface`
- [x] Implement `process(IEntry $entry): void` that extracts email addresses via `$entry->getEMailAddresses()`, full name via `$entry->getFullName()`, and organization via `$entry->getProperty('ORG')`; calls `ContactMatchingService::matchContact()` with the primary email and optional name/organization
- [x] When matches are found, query the action registry (if available via DI) for actions with `context: "contact"`; for each action and each matched entity, resolve the URL template by replacing `{contactId}`, `{contactEmail}`, `{contactName}`, `{entityId}` placeholders with URL-encoded values; create an `ILinkAction` via `$entry->addAction()` with the resolved URL, label (including entity title for disambiguation), icon, and priority `10`
- [x] When no action registry is available (graceful degradation), add a default `ILinkAction` per matched entity with label `$this->l10n->t('View in OpenRegister')`, href pointing to the deep-linked URL via `DeepLinkRegistryService::resolveUrl()` or fallback to OpenRegister's object detail route, and the app icon
- [x] Implement count badge injection: call `ContactMatchingService::getRelatedObjectCounts()`, format the counts as a human-readable string (e.g., "3 zaken, 1 lead, 5 documenten" using `IL10N::t()` with pluralization), create an `ILinkAction` with priority `0` (highest) linking to OpenRegister search filtered by the contact's email
- [x] Wrap the entire `process()` method body in a try-catch that logs exceptions at warning level and returns silently, ensuring the contacts menu never breaks due to OpenRegister errors

## Registration and Cache Invalidation

- [x] Register the provider in `Application::register()` via `$context->registerContactsMenuProvider(ContactsMenuProvider::class)` in the same method that calls `registerSearchProvider`, adding the necessary import statement for the new class
- [x] Add `ContactMatchingService` cache invalidation call in `ObjectService::saveObject()`: after successful persistence, check if the saved object has email-like property values, and if so call `ContactMatchingService::invalidateCacheForObject($objectArray)` to bust stale cache entries

## API Endpoint

- [x] Create `lib/Controller/ContactsController.php` extending `OCSController` with constructor injection of `ContactMatchingService`, `DeepLinkRegistryService`, `IRequest`, `IL10N`; implement `match()` method that reads `email`, `name`, `organization` query parameters, validates that at least `email` or `name` is provided (return 400 if neither), calls `ContactMatchingService::matchContact()`, and returns a `DataResponse` with `matches`, `total`, `cached` fields
- [x] Add route to `appinfo/routes.php`: `['name' => 'contacts#match', 'url' => '/api/contacts/match', 'verb' => 'GET']` positioned before any wildcard routes to avoid route conflicts
- [x] Enrich each match in the API response with `url` and `icon` fields by calling `DeepLinkRegistryService::resolveUrl()` and `resolveIcon()` for each matched entity

## DeepLinkRegistryService Extension

- [x] Extend `DeepLinkRegistryService::resolveUrl()` to accept an optional `array $contactContext = []` parameter; when provided, resolve additional placeholders `{contactId}`, `{contactEmail}`, `{contactName}` from the context array alongside existing object placeholders like `{uuid}`
- [x] Ensure placeholder replacement is applied after the existing object-level placeholder resolution, so both object and contact placeholders can coexist in the same URL template

## Translations

- [x] Add English translation strings to `l10n/en.json`: "View in OpenRegister", "No matching entities found", "Contact matching", "%n case" / "%n cases" (plural), "%n lead" / "%n leads", "%n document" / "%n documents", "Match by email", "Match by name", "Match by organization"
- [x] Add Dutch translation strings to `l10n/nl.json`: "Bekijk in OpenRegister", "Geen gekoppelde entiteiten gevonden", "Contact koppeling", "%n zaak" / "%n zaken", "%n lead" / "%n leads", "%n document" / "%n documenten", "Koppeling via e-mail", "Koppeling via naam", "Koppeling via organisatie"

## Testing

- [x] Write unit tests for `ContactMatchingService::matchByEmail()` covering: exact email match returns results with confidence `1.0`, case-insensitive matching, no match returns empty array, cached results are returned without DB query (mock `ICacheFactory`), cache invalidation clears the entry
- [x] Write unit tests for `ContactMatchingService::matchByName()` covering: full name match returns confidence `0.7`, partial name match returns `0.4`, no match returns empty array
- [x] Write unit tests for `ContactMatchingService::matchByOrganization()` covering: exact organization match, no match, results filtered to organization-typed schemas only
- [x] Write unit tests for `ContactMatchingService::matchContact()` covering: combined matching with deduplication (same object matched by email and name keeps email confidence), empty email with name-only matching, all three parameters provided
- [x] Write unit tests for `ContactsMenuProvider::process()` covering: matched contact gets actions and count badge added, unmatched contact gets no actions, exception in matching service is caught and logged, action registry unavailable falls back to default action
- [x] Write unit tests for `ContactsController::match()` covering: successful match returns 200 with correct JSON structure, missing parameters returns 400, authentication required returns 401
- [x] Write unit tests for URL template placeholder resolution covering: `{contactEmail}` is URL-encoded, `{contactName}` is URL-encoded, `{entityId}` is replaced with UUID, `{contactId}` is replaced with vCard UID, missing placeholder values are left as-is
- [x] Manual test: verify clicking a contact name in Nextcloud's top-bar contacts menu shows OpenRegister actions when the contact's email matches an object
- [x] Manual test: verify the count badge shows correct counts grouped by schema type
- [x] Manual test: verify the API endpoint `GET /api/contacts/match?email=...` returns correct matches with cache hit/miss indicator
- [x] Manual test: verify performance -- contacts menu popup renders within 200ms when APCu cache is warm
- [x] Manual test: verify no actions appear for contacts with no matching OpenRegister entities
- [x] Manual test: verify the provider does not break the contacts menu when OpenRegister has no data or when the action registry is not yet implemented
