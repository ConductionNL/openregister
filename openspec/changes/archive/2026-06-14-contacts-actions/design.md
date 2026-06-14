# Design: Contacts Actions

## Approach

Implement a Nextcloud Contacts Menu provider that bridges the Contacts/CardDAV ecosystem with OpenRegister entity data. The backend consists of two PHP classes: a `ContactsMenuProvider` that implements `OCP\Contacts\ContactsMenu\IProvider` and processes contact entries, and a `ContactMatchingService` that handles entity matching with APCu caching. A new API endpoint exposes the matching logic for reuse by the `mail-sidebar` change.

The design leverages existing infrastructure:
- **Data access**: Uses `ObjectService::searchObjects()` for querying objects by property values across all registers and schemas.
- **URL resolution**: Uses `DeepLinkRegistryService::resolveUrl()` and `resolveIcon()` for consuming-app aware links and icons.
- **Metadata**: Uses `SchemaMapper` and `RegisterMapper` for schema/register names in count badges and action labels.
- **Caching**: Uses Nextcloud's `ICacheFactory` to obtain an APCu cache instance (falls back to memory cache if APCu is unavailable).

## Architecture

```
Nextcloud Contacts Menu (core UI)
    |
    v
ContactsMenuProvider (PHP, implements IProvider)
    |-- process(IEntry)        --> Extract email/name/org from contact entry
    |-- matchEntities()        --> ContactMatchingService
    |-- injectActions()        --> Action registry lookup + ILinkAction creation
    |-- injectCountBadge()     --> Summary count action (highest priority)
    |
    v
ContactMatchingService (PHP, shared service)
    |-- matchContact()         --> Combined matching (email + name + org)
    |-- matchByEmail()         --> ObjectService search with APCu cache
    |-- matchByName()          --> ObjectService search with APCu cache
    |-- matchByOrganization()  --> ObjectService search with APCu cache
    |-- invalidateCache()      --> Called from ObjectService::saveObject()
    |
    v
ContactsController (PHP, API endpoint)
    |-- match()                --> GET /api/contacts/match?email=&name=&organization=
```

## Files Affected

### New Files

- **`lib/Contacts/ContactsMenuProvider.php`** -- Main contacts menu provider class. Implements `OCP\Contacts\ContactsMenu\IProvider`. Constructor-injected with `ContactMatchingService`, `DeepLinkRegistryService`, `IURLGenerator`, `IL10N`, `LoggerInterface`. The `process(IEntry $entry)` method:
  1. Extracts email address(es) from `$entry->getEMailAddresses()`
  2. Extracts full name from `$entry->getFullName()`
  3. Extracts organization from `$entry->getProperty('ORG')` (vCard ORG field)
  4. Calls `ContactMatchingService::matchContact()` with extracted metadata
  5. If matches found: queries action registry for `context: "contact"` actions, resolves URL templates with contact placeholders, creates `ILinkAction` entries via `$entry->addAction()`
  6. Adds a count badge summary action with highest priority

- **`lib/Service/ContactMatchingService.php`** -- Shared entity matching service. Constructor-injected with `ObjectService`, `SchemaMapper`, `RegisterMapper`, `ICacheFactory`, `LoggerInterface`. Provides:
  - `matchContact(string $email, ?string $name, ?string $organization): array` -- Combined matching with deduplication
  - `matchByEmail(string $email): array` -- Primary matching by email property (case-insensitive, exact match)
  - `matchByName(string $name): array` -- Secondary matching by name properties (fuzzy, lower confidence)
  - `matchByOrganization(string $organization): array` -- Tertiary matching by organization name
  - `invalidateCache(string $email): void` -- Clears APCu cache entry for a specific email
  - `invalidateCacheForObject(array $object): void` -- Extracts email-like property values and invalidates each
  - `getRelatedObjectCounts(array $matches): array` -- Groups matched entities by schema and returns counts (e.g., `['Zaken' => 3, 'Leads' => 1]`)

