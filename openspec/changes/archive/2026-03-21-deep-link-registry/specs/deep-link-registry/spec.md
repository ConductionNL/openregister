---
status: implemented
reviewed_date: 2026-02-28
---

# Deep Link Registry

## Purpose

The Deep Link Registry enables consuming Nextcloud apps (Procest, Pipelinq, OpenCatalogi, etc.) to claim ownership of specific OpenRegister (register, schema) combinations by registering URL templates at boot time. When Nextcloud's unified search returns objects belonging to a claimed combination, results link directly to the consuming app's detail view instead of OpenRegister's generic object view. This decouples object storage (OpenRegister) from object presentation (consuming apps), allowing each app to own its user experience while sharing a common data layer.

The registry is event-driven and in-memory only: OpenRegister dispatches a `DeepLinkRegistrationEvent` during `Application::boot()`, consuming apps listen and call `register()`, and the resulting mappings are used by `ObjectsProvider` (the unified search provider) to resolve URLs and icons for the current request cycle.

## Requirements

### Requirement: Apps SHALL register deep link patterns via boot-time events

Consuming Nextcloud apps SHALL be able to register URL patterns for OpenRegister schema/register combinations via the `DeepLinkRegistryService`. A registration maps a (register, schema) pair to a URL template and optional icon, so that OpenRegister can generate URLs pointing to the consuming app's detail view instead of its own. Registration is event-driven: OpenRegister dispatches a `DeepLinkRegistrationEvent` during its `Application::boot()` phase. Consuming apps listen for this event and call `register()` on the provided `DeepLinkRegistryService` (or use the convenience `register()` method on the event itself).

**Key classes:**
- `OCA\OpenRegister\Service\DeepLinkRegistryService` -- In-memory registry with `register()`, `resolve()`, `resolveUrl()`, `resolveIcon()`, `hasRegistrations()`, `reset()` methods
- `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` -- Event dispatched during boot; wraps the registry service
- `OCA\OpenRegister\Dto\DeepLinkRegistration` -- Value object storing a single registration (appId, registerSlug, schemaSlug, urlTemplate, icon)

#### Scenario: Pipelinq registers deep link patterns for CRM schemas
- **GIVEN** Pipelinq is installed alongside OpenRegister
- **WHEN** OpenRegister dispatches `DeepLinkRegistrationEvent` during `Application::boot()`
- **THEN** Pipelinq's `DeepLinkRegistrationListener` registers four patterns: `client`, `lead`, `request`, `contact` in the `pipelinq` register
- **AND** each registration uses the URL template format `/apps/pipelinq/#/clients/{uuid}` (hash-based Vue Router routes)

#### Scenario: Procest registers deep link patterns for case management schemas
- **GIVEN** Procest is installed alongside OpenRegister
- **WHEN** OpenRegister dispatches `DeepLinkRegistrationEvent` during `Application::boot()`
- **THEN** Procest's `DeepLinkRegistrationListener` registers two patterns: `case` and `task` in the `case-management` register
- **AND** each registration uses the URL template format `/apps/procest/#/cases/{uuid}` and `/apps/procest/#/tasks/{uuid}`

#### Scenario: Multiple apps register for different schemas in the same register
- **GIVEN** both Procest and a hypothetical audit app are installed
- **WHEN** Procest registers for `case-management::case` and the audit app registers for `case-management::audit-log`
- **THEN** both registrations coexist and the correct app is resolved per schema

#### Scenario: Duplicate registration for same (register, schema) pair is silently ignored
- **GIVEN** Procest has already registered a deep link for `case-management::case`
- **WHEN** a second app attempts to register for the same `case-management::case` pair
- **THEN** the duplicate registration is silently ignored (first-come-first-served)
- **AND** a debug log message is emitted: `[DeepLinkRegistry] Ignoring duplicate registration for {key} from {appId} (already claimed by {existing})`

#### Scenario: App that is disabled stops registering deep links
- **GIVEN** Procest was previously registered for `case-management::case`
- **WHEN** Procest is disabled by the admin
- **THEN** on the next request, Procest's boot listener does not fire
- **AND** the `case-management::case` pair has no registration, so search results fall back to OpenRegister's default URL

### Requirement: Deep link registry SHALL resolve URLs for unified search results

