## ADDED Requirements

### Requirement: Apps can register deep link patterns

Consuming Nextcloud apps SHALL be able to register URL patterns for OpenRegister schema/register combinations via a PHP service. A registration maps a (register, schema) pair to a Nextcloud route name and parameter mapping, so that OpenRegister can generate URLs pointing to the consuming app's detail view instead of its own.

#### Scenario: App registers a deep link pattern at boot time

- **WHEN** a consuming app calls `DeepLinkRegistryService::register()` with an app ID, register slug, schema slug, route name, and parameter map during its `Application::register()` or `boot()` phase
- **THEN** the registry stores this mapping in memory for the duration of the request

#### Scenario: App registers multiple patterns

- **WHEN** a consuming app registers deep link patterns for multiple schema/register combinations (e.g., Procest registers for "cases" and "tasks" schemas)
- **THEN** each registration is stored independently and can be resolved separately

#### Scenario: Multiple apps register for different schemas

- **WHEN** Procest registers for the "cases" schema and Pipelinq registers for the "clients" schema in the same register
- **THEN** both registrations coexist and the correct app is resolved per schema

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
- **THEN** the `SearchResultEntry` icon MUST use the consuming app's icon identifier instead of `icon-openregister`

### Requirement: Registration uses slugs not IDs

Deep link registrations SHALL use register and schema **slugs** (string identifiers) rather than internal database IDs. This ensures registrations are portable across environments and do not break when IDs change.

#### Scenario: Registration by slug

- **WHEN** an app registers a deep link with `register: "procest"` and `schema: "cases"`
- **THEN** the registry resolves the slugs to the corresponding register/schema objects at lookup time

#### Scenario: Slug not found at resolution time

- **WHEN** a deep link is registered for a slug that does not match any existing register or schema
- **THEN** the registration is silently ignored and the search result falls back to OpenRegister's default URL

### Requirement: Parameter mapping for URL generation

Each deep link registration SHALL include a parameter map that defines how object data fields map to the consuming app's route parameters. This allows the registry to generate correct URLs using the Nextcloud `IURLGenerator`.

#### Scenario: UUID-based parameter mapping

- **WHEN** a deep link registration specifies `{'id': 'uuid'}` as the parameter map
- **THEN** the registry generates the URL by replacing the route parameter `id` with the object's `uuid` field

#### Scenario: Hash-based frontend route

- **WHEN** a consuming app uses Vue Router hash-based routing (e.g., `/apps/procest/#/cases/{uuid}`)
- **THEN** the registration SHALL support a `urlTemplate` string as an alternative to Nextcloud route generation, where `{uuid}` placeholders are replaced with object field values

### Requirement: Registry is in-memory only

The deep link registry SHALL store all registrations in memory (PHP static/singleton) without database persistence. Registrations are populated fresh on every request via each app's boot cycle.

#### Scenario: No database tables needed

- **WHEN** OpenRegister starts up
- **THEN** the deep link registry requires no database migrations or tables

#### Scenario: Registrations reset per request

- **WHEN** a new HTTP request arrives
- **THEN** the registry starts empty and is populated by consuming apps during their `Application::register()` or `boot()` phase

### Requirement: Backward compatibility

The deep link registry MUST be fully backward compatible. OpenRegister's existing search behavior SHALL remain unchanged when no consuming apps register deep links.

#### Scenario: No apps register deep links

- **WHEN** no consuming app has registered any deep link patterns
- **THEN** all search results continue to link to `openregister.objects.show` with the object UUID, exactly as before

#### Scenario: OpenRegister works without consuming apps

- **WHEN** OpenRegister is installed without Procest, Pipelinq, or any other consuming app
- **THEN** the search provider functions identically to the current implementation
