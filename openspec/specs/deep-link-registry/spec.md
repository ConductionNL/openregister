---
status: reviewed
reviewed_date: 2026-02-28
---

# Deep Link Registry

Allows consuming Nextcloud apps to register URL patterns per OpenRegister (register, schema) combination, so that unified search results link to the owning app's detail view instead of OpenRegister's generic object view.

### Requirement: Apps can register deep link patterns

Consuming Nextcloud apps SHALL be able to register URL patterns for OpenRegister schema/register combinations via the `DeepLinkRegistryService`. A registration maps a (register, schema) pair to a URL template and optional icon, so that OpenRegister can generate URLs pointing to the consuming app's detail view instead of its own.

Registration is event-driven: OpenRegister dispatches a `DeepLinkRegistrationEvent` during its `Application::boot()` phase. Consuming apps listen for this event and call `register()` on the provided `DeepLinkRegistryService` (or use the convenience `register()` method on the event itself).

**Key classes:**
- `OCA\OpenRegister\Service\DeepLinkRegistryService` — In-memory registry with `register()`, `resolve()`, `resolveUrl()`, `resolveIcon()` methods
- `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` — Event dispatched during boot; wraps the registry service
- `OCA\OpenRegister\Dto\DeepLinkRegistration` — Value object storing a single registration (appId, registerSlug, schemaSlug, urlTemplate, icon)

#### Scenario: App registers a deep link pattern at boot time

- **WHEN** a consuming app listens for `DeepLinkRegistrationEvent` and calls `DeepLinkRegistryService::register()` with an app ID, register slug, schema slug, URL template, and optional icon
- **THEN** the registry stores this mapping in memory for the duration of the request

#### Scenario: App registers multiple patterns

- **WHEN** a consuming app registers deep link patterns for multiple schema/register combinations (e.g., Procest registers for "cases" and "tasks" schemas)
- **THEN** each registration is stored independently and can be resolved separately

#### Scenario: Multiple apps register for different schemas

- **WHEN** Procest registers for the "cases" schema and Pipelinq registers for the "clients" schema in the same register
- **THEN** both registrations coexist and the correct app is resolved per schema

#### Scenario: Duplicate registration for same schema is ignored

- **WHEN** a second app tries to register a deep link for a (register, schema) pair that is already claimed
- **THEN** the duplicate registration is silently ignored (first-come-first-served) and a debug log message is emitted

### Requirement: Deep link registry resolves URLs for search results

The `ObjectsProvider` search provider SHALL use the deep link registry to generate URLs for search result entries. When a registered deep link exists for an object's (register, schema) combination, the search result URL MUST point to the consuming app's route. When no registration exists, it MUST fall back to OpenRegister's own object detail route.

#### Scenario: Search result with registered deep link

- **WHEN** a user searches and a result matches an object in a schema that has a registered deep link (e.g., schema "cases" registered by Procest)
- **THEN** the `SearchResultEntry` URL points to Procest's case detail route (e.g., `/apps/procest/#/cases/{uuid}`)

#### Scenario: Search result without registered deep link

- **WHEN** a user searches and a result matches an object in a schema with no registered deep link
- **THEN** the `SearchResultEntry` URL falls back to OpenRegister's `openregister.objects.show` route

#### Scenario: Search result icon reflects owning app

- **WHEN** a search result has a registered deep link from a consuming app
- **THEN** the `SearchResultEntry` icon MUST use the consuming app's icon identifier (via `DeepLinkRegistryService::resolveIcon()`) instead of `icon-openregister`
- **AND** if no custom icon was provided during registration, the icon defaults to `icon-{appId}` (e.g., `icon-procest`)

### Requirement: Registration uses slugs not IDs

Deep link registrations SHALL use register and schema **slugs** (string identifiers) rather than internal database IDs. This ensures registrations are portable across environments and do not break when IDs change.

#### Scenario: Registration by slug

- **WHEN** an app registers a deep link with `registerSlug: "procest"` and `schemaSlug: "cases"`
- **THEN** the registry stores the registration keyed by `"procest::cases"` (slug-based key)
- **AND** at resolution time, `resolve(int $registerId, int $schemaId)` lazily builds ID-to-slug maps from the database (via `RegisterMapper` and `SchemaMapper`) to reverse-map the integer IDs back to slugs for key lookup

#### Scenario: Slug not found at resolution time

- **WHEN** a deep link is registered for a slug that does not match any existing register or schema
- **THEN** the registration is silently ignored and the search result falls back to OpenRegister's default URL

### Requirement: URL template for URL generation

Each deep link registration SHALL include a `urlTemplate` string that defines the URL pattern with `{placeholder}` tokens. The `DeepLinkRegistration::resolveUrl()` method replaces placeholders with values from the object data array.

Supported built-in placeholders: `{uuid}`, `{id}`, `{register}`, `{schema}`. Additionally, any top-level key from the object data array can be used as a placeholder (e.g., `{title}`). Only scalar values are substituted.

URL generation does NOT use Nextcloud's `IURLGenerator` — it uses simple string replacement via `strtr()`.

#### Scenario: UUID-based URL template

- **WHEN** a deep link registration specifies urlTemplate `/apps/procest/#/cases/{uuid}`
- **THEN** the `DeepLinkRegistration::resolveUrl()` method replaces `{uuid}` with the object's UUID from the search result data

#### Scenario: Hash-based frontend route

- **WHEN** a consuming app uses Vue Router hash-based routing (e.g., `/apps/procest/#/cases/{uuid}`)
- **THEN** the URL template handles this natively since it is a plain string with placeholder replacement

### Requirement: Registry is in-memory only

The deep link registry SHALL store all registrations in memory (PHP static/singleton) without database persistence. Registrations are populated fresh on every request via each app's boot cycle.

#### Scenario: No database tables needed

- **WHEN** OpenRegister starts up
- **THEN** the deep link registry requires no database migrations or tables

#### Scenario: Registrations reset per request

- **WHEN** a new HTTP request arrives
- **THEN** the registry starts empty and is populated when OpenRegister dispatches `DeepLinkRegistrationEvent` during its `boot()` phase, which triggers consuming app event listeners to call `register()`

Note: The registry uses PHP `static` arrays, so state persists within a single request but resets across requests. A `reset()` method exists for testing purposes.

### Requirement: Backward compatibility

The deep link registry MUST be fully backward compatible. OpenRegister's existing search behavior SHALL remain unchanged when no consuming apps register deep links.

#### Scenario: No apps register deep links

- **WHEN** no consuming app has registered any deep link patterns
- **THEN** all search results continue to link to `openregister.objects.show` with the object UUID, exactly as before

#### Scenario: OpenRegister works without consuming apps

- **WHEN** OpenRegister is installed without Procest, Pipelinq, or any other consuming app
- **THEN** the search provider functions identically to the current implementation
