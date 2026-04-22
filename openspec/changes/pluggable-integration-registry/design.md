# Design: Pluggable Integration Registry

## Approach

Introduce a two-sided registry — PHP backend and JS frontend — with a single conceptual "integration" as the unit. An integration owns a vertical slice: backend provider, sidebar tab, card widget, plus a handful of declarative metadata (id, label, icon, required NC app, storage strategy, order, group).

### The three-stage filter

Three layers decide what the user actually sees. They already exist in skeletal form today (registry ≈ hardcoded `TYPE_COLUMN_MAP`; schema ≈ `linkedTypes` config; component ≈ `hidden-tabs` prop). The change makes them consistent, registry-driven, and observable.

```
 Stage 1 — REGISTRY (existence)                 resolves on every request
 ┌──────────────────────────────────────────┐
 │ IntegrationRegistry::list()              │   "all integrations registered on this instance"
 │   → filter by Provider::isEnabled()      │   (required NC app installed?)
 └────────────┬─────────────────────────────┘
              │
              ▼
 Stage 2 — SCHEMA (relevance)                   evaluated per object
 ┌──────────────────────────────────────────┐
 │ Schema.configuration.linkedTypes         │   "integrations relevant to this schema"
 │   (absent → show all; present → whitelist)│
 │   + per-schema order override (optional)  │
 └────────────┬─────────────────────────────┘
              │
              ▼
 Stage 3 — COMPONENT / APP CONTEXT (visibility) evaluated per render
 ┌──────────────────────────────────────────┐
 │ <CnObjectSidebar :exclude-integrations=..│
 │ <CnDashboardPage widgets=selected-subset │   "show only these on THIS page"
 │ surface: user-dashboard | app-dashboard  │
 │          | detail-page-sidebar           │
 │          | detail-page-widget            │
 └──────────────────────────────────────────┘
```

```
 ┌──────────────────────────────────────────────────────────────────┐
 │                    OpenRegister core                              │
 │                                                                   │
 │   ObjectsController ──┐                                           │
 │                       │  dispatch /{register}/{schema}/{id}/{type}│
 │                       ▼                                           │
 │              ┌──────────────────────┐                             │
 │              │  IntegrationRegistry │  reads DI-tagged providers  │
 │              └──────────┬───────────┘                             │
 │                         │ lookup by id                            │
 │                         ▼                                         │
 │                ┌────────────────────┐                             │
 │                │ IntegrationProvider│  interface                  │
 │                └────────┬───────────┘                             │
 │            ┌────────────┼───────────────┬─────────────┐           │
 │            ▼            ▼               ▼             ▼           │
 │       Built-in     Built-in         New NC-native   External     │
 │    (files, notes,  (mail, cal,      (forms, maps,   via          │
 │     tasks, tags,    deck, contacts,  collectives,    OpenConnector│
 │     audit)          talk)            ...)            (openproject,│
 │                                                       xwiki)      │
 └──────────────────────────────────────────────────────────────────┘
                                 │
                                 │ REST (sub-resource endpoints)
                                 ▼
 ┌──────────────────────────────────────────────────────────────────┐
 │             @conduction/nextcloud-vue (shared lib)                │
 │                                                                   │
 │     window.OCA.OpenRegister.integrations  ← JS registry           │
 │              │                                                    │
 │              │ resolves by id                                     │
 │              ▼                                                    │
 │   ┌────────────────┐          ┌──────────────────┐                │
 │   │ CnObjectSidebar│          │ CnDashboardPage  │                │
 │   │  renders tabs  │          │  renders widgets │                │
 │   │  dynamically   │          │  dynamically     │                │
 │   └────────────────┘          └──────────────────┘                │
 └──────────────────────────────────────────────────────────────────┘
```

## Architecture Decisions

### AD-1: Backend registration via DI tags

**Decision**: `IntegrationProvider` implementations register via Nextcloud's DI container using a `@IntegrationProvider` tag (injected through `Application::register()` in each app's bootstrap). `IntegrationRegistry` reads tagged services at request time.

**Why**: DI-tag registration is the idiomatic NC pattern (used for event listeners, middleware, capabilities). It means external apps (OpenConnector-backed providers) can register their own providers without touching OpenRegister core. Alternative — a hardcoded provider list or a static registry populated at bootstrap — couples core to every integration.