The `ObjectsProvider` search provider SHALL use the deep link registry to generate URLs for `SearchResultEntry` objects. When a registered deep link exists for an object's (register, schema) combination, the search result URL MUST point to the consuming app's route. When no registration exists, it MUST fall back to OpenRegister's `openregister.objects.show` route via `IURLGenerator::linkToRoute()`.

#### Scenario: Search result with registered deep link
- **GIVEN** Procest has registered a deep link for `case-management::case` with template `/apps/procest/#/cases/{uuid}`
- **WHEN** a user searches in Nextcloud's unified search and a result matches an object with UUID `abc-123` in schema `case` of register `case-management`
- **THEN** the `SearchResultEntry` URL is `/apps/procest/#/cases/abc-123`

#### Scenario: Search result without registered deep link
- **GIVEN** no consuming app has registered a deep link for schema `audit-log` in register `case-management`
- **WHEN** a user searches and a result matches an object in `case-management::audit-log`
- **THEN** the `SearchResultEntry` URL falls back to `IURLGenerator::linkToRoute('openregister.objects.show', ['register' => $registerId, 'schema' => $schemaId, 'id' => $uuid])`

#### Scenario: Search result icon reflects the owning app
- **GIVEN** Pipelinq has registered a deep link for `pipelinq::client` without specifying a custom icon
- **WHEN** a search result matches a client object
- **THEN** the `SearchResultEntry` icon is `icon-pipelinq` (derived from `icon-{appId}`)
- **AND** if Pipelinq had specified a custom icon during registration, that custom icon is used instead

#### Scenario: Mixed search results from multiple apps
- **GIVEN** Procest owns `case-management::case` and Pipelinq owns `pipelinq::client`
- **WHEN** a unified search returns results from both schemas
- **THEN** case results link to Procest, client results link to Pipelinq, and any unregistered schema results link to OpenRegister

### Requirement: Registration SHALL use slugs not database IDs

Deep link registrations SHALL use register and schema **slugs** (string identifiers) rather than internal database IDs. This ensures registrations are portable across environments (development, staging, production) and do not break when IDs change due to data migration or reimport. At resolution time, `DeepLinkRegistryService` lazily builds ID-to-slug reverse maps from the database via `RegisterMapper` and `SchemaMapper`.

#### Scenario: Registration by slug with lazy ID resolution
- **GIVEN** Procest registers a deep link with `registerSlug: "case-management"` and `schemaSlug: "case"`
- **WHEN** `ObjectsProvider` calls `resolveUrl(registerId: 42, schemaId: 17, objectData: [...])`
- **THEN** the registry loads all registers and schemas from the database (once per request), builds an `ID -> slug` map, resolves `42 -> "case-management"` and `17 -> "case"`, constructs the key `"case-management::case"`, and returns the matching registration

#### Scenario: Slug not found at resolution time
- **GIVEN** a deep link is registered for slug `old-register` that no longer exists in the database
- **WHEN** `resolve()` is called with an ID that maps to a different slug
- **THEN** no registration is found and the search result falls back to OpenRegister's default URL

#### Scenario: ID maps are cached within a single request
- **GIVEN** 50 search results need deep link resolution
- **WHEN** `resolveUrl()` is called 50 times in the same request
- **THEN** `ensureIdMaps()` loads registers and schemas from the database only once (static cache)
- **AND** subsequent calls use the cached maps without additional database queries

### Requirement: URL templates SHALL support placeholder-based URL generation

Each deep link registration SHALL include a `urlTemplate` string with `{placeholder}` tokens. The `DeepLinkRegistration::resolveUrl()` method replaces placeholders with values from the object data array using `strtr()`. This approach supports hash-based Vue Router routes natively without requiring `IURLGenerator`.

Supported built-in placeholders: `{uuid}`, `{id}`, `{register}`, `{schema}`. Additionally, any top-level key from the object data array (from `@self` metadata) can be used as a placeholder. Only scalar values are substituted.

#### Scenario: UUID-based URL template
- **GIVEN** a deep link registration specifies `urlTemplate: "/apps/procest/#/cases/{uuid}"`
- **WHEN** `resolveUrl()` is called with `objectData: ['uuid' => 'abc-123', 'register' => 42, 'schema' => 17]`
- **THEN** the resolved URL is `/apps/procest/#/cases/abc-123`

