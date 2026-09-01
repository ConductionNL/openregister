## Why

Contact persons in Nextcloud (from the Contacts/CardDAV app) often correspond to entities in OpenRegister (persons, organizations). When users click on a contact name anywhere in Nextcloud -- the contacts menu popup, or the Contacts app -- there is no bridge to OpenRegister data. Users cannot see what cases, leads, or documents relate to a contact, nor take actions like "Create Case for Contact" or "View Lead History" without manually switching apps and searching.

## What Changes

- Implement `OCP\Contacts\ContactsMenu\IProvider` as `ContactsMenuProvider` that processes contact entries: extracts email and name, looks up matching OpenRegister entities, and adds actions to the entry
- Create `ContactMatchingService` for entity matching by email address (against EMAIL entities), display name (against PERSON entities), and organization field (against ORGANIZATION entities); shared logic with `mail-sidebar` change
- Add actions from the action registry with `context: "contact"` to each matched contact entry using `ILinkAction` (clickable links in the contacts menu)
- URL templates support placeholders: `{contactId}`, `{contactEmail}`, `{contactName}`, `{entityId}`
- Show entity/object count badge in the contacts menu popup (e.g., "3 cases, 1 lead, 5 documents")
- Investigate Nextcloud Contacts app sidebar tab support; if available, add Entities/Objects/Actions tabs reusing components from `files-sidebar-tabs`
- Add API endpoint: `GET /api/contacts/match?email={email}&name={name}` for entity matching (reusable by mail-sidebar)
- Cache entity lookups by email address in APCu (TTL 60s) for fast contact menu rendering (< 200ms)

## Capabilities

### New Capabilities
- `contacts-actions`: ContactsMenu provider integration with entity matching, action injection, and count badges for bridging Nextcloud Contacts with OpenRegister entities and consuming app actions
- `contact-entity-matching`: Shared service for matching contact metadata (email, name, organization) to OpenRegister entities with APCu caching

### Modified Capabilities
- `deep-link-registry`: Needs URL template variable support for `{contactId}`, `{contactEmail}`, `{contactName}`

## Impact

- **New PHP classes**: `lib/Contacts/ContactsMenuProvider.php`, `lib/Service/ContactMatchingService.php`
- **Modified**: `lib/AppInfo/Application.php` (register contacts menu provider)
- **New routes**: 1 API endpoint in `appinfo/routes.php`
- **Shared logic**: `ContactMatchingService` entity matching is reused by `mail-sidebar` change
- **Caching**: APCu cache for email-to-entity lookups, TTL 60s
- **Dependencies**: Requires Nextcloud Contacts app installed; depends on `action-registry` change for action cards
- **Performance**: Contact menu popup must render in < 200ms; caching ensures this
- **No breaking changes**: Purely additive