**Trade-off**: DI-tag reads are per-request; for hot paths we cache the resolved provider map in `RequestScopedCache` (already present).

### AD-2: Frontend registration via window-scoped registry

**Decision**: The JS registry lives at `window.OCA.OpenRegister.integrations`. Integrations call `integrations.register({ id, tab, widget, ... })` from their app bootstrap. `CnObjectSidebar` and `CnDashboardPage` call `integrations.list()` to render.

**Why**: Nextcloud's frontend has no standard registry pattern. Module-level imports would require `CnObjectSidebar` to statically import every integration — defeating the purpose. A window-scoped registry mirrors the backend (every provider has a JS-side counterpart) and uses the pattern already established by `OCA.Files`, `OCA.Viewer`, etc.

**Trade-off**: Bundling discipline matters — an integration that fails to register (wrong load order) silently vanishes from the sidebar. Mitigated by (a) the parity CI check catching missing declarations pre-merge and (b) a dev-mode console warning when a provider is registered backend-side but not frontend-side.

### AD-3: Widget parity is a CI-enforced hard rule

**Decision**: Registering an integration without both a tab and widget component fails pre-commit (local) and fails the hydra quality gate (pipeline). No tab-only integrations permitted.

**Why**: The user has explicit preference for full parity. Making parity a hard rule enforces it from day one; softer "warnings" drift. A `scripts/check-integration-parity.sh` walks each provider's JS registration and asserts both `tab` and `widget` are set to components that resolve.