#### Scenario: URL template with multiple placeholders
- **GIVEN** a registration uses `urlTemplate: "/apps/myapp/#/registers/{register}/schemas/{schema}/objects/{uuid}"`
- **WHEN** `resolveUrl()` is called with object data containing `uuid`, `register`, and `schema`
- **THEN** all three placeholders are replaced with the corresponding values

#### Scenario: Custom object property as placeholder
- **GIVEN** a registration uses `urlTemplate: "/apps/myapp/#/{title}/detail"`
- **WHEN** `resolveUrl()` is called with `objectData: ['uuid' => 'x', 'title' => 'my-case']`
- **THEN** the resolved URL is `/apps/myapp/#/my-case/detail`

#### Scenario: Non-scalar values are not substituted
- **GIVEN** a registration uses `urlTemplate: "/apps/myapp/#/{metadata}/view"`
- **WHEN** `objectData` contains `'metadata' => ['key' => 'value']` (an array, not scalar)
- **THEN** `{metadata}` is NOT replaced and remains as a literal string in the URL

### Requirement: Registry SHALL be in-memory only without database persistence

The deep link registry SHALL store all registrations in memory using PHP `static` arrays without requiring database migrations or tables. Registrations are populated fresh on every HTTP request via each app's boot cycle. A `reset()` method exists for testing purposes.

#### Scenario: No database tables needed
- **GIVEN** OpenRegister is installed or upgraded
- **THEN** the deep link registry requires no database migrations or tables

#### Scenario: Registrations reset per request
- **GIVEN** a previous request populated the registry with Procest and Pipelinq registrations
- **WHEN** a new HTTP request arrives
- **THEN** the registry starts empty and is repopulated when OpenRegister dispatches `DeepLinkRegistrationEvent` during its `boot()` phase

#### Scenario: Static state persists within a single request
- **GIVEN** OpenRegister's boot phase populates the registry
- **WHEN** the search provider queries the registry later in the same request
- **THEN** all registrations from the boot phase are available (PHP `static` array scope)

### Requirement: Registry MUST maintain backward compatibility

The deep link registry MUST be fully backward compatible. OpenRegister's existing search behavior SHALL remain unchanged when no consuming apps register deep links. The feature has zero impact on installations without consuming apps.

#### Scenario: No apps register deep links
- **GIVEN** no consuming app has registered any deep link patterns
- **WHEN** a user performs a unified search
- **THEN** all search results continue to link to `openregister.objects.show` with the object UUID, exactly as before

#### Scenario: OpenRegister installed standalone
- **GIVEN** OpenRegister is installed without Procest, Pipelinq, OpenCatalogi, or any other consuming app
- **WHEN** `DeepLinkRegistrationEvent` is dispatched during boot
- **THEN** no listeners respond, `hasRegistrations()` returns false, and the search provider skips registry resolution entirely

#### Scenario: Partial registration
- **GIVEN** Procest registers deep links for `case` and `task` schemas but the register also contains `document` and `note` schemas
- **WHEN** search results include objects from all four schemas
- **THEN** case and task results link to Procest, while document and note results fall back to OpenRegister's default URL

### Requirement: Canonical object URLs SHALL follow a predictable format

OpenRegister's default deep link format for objects SHALL follow the pattern `/index.php/apps/openregister/objects` with query parameters or route parameters identifying the register, schema, and object UUID. This canonical URL is the fallback when no consuming app has claimed the (register, schema) pair.

#### Scenario: Default object URL via IURLGenerator
- **GIVEN** an object with UUID `abc-123` in register ID `42` and schema ID `17`
- **WHEN** no deep link registration exists for this combination
- **THEN** the canonical URL is generated via `IURLGenerator::linkToRoute('openregister.objects.show', ['register' => 42, 'schema' => 17, 'id' => 'abc-123'])`

#### Scenario: History-mode SPA routes for OpenRegister's own UI
- **GIVEN** OpenRegister uses Vue Router in history mode with base path `/index.php/apps/openregister/`
- **WHEN** a user navigates to `/index.php/apps/openregister/registers/5`
- **THEN** the backend `UiController::registersDetails()` serves the SPA template and Vue Router handles client-side routing