- **`lib/Controller/ContactsController.php`** -- API controller for the contact matching endpoint. Extends `OCSController`. Constructor-injected with `ContactMatchingService`, `DeepLinkRegistryService`, `IRequest`, `IL10N`. Provides:
  - `match()` -- Handles `GET /api/contacts/match` with query parameters `email`, `name`, `organization`. Returns JSON with `matches`, `total`, `cached` fields.

### Modified Files

- **`lib/AppInfo/Application.php`** -- Add `$context->registerContactsMenuProvider(ContactsMenuProvider::class)` in the registration method, alongside the existing `registerSearchProvider` call. Add import for the new class.

- **`lib/Service/ObjectService.php`** -- Add a hook in `saveObject()` to call `ContactMatchingService::invalidateCacheForObject()` when an object with email-type properties is saved. This is done by checking if the saved object has properties that look like email addresses and invalidating corresponding cache entries.

- **`lib/Service/DeepLinkRegistryService.php`** -- Extend URL template resolution to support contact-specific placeholders: `{contactId}`, `{contactEmail}`, `{contactName}`, `{entityId}`. The existing `resolveUrl()` method's placeholder replacement logic is extended with a new `$contactContext` parameter that provides these values.

- **`appinfo/routes.php`** -- Add the contact matching route:
  ```php
  ['name' => 'contacts#match', 'url' => '/api/contacts/match', 'verb' => 'GET'],
  ```

- **`l10n/en.json`** / **`l10n/nl.json`** -- Add translation strings for action labels, count badges, and error messages.

## Entity Matching Strategy

### Email Matching (Highest Confidence)
Email matching is the primary identification mechanism. The service searches across all registers and schemas for objects with properties whose value matches the given email address. The search uses `ObjectService::searchObjects()` with a filter on properties that contain the email value.

**Implementation approach:**
1. Build a search filter: `{'_search': 'jan@example.nl'}` using the global search to find objects containing the email string
2. Post-filter results to confirm the email appears in a property that semantically represents an email (property name contains "email", "e-mail", "mail", or the schema property is typed as `format: email`)
3. Assign confidence score: `1.0` for exact email match

### Name Matching (Medium Confidence)
Name matching is secondary. The service searches for objects with name-like properties that match the contact's display name.

**Implementation approach:**
1. Split the display name into parts (e.g., "Jan de Vries" -> ["Jan", "de", "Vries"])
2. Search using `ObjectService::searchObjects()` with `{'_search': 'Jan de Vries'}`
3. Post-filter to confirm name parts appear in name-like properties (property name contains "naam", "name", "voornaam", "achternaam", "firstName", "lastName")
4. Assign confidence score: `0.7` for full name match, `0.4` for partial match

### Organization Matching (Lowest Confidence)
Organization matching identifies related organization entities.

**Implementation approach:**
1. Search using `ObjectService::searchObjects()` with `{'_search': 'Gemeente Tilburg'}`
2. Post-filter to confirm the value appears in organization-like properties (property name contains "organisatie", "organization", "bedrijf", "company", "naam")
3. Only match objects in schemas that are semantically "organization" schemas (heuristic: schema name contains "organisat", "company", "bedrijf")
4. Assign confidence score: `0.5` for exact organization name match

### Deduplication
When combining results from email, name, and organization matching, entities are deduplicated by object UUID. The highest confidence match type is retained.

## APCu Cache Design

```
Cache key format:  "or_contact_match_email_{sha256(lowercase(email))}"
Cache key format:  "or_contact_match_name_{sha256(lowercase(name))}"
Cache key format:  "or_contact_match_org_{sha256(lowercase(org))}"
TTL:               60 seconds
```

The cache stores serialized match result arrays. Cache is obtained via `ICacheFactory::createDistributed('openregister_contacts')`, which uses APCu if available or falls back to Nextcloud's default cache backend.

**Cache invalidation** happens in two ways:
1. **TTL expiry**: After 60 seconds, entries are automatically evicted.
2. **Active invalidation**: When `ObjectService::saveObject()` processes an object, if the object has email-like properties, the corresponding cache entries are invalidated via `ContactMatchingService::invalidateCacheForObject()`.

## Action Injection Flow