**Trade-off**: Slower onboarding for a new integration — both UI surfaces must exist before merge. Acceptable: the work is small (one shell widget can wrap the tab's data for MVP), and the user experience benefit is real.

### AD-4: External integrations route via OpenConnector

**Decision**: Providers declare `storage: "external"` with an `openconnector_source: "..."` reference. When the sub-resource endpoint is hit, `IntegrationRegistry` delegates to the OpenConnector client, passing the object context (register/schema/id) as request context. There is no local link table for external entities; pairings are stored in OpenConnector's own pairing model or computed per-request.

**Why**: OpenConnector is the existing pattern for external-service integration. Reusing it avoids inventing a second external-integration mechanism and lets OR stay focused on its own primitives. The `storage` strategy becomes a first-class switch: `magic-column` (legacy), `link-table`, `external`.

**Trade-off**: External integrations are higher-latency and can fail for reasons unrelated to OR (external service down, OAuth expired). The UI must handle graceful degradation — `IntegrationProvider::health()` returns a status that the tab/widget can display.

### AD-4b: Schema `linkedTypes` is the relevance layer, not the existence layer

**Decision**: `Schema::validateLinkedTypesValue()` stops comparing against the hardcoded `VALID_LINKED_TYPES` constant and instead calls `IntegrationRegistry::listIds()`. The schema's `configuration.linkedTypes` array is the **relevance filter**, not the gate on what exists. An empty/absent `linkedTypes` means "no integrations explicitly enabled" (not "all" — matches current behaviour). A present array is a whitelist.

**Why**: The user flagged that "schemas and sidebar components can already influence what's available." That observation is correct — the mechanism is real but its semantics were tangled. Clarifying: registry = existence, schema = relevance, component = context. Keeping the schema as the relevance layer means consuming apps can continue to declare per-schema relevance (e.g., only `files` + `notes` on a `zaak` schema) while the registry evolves independently.

**Trade-off**: Backwards-incompat risk if any current schema has `linkedTypes` referencing an id that isn't yet registered (e.g., a schema set to `["talk"]` on an instance without the Talk provider registered). Mitigated by making registration permissive of historical ids — validation warns but doesn't reject ids-not-currently-registered on READ; it only rejects on WRITE when adding a new id. A one-time migration logs any schemas with dangling linked types.

### AD-4c: Widget surfaces — one component, contextual rendering

**Decision**: One widget component per integration is registered. It receives a `surface` prop at render time: `'user-dashboard' | 'app-dashboard' | 'detail-page'`. The component decides what to render per surface — typically compact summary on dashboards, richer interaction on the detail page. Extra surface variants (e.g., a distinct "collapsed kanban card" view) are declared via additional registration keys (`widgetCompact`, `widgetExpanded`) but default to the main `widget` when absent.

**Why**: The user clarified widgets serve three surfaces, not one. Forcing integrations to ship three components raises the parity bar painfully and duplicates logic. A single component with a `surface` prop lets simple integrations do nothing and renders the same thing everywhere; rich integrations branch on `surface`.

**Trade-off**: Integrations that differ dramatically across surfaces have a bulkier single component. Acceptable — and the optional `widgetCompact` / `widgetExpanded` registration keys exist for truly divergent cases. Parity gate still only requires `widget`; extras are opt-in.

### AD-5: Tags and audit trail become IntegrationType entries

**Decision**: `tags` and `audit-trail` are first-class `IntegrationProvider`s, not special-case tabs. They are "built-in always-available" — their `required_app` is OpenRegister itself, so they are never absent — but they ride the same registry machinery.

**Why**: Uniformity wins. Special cases breed special cases. Making tags and audit first-class reveals their missing widgets as a parity gap the umbrella must fill (`CnTagsCard`, `CnAuditTrailCard`), which is a real product improvement.

**Trade-off**: Mild — two integrations exist "for free" that don't map to an external NC app. The registry handles this naturally; the cost is just the label `required_app: null` meaning "always available."

### AD-6: Migration is structural, not data-level

**Decision**: The existing `LinkedEntityService::TYPE_COLUMN_MAP` constant is kept as an internal implementation detail of the built-in providers (files, notes, todos). The `openregister_*_links` tables keep their schema. Nothing moves on disk.

**Why**: Data migration risk is zero-value here. The refactor is purely about how the code *exposes* the types, not how they're stored. Keeping storage unchanged means the change is revertable and easy to validate against existing data.

**Trade-off**: Built-in providers carry a small amount of legacy-aware code (delegating to `LinkedEntityService` internally). Acceptable; it will be cleaned up if/when storage models ever unify.

### AD-7: Backwards compatibility for `CnObjectSidebar`

**Decision**: The current `CnObjectSidebar` prop surface (`hidden-tabs`, `files-label`, named slots like `tab-notes`) continues to work. New consumers use the registry-driven behaviour by default; existing consumers opt out via an `integrations` prop or continue relying on slots, which still override registry-resolved components.

**Why**: Required by the shared-library contract ("NEVER break existing prop interfaces"). Every consuming app — OpenCatalogi, Procest, Pipelinq, MyDash — must keep working unchanged on upgrade.

**Trade-off**: Slightly more complex rendering logic inside `CnObjectSidebar`. Offset by extensive tests.

### AD-8: Leaf-change contract

**Decision**: Each leaf change that adds an integration must ship:

1. `IntegrationProvider` implementation (PHP)
2. Controller (if storage is `link-table` or external; built-in `magic-column` integrations reuse the object controller)
3. Sidebar tab component (Vue)
4. Card widget component (Vue)
5. Registry declaration (backend DI tag + frontend `integrations.register()` call)
6. Spec delta against the `generic-integrations` capability
7. Unit tests for the provider; component tests for tab + widget
8. nl + en translations

Leaves that miss any of these fail the parity gate at merge time.

**Why**: Establishes a predictable recipe so leaf changes are hydra-sized and reviewable. Avoids the "UI came later" drift that produced the current gap.

**Trade-off**: Onboarding overhead per integration; mitigated by a scaffold script (`scripts/scaffold-integration.sh <id>`) delivered in this umbrella.

### AD-9: Dashboard / detail page composition is left to consumers

**Decision**: The umbrella registers widgets; it does **not** decide which widgets appear on which dashboard or detail page. Each consuming app (OpenCatalogi, Procest, ...) composes its own surfaces by selecting a subset from `integrations.list()` and providing a layout — same mechanism for user dashboard, app dashboard, and detail page widget grid.

**Why**: Surfaces are app-specific. OpenCatalogi's home dashboard has little in common with Procest's case detail page. Centralising that decision would reintroduce coupling the registry is designed to remove.

**Trade-off**: Each consuming app does one-time work per surface to declare its layout. Covered by `CnDashboardPage`'s existing `widgets` + `layout` props — no new mechanism needed.

### AD-11: Naming collision policy

**Decision**: `integrations.register(...)` with an id already registered throws synchronously in development mode and logs a warning + keeps the first registration in production. PHP-side, two providers tagged with the same id cause container build failure (loud).

**Why**: Silent overwrite is the worst option — debugging "why is the wrong tab rendering" with overwrites is brutal. Dev-mode throw catches it during integration; production warn-and-keep avoids breaking deploy if two NC apps both happen to register the same id (e.g., two competing forms apps).

**Trade-off**: Two well-meaning apps competing for an id (`forms` from NC Forms vs `forms` from Cospend Forms) need to coordinate. The registry exposes this via the `IntegrationsController` health endpoint so admins can see collisions.

### AD-13: Provider contract is "linked thing"–shaped, not NC-entity–specific

**Decision**: Even though this umbrella ships only NC-native + external-via-OpenConnector providers, the `IntegrationProvider` interface uses generic terminology ("linked entity", "thing", "item") rather than NC-specific terms. This keeps the door open for `RelationsService` (object↔object) to be subsumed under the registry in a future change without breaking the contract.

**Why**: The user explicitly chose "stay focused on NC entities now, but design relations-shaped for future unification." Naming and method shapes set early are hard to change later. Saying `list($register, $schema, $objectId)` rather than `listNcEntitiesForObject(...)` costs nothing now and unlocks future flexibility.

**Trade-off**: Slightly less self-documenting — a reader has to know "this is currently NC entities only, but the contract is more general." Mitigated by clear docblock on the interface.

### AD-14: External integrations declare auth requirements; OR shows admin UI

**Decision**: `IntegrationProvider::authRequirements()` returns one of `'none' | 'oauth2' | 'api-key' | 'basic'` plus a config schema describing the credential shape. OpenRegister provides a unified admin UI (under settings) showing each integration's auth status, with "Configure" buttons that delegate to OpenConnector's existing credential management. The provider itself never handles credentials — it asks OpenConnector for them when making external calls.

**Why**: The user picked "umbrella defines auth declaration." The motivation is unified visibility — without this, each external integration ships its own auth setup screen and admins have no central view of "what's configured / broken / expired." Reusing OpenConnector for the actual auth flow keeps OR out of the credentials business.

**Trade-off**: Adds an admin UI surface (small) and a method to the provider contract. Worth it for the unified status view, especially given OCS capability advertising (AD-16) surfaces this status to clients too.

### AD-15: Per-integration RBAC via optional `requiresPermission()`

**Decision**: `IntegrationProvider::requiresPermission(): ?string` defaults to `null` (no extra check). When set, it returns a permission string evaluated against the user's permissions on the object (using OR's existing `AuthorizationService`). Integrations with no extra requirement inherit access from the underlying object's RBAC + the NC app's own permissions (e.g., `calendar` integration is hidden if user can't access NC Calendar).

**Why**: User picked "optional permission, default null." Most integrations don't need extra RBAC — file access on the object implies file integration access, etc. The few that do (e.g., "audit trail visible to admins only") get a clean opt-in. Avoids over-engineering — no DSL, just a permission string.

**Trade-off**: A permission string is less expressive than a DSL. If real cases demand more (e.g., "handler OR admin"), the contract evolves later — but YAGNI for now.

### AD-16: Registry advertised via Nextcloud's OCS capabilities endpoint

**Decision**: OR's `CapabilitiesService` is extended to include an `integrations` block in the response from `/ocs/v2.php/cloud/capabilities`. Block contains: `{ id, label, group, enabled, requiresPermission, authStatus, surfaces[] }` for each registered integration on the instance. Full per-object operations remain at `/api/integrations` and the sub-resource endpoints — capabilities is for *discovery*, not *operation*.

**Why**: User picked "expose via capabilities." External clients (mobile apps, partner integrations, other NC apps) get a standard discovery mechanism — they can do feature-detection without polling OR-specific endpoints. Free to add now, awkward to add later (every client would have to dual-path).

**Trade-off**: Slightly bigger capabilities response. Mitigated by the registry being small (target: <50 integrations forever).

### AD-17: Reference-property auto-rendering via `single-entity` widget surface

**Decision**: A new widget surface `single-entity` is added to the existing three (`user-dashboard`, `app-dashboard`, `detail-page`). When a schema property is typed as a reference to an NC entity (via a new `referenceType: <integration-id>` marker on the property), `CnFormDialog` and `CnDetailGrid` detect it and render the matching integration's widget at `surface: 'single-entity'`. The widget receives the entity id as `entityId` and renders a compact card.

The `referenceType` marker is added to the schema property type system as an optional sibling of `type`/`format`/`enum`. Backwards-compatible — schemas without it behave exactly as today.

**Why**: User picked "in scope — reference properties auto-render via integration widget." The motivation is consistency: if `assignedHandler` is a contact uuid, the user shouldn't see a raw uuid string — they should see the contact's card. The same widget that renders the contact in the sidebar tab should render it inline next to the property. One source of truth.

**Trade-off**: This expands the umbrella significantly — `CnFormDialog`, `CnDetailGrid`, schema property types, and the widget contract all gain new responsibilities. Mitigated by:
- Reference properties opt in via the new marker (no automatic migration of existing schemas)
- The `single-entity` surface gracefully falls back to the main `widget` for integrations that don't ship a dedicated single-entity rendering (per AD-18)
- A leaf change can opt out by not declaring `referenceType`

### AD-18: New surfaces use graceful fallback to main `widget`

**Decision**: When a render request specifies a `surface` the integration didn't register a specific widget for, the registry falls back to the main `widget` component, passing the surface name as a prop so the component can branch internally if it wants. Adding a future surface (e.g., `email-digest`, `printed-pdf`, `mobile-card`) requires zero re-registration from existing integrations — they just keep working with their main widget.

**Why**: User picked "graceful fallback." Future-proofing without breaking changes. Integrations that care about a new surface opt in; integrations that don't, don't.

**Trade-off**: An integration's main widget might render awkwardly on a brand-new surface it wasn't designed for. Acceptable — better than every existing integration breaking when a new surface is added. Per-surface opt-in is explicit (`widgetCompact`, `widgetExpanded`, `widgetEntity`, future `widgetX`), so deliberate quality work happens where it matters.

### AD-19: `LinkedEntityService::TYPE_COLUMN_MAP` deprecated, removed in follow-up

**Decision**: This umbrella marks the constant `@deprecated` with a doc-block pointing to the registry. Built-in `link-table` providers (mail, contacts, deck) use it internally for one more cycle to minimise migration risk. A follow-up change `cleanup-linked-entity-type-map` removes the constant entirely once those providers are stable and any external consumers have moved off it (we don't expect any — the constant was always private-by-convention).

**Why**: User picked "deprecated for removal in a follow-up." Clean end state, but rolling change minimises risk of breaking subtle behaviour during the umbrella's already-large surface area.

**Trade-off**: A follow-up change is now obligatory. Tracked in the leaf-plan section of proposal.md as a Wave 0.5 item.

### AD-12: Apps consume OR abstractions over local duplication (companion ADR)

**Decision**: This umbrella references — and depends on — a companion org-wide ADR ("Apps consume OpenRegister abstractions") that should live in `hydra/openspec/architecture/` (likely as ADR-019 or 020). That ADR is *not authored by this change*; it is flagged as required and tracked as a separate proposal in the hydra repo.

**Why**: The integration registry is a concrete instance of a broader principle: Conduction apps should hook into OpenRegister abstractions (registers, schemas, objects, integrations, RBAC, audit, archival, ...) rather than build parallel mechanisms. That principle applies far beyond integrations and deserves its own ADR. Codifying it explicitly prevents future drift where an app reinvents, say, a sidebar tab system or a relations table.

**Trade-off**: This change creates a dependency on a not-yet-written ADR. Acceptable — the ADR is small, the principle is well-understood, and writing it is a parallel task that doesn't block implementation here.

### AD-10: Capability visibility based on installed NC apps

**Decision**: `IntegrationProvider::isEnabled()` checks whether the required NC app is installed + enabled. Disabled integrations are filtered out of `IntegrationRegistry::list()`. The UI never shows a tab for a disabled integration (no "install Deck to use this" placeholders).

**Why**: An integration that requires Deck shouldn't appear on a system that doesn't have Deck. Showing it then immediately failing is worse than not showing it.

**Trade-off**: Consumers who want admin-facing "install this app to unlock X" prompts need to build that themselves; it's out of scope for the registry.

## The contract (normative)

### PHP `IntegrationProvider` interface

```php
/**
 * Provider for an integration that exposes a "linked thing" against an OR object.
 *
 * Currently used for NC-native and external (via OpenConnector) entities, but
 * the contract is shaped generically so RelationsService (object↔object) can
 * be unified under the same registry in a future change without breaking changes.
 */
interface IntegrationProvider
{
    /** Stable id: 'files', 'email', 'calendar', 'openproject', ... */
    public function getId(): string;

    /** Translatable label ('Emails', 'Meetings'). */
    public function getLabel(): string;

    /** MDI icon name (matches frontend icon). */
    public function getIcon(): string;

    /** Optional named group: 'core' | 'comms' | 'docs' | 'workflow' | 'external'. Null = ungrouped. */
    public function getGroup(): ?string;

    /** NC app id the integration requires, or null for always-available. */
    public function getRequiredApp(): ?string;

    /** 'magic-column' | 'link-table' | 'external' */
    public function getStorageStrategy(): string;

    /** Whether the integration is currently usable on this NC instance. */
    public function isEnabled(): bool;

    /**
     * Optional permission required to use this integration on a given object.
     * Null (default) = inherits from object RBAC + underlying NC app permissions.
     * String = checked against AuthorizationService for the current user on the object.
     */
    public function requiresPermission(): ?string;

    /**
     * Auth requirements (for external integrations primarily).
     * Returns ['type' => 'none'|'oauth2'|'api-key'|'basic', 'configSchema' => [...]].
     * OpenRegister surfaces this in admin UI; OpenConnector handles the actual flows.
     * Built-in NC integrations return ['type' => 'none'].
     */
    public function authRequirements(): array;

    /** List linked things for an object. */
    public function list(string $register, string $schema, string $objectId, array $filters = []): array;

    /** Get a single linked thing by id (used by surface='single-entity' rendering). */
    public function get(string $register, string $schema, string $objectId, string $entityId): array;

    /** Create/attach a linked thing. */
    public function create(string $register, string $schema, string $objectId, array $payload): array;

    /** Update a linked thing. */
    public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array;

    /** Delete/unlink a linked thing. */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void;

    /**
     * Health + auth status for display.
     * Returns ['status' => 'ok'|'degraded'|'unavailable', 'authStatus' => 'configured'|'missing'|'expired', 'message' => ?string].
     */
    public function health(): array;
}
```

### JS registration shape

```js
OCA.OpenRegister.integrations.register({
    id: 'calendar',
    label: t('pipelinq', 'Meetings'),
    icon: 'Calendar',              // MDI name
    requiredApp: 'calendar',
    order: 10,                     // numeric ordering hint
    group: 'comms',                // optional named group: 'core' | 'comms' | 'docs' | 'workflow' | 'external'
    requiresPermission: null,      // optional: permission string checked against AuthorizationService
    referenceType: 'calendar',     // marker that schema properties of type 'reference' may target this integration
    tab: CnCalendarTab,            // Vue component — REQUIRED
    widget: CnCalendarCard,        // Vue component — REQUIRED. Receives :surface prop at render
    widgetCompact: CnCalendarMini, // optional — used by surface='user-dashboard' if present
    widgetExpanded: CnCalendarFull,// optional — used by surface='detail-page' if present
    widgetEntity: CnCalendarChip,  // optional — used by surface='single-entity' (reference-property render) if present
    defaultSize: { w: 3, h: 3 },   // default grid dimensions on dashboards
})
```

**Surface fallback rule (AD-18)**: any surface without a dedicated component falls back to `widget`, with the `surface` prop passed so the component can branch internally.

**Surface registry (current)**: `'user-dashboard' | 'app-dashboard' | 'detail-page' | 'single-entity'`. Future surfaces are added by adding a new key to the surface enum and an optional `widget<Surface>` registration key — existing integrations keep working via fallback.

A missing `tab` or `widget` throws synchronously at registration time (development mode) and is caught by the parity CI check (production mode — never reaches merge). `widgetCompact` and `widgetExpanded` are optional fallbacks for surface-specific rendering when a single component would be too divergent.

### Registration timing and collision handling

```js
// Late-loaded apps may register after CnObjectSidebar mounts.
// The registry is reactive — components re-render when integrations register.
OCA.OpenRegister.integrations.onChange((integrations) => { ... })

// Collision: two apps register id 'forms'
OCA.OpenRegister.integrations.register({ id: 'forms', ... }) // first call wins
OCA.OpenRegister.integrations.register({ id: 'forms', ... }) // dev: throws; prod: warns, ignored
```

Bootstrap order: the registry shim (`window.OCA.OpenRegister.integrations`) is created by OpenRegister's main bundle; consuming apps load after. If a consuming app's bundle loads before OpenRegister's, the call is queued via a stub and replayed when the real registry initialises.

### REST endpoint shape

The sub-resource pattern from `object-interactions` is preserved:

```
GET    /api/objects/{register}/{schema}/{id}/{integrationId}
POST   /api/objects/{register}/{schema}/{id}/{integrationId}
GET    /api/objects/{register}/{schema}/{id}/{integrationId}/{entityId}
PUT    /api/objects/{register}/{schema}/{id}/{integrationId}/{entityId}
DELETE /api/objects/{register}/{schema}/{id}/{integrationId}/{entityId}

GET    /api/integrations                    ← enumerate registered integrations
GET    /api/integrations/{integrationId}    ← single integration metadata + health
```

`{integrationId}` is the `id` returned by `IntegrationProvider::getId()`. `ObjectsController` routes sub-resource calls through `IntegrationRegistry::get($integrationId)` and delegates to the provider.

## Files Affected

### New files — Backend

| File | Purpose |
|---|---|
| `lib/Service/Integration/IntegrationProvider.php` | Interface (relations-shaped, generic "linked thing") |
| `lib/Service/Integration/AbstractIntegrationProvider.php` | Base class with sensible defaults (group=null, requiresPermission=null, authRequirements=['type'=>'none']) |
| `lib/Service/Integration/IntegrationRegistry.php` | Service resolving DI-tagged providers |
| `lib/Service/Integration/ExternalIntegrationRouter.php` | Routes `storage: external` to OpenConnector + auth status |
| `lib/Service/Integration/BuiltinProviders/FilesProvider.php` | Wraps existing files integration |
| `lib/Service/Integration/BuiltinProviders/NotesProvider.php` | Wraps existing notes integration |
| `lib/Service/Integration/BuiltinProviders/TasksProvider.php` | Wraps existing todos integration |
| `lib/Service/Integration/BuiltinProviders/TagsProvider.php` | Wraps existing tags integration |
| `lib/Service/Integration/BuiltinProviders/AuditTrailProvider.php` | Wraps existing audit trail |
| `lib/Controller/IntegrationsController.php` | `GET /api/integrations` + single + admin auth-status |
| `lib/Settings/IntegrationsAdminSection.php` | Admin section: per-integration auth status + Configure buttons |
| `tests/Unit/Service/Integration/IntegrationRegistryTest.php` | Unit tests |
| `tests/Unit/Service/Integration/CapabilitiesIntegrationTest.php` | Asserts OCS capabilities block matches registry |

### Modified files — Backend

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | Register built-in providers as DI-tagged services |
| `lib/Controller/ObjectsController.php` | Route sub-resource calls through `IntegrationRegistry` |
| `lib/Service/LinkedEntityService.php` | Retained; `TYPE_COLUMN_MAP` marked `@deprecated` (removal in follow-up cleanup change) |
| `lib/Service/CapabilitiesService.php` | Add `integrations` block to OCS capabilities response |
| `lib/Db/Schema.php` | `validateLinkedTypesValue()` consumes registry instead of `VALID_LINKED_TYPES` constant; constant marked `@deprecated`. Add `referenceType` to property type validation. |
| `appinfo/routes.php` | Add `/api/integrations` routes |
| `appinfo/info.xml` | Declare capability: `generic-integrations` |

### New files — Frontend (`@conduction/nextcloud-vue`)

| File | Purpose |
|---|---|
| `src/integrations/registry.js` | `window.OCA.OpenRegister.integrations` implementation |
| `src/composables/useIntegrationRegistry.js` | Vue composable to reactively read registry |
| `src/components/CnFilesCard/CnFilesCard.vue` | Fill parity gap |
| `src/components/CnTagsCard/CnTagsCard.vue` | Fill parity gap |
| `src/components/CnAuditTrailCard/CnAuditTrailCard.vue` | Fill parity gap |
| `src/integrations/builtin/files.js` | Register built-in `files` integration |
| `src/integrations/builtin/notes.js` | Register built-in `notes` integration |
| `src/integrations/builtin/tasks.js` | Register built-in `tasks` integration |
| `src/integrations/builtin/tags.js` | Register built-in `tags` integration |
| `src/integrations/builtin/audit-trail.js` | Register built-in `audit-trail` integration |
| `tests/integrations/registry.test.js` | Unit tests for the JS registry |

### Modified files — Frontend

| File | Change |
|---|---|
| `src/components/CnObjectSidebar/CnObjectSidebar.vue` | Render tabs from registry (three-stage filter); preserve existing slots for backwards compat |
| `src/components/CnDashboardPage/CnDashboardPage.vue` | Support widget resolution from registry (opt-in prop); pass `surface='user-dashboard'` or `'app-dashboard'` |
| `src/components/CnDetailPage/CnDetailPage.vue` | Pass `surface='detail-page'` when rendering registered widgets |
| `src/components/CnFormDialog/CnFormDialog.vue` | Detect `referenceType` on schema properties; render integration's `single-entity` widget |
| `src/components/CnDetailGrid/CnDetailGrid.vue` | Same — render `referenceType` properties via integration widget |
| `src/index.js` | Export `integrations` registry + new widgets + composable |
| `CLAUDE.md` | Document the integration registry API for agents |

### New files — Repo (governance + docs)

| File | Purpose |
|---|---|
| `hydra/openspec/architecture/adr-019-integration-registry.md` | Org-wide ADR (lives in hydra repo) |
| `docs/integrations/README.md` | Developer guide — "How to add an integration" |
| `scripts/check-integration-parity.sh` | CI parity gate |
| `scripts/scaffold-integration.sh` | One-shot scaffold for leaf changes |
| `.github/workflows/integration-parity.yml` | CI workflow running the parity check |

**Companion change (separate, not in this umbrella):**

| File | Purpose |
|---|---|
| `hydra/openspec/architecture/adr-020-apps-consume-or-abstractions.md` | Org-wide principle ADR — flagged as required, lives in a separate hydra change |

## Risks

| Risk | Mitigation |
|---|---|
| Backwards-incompat break in `CnObjectSidebar` | Hard requirement that existing props + slots continue to work; extensive snapshot tests on the 5 existing tabs |
| Leaf changes drift from the contract | Parity CI gate + scaffold script + hydra quality gate rejects incomplete providers |
| External providers fail at runtime, breaking the sidebar UX | `IntegrationProvider::health()` surfaced in the tab header; degraded state renders as an empty state with an error message, not a broken tab |
| Bundle size grows as integrations proliferate | Tab + widget components are registered but dynamically imported (code-split per integration); consumer apps only load integrations they actually use via tree-shake on `integrations.register()` calls |
| DI-tag registration subtly breaks in unusual NC versions | Fallback: built-in providers are registered explicitly in `Application::register()` as well; only third-party providers rely purely on tags |
| Parity rule blocks rapid experimentation | The scaffold script ships a minimal passing widget stub alongside a richer tab; MVP parity is cheap |

## Open questions (for the umbrella only)

The three pre-iteration questions have been resolved into the design:
- Ordering — numeric `order` + optional named `group` (AD-11 / JS shape)
- Per-app filter — `excludeIntegrations` prop, mirrors existing `hidden-tabs` (AD-7)
- Widget size — `defaultSize: {w, h}` at registration (JS shape)

Ten new open questions surfaced during iteration are listed in the companion message — they need answers before `tasks.md` and `spec.md` can be finalised.
