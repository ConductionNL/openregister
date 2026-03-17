## Context

OpenRegister's `ObjectsProvider` (at `lib/Search/ObjectsProvider.php`) implements Nextcloud's `IFilteringProvider` to surface register objects in unified search. Currently, all search result URLs point to OpenRegister's own route:

```php
$objectUrl = $this->urlGenerator->linkToRoute(
    'openregister.objects.show',
    ['id' => $result['uuid']]
);
```

Consumer apps (Procest, Pipelinq, OpenCatalogi, SoftwareCatalog) use OpenRegister as invisible data infrastructure. Their users expect search results to open in the relevant app's UI, not OpenRegister's raw object view.

Consumer apps use Vue Router with hash-based routing (e.g., `/apps/procest/#/cases/{uuid}`), which means Nextcloud's server-side `IURLGenerator::linkToRoute()` cannot generate the full client-side URL. We need a mechanism that supports both server-side Nextcloud routes and client-side hash-based URL templates.

## Goals / Non-Goals

**Goals:**
- Allow consuming apps to register URL patterns per (register, schema) pair
- Modify `ObjectsProvider` to resolve registered deep links for search result URLs
- Support Vue Router hash-based URLs (most consumer apps use this pattern)
- Zero database changes — in-memory only
- Full backward compatibility — no change when no apps register

**Non-Goals:**
- REST API for managing registrations (this is a PHP-only internal API)
- Deep link UI in OpenRegister's admin settings
- Support for registering deep links from external (non-Nextcloud) apps
- Per-object URL overrides (registration is per schema, not per object)
- Priority/conflict resolution between multiple apps claiming the same schema (first registration wins)

## Decisions

### 1. Singleton service with static storage

**Decision**: Use a service class `DeepLinkRegistryService` with a static array to hold registrations.

**Rationale**: Nextcloud apps boot via `Application::register()` which runs before DI containers are fully resolved. A static array survives across DI resolution and is the simplest mechanism for in-memory state within a single PHP request. This follows the same pattern as Nextcloud's own `registerSearchProvider()`.

**Alternative considered**: Using `IAppConfig` for persistence — rejected because registrations should be fresh per request (apps come and go, slugs may change), and database writes on every request add unnecessary overhead.

### 2. URL template strings instead of route names

**Decision**: Registrations use a `urlTemplate` string (e.g., `/apps/procest/#/cases/{uuid}`) where placeholders like `{uuid}`, `{id}`, `{register}`, `{schema}` are replaced with object field values.

**Rationale**: Most consumer apps use Vue Router with hash-based routing. Nextcloud's `IURLGenerator::linkToRoute()` generates server-side URLs but cannot produce `/#/path` fragments. A template string gives apps full control over their URL format.

**Alternative considered**: Using `linkToRoute()` with a catch-all Nextcloud route + parameter map — rejected because it requires each consumer app to define a server-side catch-all route that redirects to the Vue frontend, adding unnecessary complexity.

### 3. Slug-based registration keys

**Decision**: Register and schema are identified by their slug (string name) in the registration, not by database integer IDs.

**Rationale**: Slugs are human-readable, portable across environments, and stable across database re-imports. IDs change between dev/staging/production. Consumer apps know their schema slugs but shouldn't need to query OpenRegister's database for IDs.

**Resolution at search time**: The `ObjectsProvider` already has access to schema/register IDs on each search result. The registry service resolves slug → ID at boot time (or lazily on first lookup) by querying `RegisterMapper` and `SchemaMapper`.

### 4. First-registration-wins for conflicts

**Decision**: If two apps register for the same (register, schema) combination, the first registration wins silently.

**Rationale**: In practice, each schema should have one owning app. Conflicts indicate misconfiguration, not a valid use case. Logging a warning is sufficient; throwing exceptions during boot would break the app.

### 5. Registration via Nextcloud event system

**Decision**: OpenRegister dispatches a `DeepLinkRegistrationEvent` during its `boot()` phase. Consumer apps listen for this event and call `register()` on the event or the injected service.

**Alternative approach**: Consumer apps directly inject `DeepLinkRegistryService` in their `boot()`. This is simpler but creates a hard dependency on OpenRegister being installed. Using events is more decoupled — if OpenRegister isn't installed, the event simply never fires and the consumer app doesn't crash.

**Final decision**: Support both patterns:
1. **Event-based** (recommended): Listen for `DeepLinkRegistrationEvent`
2. **Direct injection**: Inject `DeepLinkRegistryService` and call `register()` (for apps that already depend on OpenRegister)

## Component Design

### DeepLinkRegistryService

```
lib/Service/DeepLinkRegistryService.php

Methods:
- register(appId, registerSlug, schemaSlug, urlTemplate, icon?): void
- resolve(registerId, schemaId): ?DeepLinkRegistration
- resolveUrl(registerId, schemaId, objectData): string
```

The `resolve()` method maps integer IDs (from search results) back to registrations via a lazy-loaded slug→ID lookup cache.

### DeepLinkRegistration (value object)

```
lib/Dto/DeepLinkRegistration.php

Properties:
- appId: string (e.g., "procest")
- registerSlug: string
- schemaSlug: string
- urlTemplate: string (e.g., "/apps/procest/#/cases/{uuid}")
- icon: string (e.g., "icon-procest", defaults to app icon)
```

### ObjectsProvider changes

In the `search()` method, replace the hardcoded URL generation:

```
Before: $objectUrl = $this->urlGenerator->linkToRoute('openregister.objects.show', ...)
After:  $objectUrl = $this->deepLinkRegistry->resolveUrl($registerId, $schemaId, $result)
        ?? $this->urlGenerator->linkToRoute('openregister.objects.show', ...)
```

Similarly, replace the hardcoded icon:
```
Before: 'icon-openregister'
After:  $registration?->icon ?? 'icon-openregister'
```

## Risks / Trade-offs

**[Risk: Boot order]** Consumer apps must boot after OpenRegister for event-based registration to work.
→ **Mitigation**: Nextcloud boots apps alphabetically by default. If ordering matters, apps can use direct injection instead of events. Document both patterns.

**[Risk: Slug changes]** If an admin renames a register/schema slug, existing registrations break silently.
→ **Mitigation**: Registrations fall back to OpenRegister's default URL. Log a debug warning when a registered slug cannot be resolved.

**[Risk: Performance]** Slug→ID resolution on every search request adds queries.
→ **Mitigation**: Cache the slug→ID map in a static property for the duration of the request. This is at most 2 queries (one for registers, one for schemas) per request, and only if deep links are registered.

**[Trade-off: Template vs route]** URL templates are more flexible but less type-safe than Nextcloud routes.
→ **Accepted**: The flexibility is necessary for hash-based Vue Router URLs. Template validation at registration time is not practical since we don't know the app's routes.

## Migration Plan

1. Add `DeepLinkRegistryService` and `DeepLinkRegistration` to OpenRegister
2. Modify `ObjectsProvider` to use the registry with fallback
3. Add `DeepLinkRegistrationEvent` dispatch in `Application::boot()`
4. Document the registration API for consuming apps
5. (Separate changes in each app) Add deep link registrations in Procest, Pipelinq, etc.

**Rollback**: Remove the registry service and event. Revert `ObjectsProvider` to hardcoded URL generation. No database changes to revert.

## Open Questions

1. **Should the registry support multiple URL templates per schema?** (e.g., list view vs detail view) — Current design says no, one URL per (register, schema) pair. Revisit if needed.
2. **Should consuming apps also be able to customize the search result title/description format?** — Not in scope for this change, but could be added later via an optional callback in the registration.