#### Scenario: Backend page routes mirror frontend SPA routes
- **GIVEN** OpenRegister defines page routes in `appinfo/routes.php` (e.g., `ui#registers`, `ui#registersDetails`, `ui#schemas`, `ui#objects`)
- **WHEN** a user directly navigates to any of these URLs (bookmark, shared link, browser refresh)
- **THEN** the backend serves the SPA template via `UiController::makeSpaResponse()` and Vue Router picks up the path for client-side rendering

### Requirement: Cross-app deep linking SHALL work with hash-based and history-mode routing

The deep link registry SHALL support both hash-based routing (e.g., `/apps/procest/#/cases/{uuid}`) used by consuming apps and history-mode routing (e.g., `/apps/openregister/registers/{id}`) used by OpenRegister itself. URL templates are plain strings processed by `strtr()`, so they are routing-mode agnostic.

#### Scenario: Hash-based route from Pipelinq
- **GIVEN** Pipelinq registers `urlTemplate: "/apps/pipelinq/#/clients/{uuid}"`
- **WHEN** the unified search resolves a client object
- **THEN** the URL `/apps/pipelinq/#/clients/abc-123` is generated
- **AND** clicking this URL in the Nextcloud search results navigates to Pipelinq's Vue Router client detail view

#### Scenario: History-mode route from a hypothetical app
- **GIVEN** an app registers `urlTemplate: "/apps/myapp/objects/{uuid}"`
- **WHEN** the unified search resolves an object
- **THEN** the URL `/apps/myapp/objects/abc-123` is generated
- **AND** this requires the consuming app to have a matching backend page route in its `routes.php`

#### Scenario: Absolute URL template with external system
- **GIVEN** an app registers `urlTemplate: "https://external-system.example.com/objects/{uuid}"`
- **WHEN** the unified search resolves an object
- **THEN** the URL `https://external-system.example.com/objects/abc-123` is generated
- **AND** the Nextcloud search UI opens this as an external link

### Requirement: Notification deep links SHALL use the deep link registry

OpenRegister's `Notifier` class generates notification links pointing to object detail views. Notifications SHALL consult the deep link registry to generate links to the owning app's view when a registration exists. Currently, notification links use `IURLGenerator::linkToRouteAbsolute()` with a hash fragment for configurations (e.g., `openregister.dashboard.page` + `#/configurations/{id}`). This pattern SHOULD be extended to object notifications.

#### Scenario: Notification links to registered consuming app
- **GIVEN** a notification is generated for an object in `case-management::case` (owned by Procest)
- **WHEN** the `Notifier` resolves the notification link
- **THEN** the link SHOULD point to `/apps/procest/#/cases/{uuid}` instead of OpenRegister's generic view

#### Scenario: Notification links without registered deep link
- **GIVEN** a notification is generated for an object with no deep link registration
- **WHEN** the `Notifier` resolves the notification link
- **THEN** the link falls back to `IURLGenerator::linkToRouteAbsolute('openregister.dashboard.page')` with a hash fragment to the object

#### Scenario: Configuration update notification uses existing pattern
- **GIVEN** a configuration update notification is created
- **WHEN** the `Notifier::prepareConfigurationUpdate()` generates the link
- **THEN** the link uses `linkToRouteAbsolute('openregister.dashboard.page') . '#/configurations/' . $configurationId`
- **AND** this existing pattern demonstrates the hash-fragment approach used for deep linking

### Requirement: API responses SHALL include self-referencing links

Object API responses SHALL include `_self` metadata that provides enough information for clients to construct deep links. The `ObjectEntity::jsonSerialize()` method already returns `@self` metadata containing `id` (UUID), `register`, `schema`, `name`, `slug`, and other fields. API consumers can use this metadata to construct deep links.

#### Scenario: Object API response includes @self metadata
- **GIVEN** an API client fetches an object via `GET /api/objects/{register}/{schema}/{id}`
- **WHEN** the response is serialized via `ObjectEntity::jsonSerialize()`
- **THEN** the response includes `@self` with fields: `id`, `slug`, `name`, `register`, `schema`, `organisation`, `created`, `updated`, and `uri`

#### Scenario: OAS schema documents the _self structure
- **GIVEN** the OpenAPI specification is generated via `OasService`
- **WHEN** a client reads the schema definition
- **THEN** `_self` is documented as a `$ref` to `#/components/schemas/_self` with `readOnly: true`