```
1. ContactsMenuProvider::process(IEntry $entry)
2.   -> Extract email, name, org from $entry
3.   -> ContactMatchingService::matchContact(email, name, org)
4.   -> If matches found:
5.     a. Get actions from action registry with context: "contact"
6.     b. For each action + each matched entity:
7.        - Resolve URL template placeholders:
8.          {contactId}    -> $entry->getProperty('UID')
9.          {contactEmail} -> urlencode($email)
10.         {contactName}  -> urlencode($name)
11.         {entityId}     -> $match['uuid']
12.       - Create ILinkAction:
13.         ->setName($action['label'] . ' (' . $match['title'] . ')')
14.         ->setHref($resolvedUrl)
15.         ->setIcon($action['icon'] ?? $deepLinkIcon)
16.         ->setPriority(10)
17.       - $entry->addAction($action)
18.     c. Add count badge action (priority 0, renders first):
19.        ->setName("3 zaken, 1 lead, 5 documenten")
20.        ->setHref(openregister search URL filtered by email)
21.        ->setIcon(openregister app icon)
22.        ->setPriority(0)
23.   -> If no action registry actions found but matches exist:
24.       - Add default "View in OpenRegister" action per matched entity
```

## Action Registry Integration

The contacts-actions feature depends on the `action-registry` change to provide registered actions. Until the action registry is implemented, the provider SHALL:
1. Check if the action registry service class exists (via DI container)
2. If available: query for actions with `context: "contact"`
3. If not available: fall back to adding only the default "View in OpenRegister" / "Bekijk in OpenRegister" action for each matched entity

This graceful degradation ensures the contacts menu integration works even before the action registry is fully implemented.

## API Response Format

```json
{
  "matches": [
    {
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "register": {"id": 5, "title": "Gemeente"},
      "schema": {"id": 12, "title": "Medewerkers"},
      "title": "Jan de Vries",
      "matchType": "email",
      "confidence": 1.0,
      "properties": {
        "email": "jan@example.nl",
        "functie": "Beleidsmedewerker"
      },
      "url": "/apps/procest/#/medewerkers/550e8400-e29b-41d4-a716-446655440000",
      "icon": "/apps/procest/img/app-dark.svg"
    }
  ],
  "total": 1,
  "cached": true
}
```

## Error Handling

- `ContactsMenuProvider::process()` catches all exceptions and logs them at warning level. The contacts menu SHALL never break due to OpenRegister errors.
- `ContactMatchingService` catches database exceptions and returns empty results. Cache failures (APCu unavailable) are logged and the service falls back to uncached operation.
- `ContactsController::match()` returns appropriate HTTP status codes: 200 (success), 400 (missing parameters), 401 (unauthenticated), 500 (internal error).
- Missing or uninstalled Contacts app: The provider is registered regardless; if Nextcloud never calls it (no contacts available), there is no impact.

## Performance Considerations

- **200ms budget**: The contacts menu popup is rendered synchronously. The provider MUST complete within 200ms. APCu caching ensures repeat lookups are under 10ms. First-time lookups rely on `ObjectService::searchObjects()` which uses indexed queries.
- **Lazy service loading**: `ContactMatchingService` is only instantiated when `process()` is called, not on every page load. Nextcloud's DI container handles lazy instantiation.
- **Minimal data transfer**: The provider extracts only essential fields (email, name, org) from the contact entry and returns only action links. No large data payloads.
- **Cache warming**: No proactive cache warming. The cache is populated on first access per email address.
- **Parallel matching**: Email, name, and organization matching could be parallelized in the future, but the initial implementation runs them sequentially (email first, skip name/org matching if email yields high-confidence results).

## Security Considerations

- **RBAC**: The `ContactMatchingService` respects OpenRegister's authorization model. Only objects the current user has permission to view are returned as matches.
- **No data leakage**: If a contact matches an object the user cannot access, the match is excluded from results.
- **API authentication**: The `/api/contacts/match` endpoint requires Nextcloud session authentication. No public access.
- **Input validation**: Email addresses are validated for format before being used in queries. Name and organization strings are sanitized (max 255 chars, no SQL injection risk via ORM).