#### Scenario: Client constructs a deep link from API response
- **GIVEN** a client receives an object with `@self: { id: "abc-123", register: 42, schema: 17 }`
- **WHEN** the client wants to link to this object in the UI
- **THEN** the client can construct `/index.php/apps/openregister/objects?register=42&schema=17&id=abc-123` or use a registered consuming app's URL pattern

### Requirement: Deep link registry SHALL be discoverable via ICapability

The deep link registry SHALL expose registered deep link patterns via Nextcloud's `ICapability` interface. This allows frontend applications to discover which schemas have registered deep links and generate correct URLs client-side without additional API calls. The capabilities response includes a map of `{registerSlug}::{schemaSlug}` to URL template patterns.

#### Scenario: Frontend discovers deep link patterns via capabilities
- **GIVEN** Procest and Pipelinq have registered deep link patterns
- **WHEN** a frontend app fetches capabilities from `/ocs/v2.php/cloud/capabilities`
- **THEN** the response includes `openregister.deepLinks` with entries like `{"case-management::case": {"appId": "procest", "urlTemplate": "/apps/procest/#/cases/{uuid}", "icon": "icon-procest"}}`

#### Scenario: No deep links registered in capabilities
- **GIVEN** no consuming apps have registered deep links
- **WHEN** capabilities are fetched
- **THEN** `openregister.deepLinks` is an empty object `{}`

#### Scenario: Frontend generates deep links without API round-trip
- **GIVEN** the frontend has fetched capabilities containing deep link patterns
- **WHEN** the frontend needs to link to an object with known register slug, schema slug, and UUID
- **THEN** the frontend performs client-side `strtr()`-equivalent placeholder replacement on the URL template

### Requirement: Deep link resolution SHALL handle circular DI gracefully

The `DeepLinkRegistryService` SHALL use `ContainerInterface` for lazy resolution of `RegisterMapper` and `SchemaMapper` instead of direct constructor injection. This avoids circular dependency issues during the Nextcloud DI container bootstrap phase, where `RegisterMapper` depends on `MagicMapper` which may transitively depend on services being constructed.

#### Scenario: Lazy mapper resolution avoids circular DI
- **GIVEN** `DeepLinkRegistryService` is constructed during `Application::boot()`
- **WHEN** `RegisterMapper` and `SchemaMapper` are needed for ID-to-slug resolution
- **THEN** they are resolved lazily from the container only when `ensureIdMaps()` is first called (during search result generation), not during construction

#### Scenario: Mapper resolution failure is gracefully handled
- **GIVEN** `RegisterMapper` fails to load (e.g., database connection issue)
- **WHEN** `ensureIdMaps()` catches the exception
- **THEN** a warning is logged: `[DeepLinkRegistry] Failed to load registers for slug resolution: {error}`
- **AND** the registry returns null for all resolve calls (graceful degradation to OpenRegister's default URLs)

#### Scenario: Deep link registration is deferred in Application::boot()
- **GIVEN** OpenRegister's `Application::boot()` dispatches `DeepLinkRegistrationEvent`
- **WHEN** the event is dispatched
- **THEN** the registration is deferred to avoid circular DI resolution (comment in `Application.php` line 764: "Deep link registration is deferred to avoid circular DI resolution")

### Requirement: Deep link context SHALL support pre-selected views via query parameters

URL templates SHALL support query parameters and hash fragments that encode UI context such as pre-selected tabs, active filters, or scroll positions. Since URL templates use plain `strtr()` replacement, any valid URL syntax including query strings and fragments is supported.

#### Scenario: Deep link with pre-selected tab
- **GIVEN** a consuming app registers `urlTemplate: "/apps/myapp/#/cases/{uuid}?tab=documents"`
- **WHEN** the search resolves an object
- **THEN** the URL `/apps/myapp/#/cases/abc-123?tab=documents` is generated
- **AND** the consuming app's Vue Router reads the query parameter to pre-select the documents tab

#### Scenario: Deep link with filter context
- **GIVEN** a consuming app registers `urlTemplate: "/apps/myapp/#/cases/{uuid}?status={status}"`
- **WHEN** `resolveUrl()` is called with `objectData: ['uuid' => 'abc-123', 'status' => 'open']`
- **THEN** both `{uuid}` and `{status}` are replaced, producing `/apps/myapp/#/cases/abc-123?status=open`

#### Scenario: Deep link with hash sub-fragment
- **GIVEN** a consuming app registers `urlTemplate: "/apps/myapp/#/cases/{uuid}/timeline"`
- **WHEN** the search resolves an object
- **THEN** the URL points directly to the timeline section of the case detail view

### Requirement: Link preview metadata SHALL be available for shared deep links

When deep links to OpenRegister objects are shared (via chat, email, or social media), the server SHALL return OpenGraph metadata (`og:title`, `og:description`, `og:url`) so that link previews render meaningful information. This requires the backend page routes to inject metadata into the HTML template response.

#### Scenario: Shared object link generates preview
- **GIVEN** a user shares a link `/index.php/apps/openregister/objects?id=abc-123`
- **WHEN** a chat application or social media platform fetches the URL for a link preview
- **THEN** the HTML response SHOULD include `<meta property="og:title" content="Case: Omgevingsvergunning Kerkstraat">` and `<meta property="og:description" content="Case object in register case-management">`

#### Scenario: Deep link to consuming app generates preview from that app
- **GIVEN** a user shares a link `/apps/procest/#/cases/abc-123`
- **WHEN** a platform fetches the URL for a link preview
- **THEN** the consuming app (Procest) is responsible for providing OpenGraph metadata in its own template response

#### Scenario: API endpoint returns link preview data
- **GIVEN** a client wants to generate a rich link preview without parsing HTML
- **WHEN** the client fetches `GET /api/objects/{register}/{schema}/{id}`
- **THEN** the `@self` metadata in the response provides `name`, `register`, `schema`, and `updated` fields sufficient for constructing a preview

## Current Implementation Status

- **Fully implemented:**
  - `DeepLinkRegistryService` (`lib/Service/DeepLinkRegistryService.php`) -- In-memory registry with `register()`, `resolve()`, `resolveUrl()`, `resolveIcon()`, `hasRegistrations()`, `reset()` methods
  - `DeepLinkRegistration` DTO (`lib/Dto/DeepLinkRegistration.php`) -- Value object with `resolveUrl(array $objectData)` using `strtr()` placeholder replacement
  - `DeepLinkRegistrationEvent` (`lib/Event/DeepLinkRegistrationEvent.php`) -- Event dispatched during `Application::boot()` with convenience `register()` method
  - `ObjectsProvider` (`lib/Search/ObjectsProvider.php`) -- Search provider integrated with deep link resolution for URL and icon generation (lines 340-357)
  - Registration dispatched in `Application::boot()` (`lib/AppInfo/Application.php`, line 764+)
  - `UiController` (`lib/Controller/UiController.php`) -- Backend page routes for history-mode SPA deep links
  - Slug-based registration with lazy ID-to-slug mapping via `RegisterMapper` and `SchemaMapper` (lazy via `ContainerInterface`)
  - In-memory only (static PHP arrays, no database tables), resets per request
  - Backward compatible: falls back to `openregister.objects.show` when no deep link is registered
  - **Consumer implementations:** Pipelinq (`lib/Listener/DeepLinkRegistrationListener.php`, 4 schemas) and Procest (`lib/Listener/DeepLinkRegistrationListener.php`, 2 schemas)

- **NOT implemented:**
  - `ICapability` exposure of deep link patterns
  - `Notifier` integration with deep link registry for notification links (currently uses hardcoded `openregister.dashboard.page` + hash fragment)
  - OpenGraph metadata injection in template responses
  - Deep link context with query parameters (supported by architecture but no consuming app uses it yet)
  - Link preview API endpoint

## Standards & References
- **Nextcloud ISearchProvider** (`OCP\Search\IProvider`) -- Unified search provider interface that `ObjectsProvider` implements
- **Nextcloud IEventDispatcher** (`OCP\EventDispatcher\IEventDispatcher`) -- Event system for inter-app communication during boot
- **Nextcloud IURLGenerator** (`OCP\IURLGenerator`) -- Used for fallback URL generation via `linkToRoute('openregister.objects.show', ...)` and `linkToRouteAbsolute()` in notifications
- **Nextcloud ICapability** (`OCP\Capabilities\ICapability`) -- Recommended for exposing deep link patterns to frontends
- **Vue Router** -- Both hash mode (`/#/path`) and history mode (`/path`) URL patterns are supported by URL templates
- **`appinfo/routes.php`** -- Backend page routes (`ui#registers`, `ui#schemas`, `ui#objects`, etc.) that mirror frontend SPA routes for history-mode deep linking

## Cross-References
- **urn-resource-addressing** -- URN identifiers provide system-independent addressing; deep links provide system-specific navigation. URN resolution could use the deep link registry to generate navigable URLs from URNs.
- **no-code-app-builder** -- No-code apps built on OpenRegister will need to register deep link patterns dynamically for their custom schemas, potentially extending the event-based registration to a database-backed approach.

## Specificity Assessment
- This spec is highly specific and the core functionality is fully implemented with working consumer examples (Procest, Pipelinq).
- The slug-based registration with lazy ID-to-slug mapping, `strtr()` placeholder replacement, and first-come-first-served duplicate handling are all documented and match the implementation.
- Enhancement areas (ICapability, Notifier integration, OpenGraph metadata) are clearly marked as not implemented and provide concrete scenarios for future work.
- The circular DI avoidance strategy (ContainerInterface + lazy resolution) is architecturally significant and documented.

## Nextcloud Integration Analysis

**Status**: Core functionality fully implemented. `DeepLinkRegistryService`, `DeepLinkRegistration` DTO, `DeepLinkRegistrationEvent`, and `ObjectsProvider` integration are all in place and actively used by Procest and Pipelinq.

**Nextcloud Core Interfaces Used**:
- `IEventDispatcher` (`OCP\EventDispatcher\IEventDispatcher`): Dispatches `DeepLinkRegistrationEvent` during `Application::boot()`. Consumer apps register listeners via `$context->registerEventListener(DeepLinkRegistrationEvent::class, DeepLinkRegistrationListener::class)`.
- `ISearchProvider` (`OCP\Search\IProvider`): `ObjectsProvider` calls `DeepLinkRegistryService::resolveUrl()` and `resolveIcon()` to generate search result URLs and icons. Falls back to `IURLGenerator::linkToRoute('openregister.objects.show', ...)` when no registration exists.
- `IURLGenerator` (`OCP\IURLGenerator`): Used for fallback URL generation in `ObjectsProvider` and for absolute notification links in `Notifier`. The deep link registry intentionally does NOT use `IURLGenerator` for registered templates -- `strtr()` is used instead to support hash-based Vue Router routes.
- `ContainerInterface` (`Psr\Container\ContainerInterface`): Used for lazy resolution of `RegisterMapper` and `SchemaMapper` to avoid circular DI during bootstrap.

**Recommended Enhancements**:
- Expose registered deep links via `ICapability` so frontends can discover URL templates without API calls.
- Integrate `Notifier` with `DeepLinkRegistryService` so notification links point to the correct consuming app.
- Support dynamic registration from no-code apps (database-backed patterns loaded during boot alongside event-based patterns).
- Consider `IURLGenerator::linkToRouteAbsolute()` as an optional URL generation strategy for server-side route generation alongside the current `strtr()` approach.

**Dependencies on Existing OpenRegister Features**:
- `DeepLinkRegistryService` (`lib/Service/DeepLinkRegistryService.php`) -- in-memory registry with static arrays.
- `DeepLinkRegistrationEvent` (`lib/Event/DeepLinkRegistrationEvent.php`) -- boot-time event for consuming app registration.
- `DeepLinkRegistration` (`lib/Dto/DeepLinkRegistration.php`) -- value object with `resolveUrl()` method.
- `ObjectsProvider` (`lib/Search/ObjectsProvider.php`) -- unified search integration point.
- `UiController` (`lib/Controller/UiController.php`) -- backend page routes for SPA deep linking.
- `Application.php` -- dispatches the registration event during `boot()` phase.
- `RegisterMapper` / `SchemaMapper` -- ID-to-slug mapping for key resolution (lazily loaded).
- `ObjectEntity::jsonSerialize()` -- provides `@self` metadata used for deep link data extraction.
- `Notifier` (`lib/Notification/Notifier.php`) -- notification links (enhancement target).
