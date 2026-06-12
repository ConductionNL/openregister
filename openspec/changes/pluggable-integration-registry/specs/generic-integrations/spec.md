---
status: proposed
---

# Generic Integrations

## Purpose

OpenRegister exposes a uniform contract for "things linked to an object" — Nextcloud-native entities (files, notes, tasks, calendar events, mail, contacts, deck cards, talk conversations) and external services (OpenProject, XWiki, etc.) — so consuming apps and third-party integrators can extend OR's reach without modifying OR's core or `@conduction/nextcloud-vue`.

This spec defines the **integration registry**: a backend service + frontend registry + provider contract + three-stage filter (registry existence → schema relevance → component context) that governs which integrations are visible where.

**Standards**: Nextcloud OCS Capabilities, JSON Schema, RFC 5545 (iCalendar), RFC 6350 (vCard), Nextcloud DI tag system
**Cross-references**: [nextcloud-entity-relations](../../../../specs/nextcloud-entity-relations/spec.md), [object-interactions](../../../../specs/object-interactions/spec.md), [authorization-rbac](../../../../specs/authorization-rbac/spec.md)

---

## ADDED Requirements

### Requirement: Integration Provider Contract

The system SHALL define a PHP interface `OCA\OpenRegister\Service\Integration\IntegrationProvider` that every backend integration implements. The interface SHALL be relations-shaped (generic "linked thing" terminology) so that future unification with `RelationsService` (object↔object) is possible without breaking the contract.

#### Rationale

A uniform contract is the entire point of a registry. Each integration ships a vertical slice (provider + tab + widget); the contract guarantees they can be composed without core knowing about them individually.

#### Scenario: A new integration implements the contract

- **GIVEN** a developer wants to add a new integration named `forms`
- **WHEN** they create a class `FormsProvider implements IntegrationProvider`
- **THEN** their class MUST implement: `getId()`, `getLabel()`, `getIcon()`, `getGroup()`, `getRequiredApp()`, `getStorageStrategy()`, `getOpenConnectorSource()`, `isEnabled()`, `requiresPermission()`, `authRequirements()`, `list()`, `get()`, `create()`, `update()`, `delete()`, `health()`
- **AND** the class MUST be registered as a DI-tagged service with tag `IntegrationProvider`
- **AND** `IntegrationRegistry::list()` MUST return the new provider on the next request

#### Scenario: A provider with no required app is always available

- **GIVEN** a provider returns `null` from `getRequiredApp()`
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the provider MUST be included in the result regardless of which Nextcloud apps are installed

#### Scenario: A provider whose required NC app is missing is hidden

- **GIVEN** a provider returns `'deck'` from `getRequiredApp()` and the Deck app is not installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the provider MUST be excluded from the result
- **AND** `GET /api/integrations` MUST NOT list it
- **AND** `CnObjectSidebar` MUST NOT render its tab

---

### Requirement: Storage Strategy Enum

`IntegrationProvider::getStorageStrategy()` SHALL return one of four values: `'magic-column'`, `'link-table'`, `'external'`, `'query-time'`. Any other value SHALL be rejected by the registry at registration time.

- `magic-column` — link stored as a column on the OR object row (legacy built-ins).
- `link-table`   — link stored in a dedicated `openregister_*_links` table.
- `external`     — no local persistence; CRUD routed through OpenConnector. Provider SHALL also implement `getOpenConnectorSource()` returning a non-null source id.
- `query-time`   — no local persistence; the source system is queried live on every `list()` call. Mutation methods SHALL throw `NotImplementedException`.

#### Scenario: query-time provider rejects mutation

- **GIVEN** a provider returning `'query-time'` from `getStorageStrategy()`
- **WHEN** `create()`, `update()`, or `delete()` is invoked
- **THEN** the provider MUST throw `NotImplementedException`
- **AND** the controller layer MUST translate the exception to HTTP 501 with a body that names the source-of-truth NC service

#### Scenario: query-time provider exceeding timeout returns degraded surface

- **GIVEN** a `query-time` provider whose upstream service does not respond within the per-render timeout (default 2s)
- **WHEN** the surface (tab or widget) renders
- **THEN** the surface MUST render the degraded "source slow — retrying in background" state
- **AND** the request MUST NOT block the page beyond the timeout
- **AND** a structured log entry MUST be emitted with `{integration, surface, timeout_ms}`

#### Scenario: external provider missing OpenConnector source id is rejected

- **GIVEN** a provider returning `'external'` from `getStorageStrategy()` and `null` from `getOpenConnectorSource()`
- **WHEN** the registry resolves it
- **THEN** the registry MUST reject the provider with a clear error and exclude it from `getEnabled()`

---

### Requirement: External Provider Failure Modes

`ExternalIntegrationRouter` and `external` providers SHALL distinguish three failure modes via `ProviderUnavailableException` with a `details.cause` field of `'openconnector-down' | 'openconnector-source-missing' | 'upstream-service-down'`. Auth status SHALL be checked lazily — providers MUST NOT issue a health probe to OpenConnector on every render. A 401 from the upstream service SHALL be translated to `ProviderAuthException` with a `reconnectUrl`.

#### Scenario: OpenConnector NC app disabled

- **GIVEN** the OpenConnector NC app is disabled
- **WHEN** `OpenProjectProvider::list()` is called
- **THEN** the call MUST throw `ProviderUnavailableException`
- **AND** `details.cause` MUST equal `'openconnector-down'`
- **AND** the UI MUST render "Connector unavailable — admin: enable OpenConnector"

#### Scenario: OpenConnector source for the provider is missing

- **GIVEN** OpenConnector is enabled but no source named `openproject` is configured
- **WHEN** `OpenProjectProvider::list()` is called
- **THEN** `details.cause` MUST equal `'openconnector-source-missing'`
- **AND** the admin UI MUST link to OpenConnector's source-creation flow

#### Scenario: Upstream service unreachable

- **GIVEN** OpenConnector reaches `openproject` but the OpenProject API is unreachable
- **WHEN** `OpenProjectProvider::list()` is called
- **THEN** `details.cause` MUST equal `'upstream-service-down'`
- **AND** the UI MUST render "OpenProject offline — last seen <timestamp>"

#### Scenario: Lazy auth — token refresh handled by OpenConnector

- **GIVEN** an `external` provider whose tokens are refreshable via OpenConnector
- **WHEN** the provider invokes an upstream call without first calling `health()`
- **THEN** the provider MUST trust OpenConnector to refresh tokens silently
- **AND** only when the upstream returns 401 MUST the provider raise `ProviderAuthException`

---

### Requirement: Three-Stage Visibility Filter

The system SHALL filter integrations through three independent stages — registry existence, schema relevance, component context — before rendering. Each stage SHALL be observable so a developer can determine why a given integration is or isn't shown.

#### Rationale

Hardcoded visibility logic is the source of the current rigidity. Splitting the decision into three stages with distinct ownership (system / schema author / page author) lets each evolve independently.

#### Storage Model

```
Stage 1: IntegrationRegistry::getEnabled() — filtered by Provider::isEnabled()
Stage 2: Schema.configuration.linkedTypes — explicit whitelist (absent = empty)
Stage 3: Component prop excludeIntegrations — per-render override
```

#### Scenario: Schema with empty linkedTypes shows no integrations

- **GIVEN** a schema with `configuration.linkedTypes` absent or set to `[]`
- **WHEN** `CnObjectSidebar` renders for an object of that schema
- **THEN** no integration tabs MUST be shown
- **AND** the audit/tags built-in tabs (which are integrations themselves) MUST also be hidden unless the schema explicitly lists them

#### Scenario: Schema linkedTypes acts as a whitelist

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes", "calendar"]`
- **WHEN** `CnObjectSidebar` renders for an object of that schema
- **THEN** only the `files`, `notes`, and `calendar` tabs MUST be shown (assuming each is enabled in the registry)

#### Scenario: Component-level exclusion overrides schema relevance

- **GIVEN** a schema with `linkedTypes: ["files", "notes", "calendar"]`
- **WHEN** `<CnObjectSidebar :exclude-integrations="['calendar']">` renders
- **THEN** only `files` and `notes` tabs MUST be shown

#### Scenario: Schema validator accepts any registered integration id

- **GIVEN** an integration `forms` is registered via DI tag
- **WHEN** a schema is saved with `configuration.linkedTypes: ["forms"]`
- **THEN** `Schema::validateLinkedTypesValue()` MUST accept the value without error
- **AND** the validation MUST NOT consult the deprecated `Schema::VALID_LINKED_TYPES` constant

---

### Requirement: Widget-Parity Hard Rule

The system SHALL refuse to merge a change that registers an `IntegrationProvider` (frontend or backend) without a corresponding tab AND widget component. The check SHALL run in pre-commit, repository CI, and the hydra quality gate.

A `tab` or `widget` value is considered "set" only when **all** of the following hold:

- the registration object has the key (the key is present, not omitted),
- the value is not `null` and not `undefined`,
- `typeof value === 'function'` (a Vue component constructor or async-component factory) — plain object literals, primitives, and `false` MUST be rejected.

#### Rationale

User explicit preference — every integration gets both surfaces. Enforcing it at registration time prevents drift; making it a CI gate prevents merges from sneaking past local hooks. Without an executable definition of "set", a registration like `{tab: null, widget: FooCard}` could pass a naive presence check while breaking at render time.

#### Scenario: A registration without a widget fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab })` (missing `widget`)
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with an error naming the integration id and the missing component
- **AND** the CI workflow `integration-parity` MUST fail
- **AND** the hydra quality gate MUST report the failure under gate `integration-parity`

#### Scenario: A registration with `widget: null` fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab, widget: null })`
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with an error naming the integration id and that `widget` is null

#### Scenario: A registration with a non-component value fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab, widget: {} })` (object literal, not a function)
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with an error stating that `widget` is not a Vue component (typeof !== 'function')

#### Scenario: A registration without a tab fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', widget: FooCard })` (missing `tab`)
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with the same shape of error

#### Scenario: A complete registration passes

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab, widget: FooCard, label: 'Foo', icon: 'Foo' })`
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit zero

---

### Requirement: Widget Surfaces

Every registered widget SHALL render correctly in four surfaces: `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`. The `surface` SHALL be passed as a prop to the widget component. Optional surface-specific components (`widgetCompact`, `widgetExpanded`, `widgetEntity`) SHALL be used when present; otherwise the registry SHALL fall back to the main `widget`.

#### Scenario: Default widget renders on all surfaces

- **GIVEN** an integration registers only `widget: FooCard` with no surface-specific variants
- **WHEN** the widget is rendered on any of the four surfaces
- **THEN** `FooCard` MUST be rendered with the `surface` prop set appropriately
- **AND** `FooCard` MAY branch on `surface` internally

#### Scenario: Surface-specific widget overrides the default

- **GIVEN** an integration registers `widget: FooCard` AND `widgetEntity: FooChip`
- **WHEN** the widget is rendered with `surface='single-entity'`
- **THEN** `FooChip` MUST be rendered (not `FooCard`)

#### Scenario: A new surface added in the future falls back to the main widget

- **GIVEN** a future surface name `email-digest` is added to the registry's surface enum
- **WHEN** an existing integration that did not declare `widgetEmailDigest` is rendered with `surface='email-digest'`
- **THEN** the main `widget` component MUST be rendered with `surface='email-digest'`
- **AND** no error MUST be thrown

#### Scenario: Widget render failure is isolated

- **GIVEN** an integration's widget component throws an error during render
- **WHEN** it is mounted on a dashboard alongside other widgets
- **THEN** the failing widget MUST render a fallback "Widget unavailable" state with the integration id and error message
- **AND** other widgets on the same dashboard MUST continue to render normally
- **AND** the error MUST be logged for debugging

---

### Requirement: External Integration Routing via OpenConnector

Providers with `getStorageStrategy() === 'external'` SHALL route their CRUD operations through OpenConnector instead of a local storage table. The umbrella SHALL provide an `ExternalIntegrationRouter` service that handles dispatch + auth-status surfacing.

#### Scenario: External provider create call routes through OpenConnector

- **GIVEN** an external provider `openproject` with `storage: external` and an OpenConnector source `openproject-instance-1`
- **WHEN** `POST /api/objects/{register}/{schema}/{id}/openproject` is called
- **THEN** `ExternalIntegrationRouter` MUST resolve the provider's OpenConnector source
- **AND** call OpenConnector's create operation with the request payload + object context (register/schema/id)
- **AND** return the OpenConnector response shape unchanged

#### Scenario: External provider with missing credentials returns auth-status in health

- **GIVEN** an external provider whose OpenConnector source has no configured OAuth tokens
- **WHEN** `GET /api/integrations/openproject` is called
- **THEN** the response MUST include `health.status: 'unavailable'` AND `health.authStatus: 'missing'`
- **AND** the admin UI MUST surface a "Configure" button linking to OpenConnector's credential setup

---

### Requirement: Auth Requirements Declaration

`IntegrationProvider::authRequirements()` SHALL return the auth model and a config schema for credentials. OpenRegister SHALL surface unconfigured/expired auth in the admin UI and via the OCS capabilities response.

#### Scenario: Built-in NC integration declares no auth

- **GIVEN** the built-in `notes` provider
- **WHEN** `authRequirements()` is called
- **THEN** the response MUST be `['type' => 'none']`

#### Scenario: External integration declares OAuth2

- **GIVEN** an `openproject` provider
- **WHEN** `authRequirements()` is called
- **THEN** the response MUST be `['type' => 'oauth2', 'configSchema' => [...]]` describing the required credential fields

---

### Requirement: Per-Integration RBAC

`IntegrationProvider::requiresPermission()` SHALL return either `null` (default — inherit from object RBAC + NC app permissions) or a permission string. Permission strings SHALL be evaluated against `AuthorizationService` for the current user on the object before the integration is included in any list/read response.

The permission-string vocabulary recognised by `AuthorizationService` for integration gating is:

- `'admin'` — the user is a member of the Nextcloud admin group (`IGroupManager::isAdmin($userId)`).
- `'audit.view'` — the user has the OR-internal audit-view role on the object.
- A custom string starting with `<app-id>.` — delegated to that app's permission resolver.

Unknown permission strings SHALL be treated as "deny" and SHALL log a warning identifying the integration and the unrecognised string.

#### Scenario: Provider with no extra permission inherits object access

- **GIVEN** a user with read access to an object
- **AND** a provider returning `null` from `requiresPermission()`
- **WHEN** the provider is listed for the object
- **THEN** the provider MUST appear in `CnObjectSidebar` and `/api/integrations`

#### Scenario: Provider with a required permission is filtered

- **GIVEN** a user with read access to an object but lacking the `audit.view` permission on it
- **AND** an `audit-trail` provider returning `'audit.view'` from `requiresPermission()`
- **WHEN** the provider list is computed for the user/object
- **THEN** the `audit-trail` provider MUST be excluded from the result

---

### Requirement: OCS Capabilities Advertising

The system SHALL include an `integrations` block in the response from `/ocs/v2.php/cloud/capabilities` containing one entry per registered + enabled integration. Each entry SHALL be redacted per caller role:

- **All authenticated users** see only the public block: `{id, label, group, enabled, surfaces}`.
- **Admins** additionally see the sensitive block: `{requiresPermission, authStatus, openConnectorSource}`.

The sensitive fields SHALL be omitted (not set to `null`) for non-admin callers so that introspection cannot distinguish "field hidden" from "field unset". This protects against leaking infrastructure-configuration gaps (`authStatus: 'expired'` on OAuth-backed integrations) and the permission model (`requiresPermission` strings) to regular users.

#### Rationale

OCS capabilities is reachable by every authenticated NC user. Without role-based redaction, a non-admin learning that `authStatus: 'expired'` on `openproject` is told "this org has an OpenProject integration that nobody has reconnected" — disclosure of infrastructure state. Likewise, leaking permission strings (`audit.view`, `admin`, custom roles) reveals the org's RBAC topology. Admins need the full block to operate; everyone else only needs presence + label to render the UI.

#### Scenario: Non-admin caller sees only the public block

- **GIVEN** the registry has an enabled `openproject` integration with `authStatus: 'expired'` and `requiresPermission: null`
- **WHEN** a non-admin user calls `GET /ocs/v2.php/cloud/capabilities`
- **THEN** the `openproject` entry MUST contain exactly the fields `{id, label, group, enabled, surfaces}`
- **AND** the fields `requiresPermission`, `authStatus`, `openConnectorSource` MUST be absent from the entry

#### Scenario: Admin caller sees the full block

- **GIVEN** the same registry state
- **WHEN** an admin user calls `GET /ocs/v2.php/cloud/capabilities`
- **THEN** the `openproject` entry MUST contain `{id, label, group, enabled, surfaces, requiresPermission, authStatus, openConnectorSource}`

#### Scenario: Capabilities response advertises the registry

- **GIVEN** the registry has 8 enabled integrations
- **WHEN** `GET /ocs/v2.php/cloud/capabilities` is called
- **THEN** the response MUST include `data.capabilities.openregister.integrations` as an array of 8 objects
- **AND** each object MUST include at minimum the public-block fields documented above

---

### Requirement: Reference-Property Auto-Rendering

When a JSON schema property declares `referenceType: <integration-id>`, frontend form and detail components (`CnFormDialog`, `CnDetailGrid`) SHALL render the matching integration's `single-entity` widget surface inline next to the property, passing the entity id as `entityId`.

#### Scenario: Schema property with referenceType renders integration widget

- **GIVEN** a schema with property `assignedHandler: { type: 'string', referenceType: 'contacts' }` and an object with `assignedHandler: 'vcard-uuid-123'`
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the `assignedHandler` row MUST contain the `contacts` integration's `single-entity` widget (or fallback `widget`) with `entityId='vcard-uuid-123'`
- **AND** the widget MUST receive `surface='single-entity'`

#### Scenario: Schema property without referenceType renders normally

- **GIVEN** a schema with property `notes: { type: 'string' }` (no `referenceType`)
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the `notes` value MUST be rendered as plain text — no integration widget invoked

#### Scenario: Reference to a missing entity renders a broken-link placeholder

- **GIVEN** a schema property `assignedHandler: { type: 'string', referenceType: 'contacts' }` and an object with `assignedHandler: 'vcard-uuid-deleted'`
- **AND** the referenced vCard has been deleted from the NC address book
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the widget MUST render a "Reference unavailable" placeholder with the id visible to admins (not to end users)
- **AND** the render MUST NOT throw

#### Scenario: Reference to a provider whose required NC app is uninstalled

- **GIVEN** a schema property with `referenceType: 'deck'` and the NC Deck app is not installed
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the widget MUST render a "Deck not installed" placeholder with admin-only install hint
- **AND** the render MUST NOT throw

---

### Requirement: Tags and Audit-Trail as First-Class Integrations

The umbrella SHALL ship `tags` and `audit-trail` as `IntegrationProvider` implementations. Both SHALL declare `getRequiredApp(): null` (always-available) and `getGroup(): 'core'`. Neither SHALL be special-cased in `CnObjectSidebar` rendering — they flow through the same registry + three-stage filter as every other integration.

#### Rationale

Historically these two were hardcoded tabs. Promoting them to first-class integrations makes the registry the single source of truth for what appears in the sidebar, eliminates special cases in rendering, and exposes the parity gap (neither has a card widget today) the umbrella must fill.

#### Scenario: Tags provider appears in the registry

- **GIVEN** the umbrella change is applied
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the result MUST include a provider with `id='tags'`, `group='core'`, `requiredApp=null`

#### Scenario: Audit-trail provider requires admin permission

- **GIVEN** the umbrella change is applied
- **WHEN** `IntegrationRegistry::getEnabled()` is called on behalf of a non-admin user
- **THEN** the `audit-trail` provider MUST be excluded per its `requiresPermission(): 'audit.view'` declaration

---

### Requirement: Registration Collision Handling

Registering an integration id that is already present SHALL be detected. On the PHP side, two DI-tagged providers with the same `getId()` SHALL cause the container build to fail. On the JS side, calling `integrations.register({ id: 'foo', ... })` when `foo` is already registered SHALL throw synchronously in development mode and log a warning (keeping the first registration) in production mode.

#### Rationale

Silent overwrite produces the worst debugging experience. Dev-mode throw catches it during development; production warn-and-keep prevents a single misbehaving app from breaking an entire NC deployment.

#### Scenario: Duplicate JS registration in dev mode throws

- **GIVEN** an integration `forms` is already registered
- **WHEN** another call is made: `integrations.register({ id: 'forms', ... })` in development mode
- **THEN** the call MUST throw a `IntegrationCollisionError` synchronously
- **AND** the error message MUST name the id and point at both registration sites

#### Scenario: Duplicate JS registration in production warns

- **GIVEN** an integration `forms` is already registered and the runtime is in production mode
- **WHEN** another call is made: `integrations.register({ id: 'forms', ... })`
- **THEN** the call MUST log a warning via `console.warn`
- **AND** the first registration MUST remain in effect (second is ignored)

#### Scenario: Duplicate DI tag fails container build

- **GIVEN** two services tagged `IntegrationProvider` both return `'forms'` from `getId()`
- **WHEN** the NC DI container is built
- **THEN** container build MUST fail with a clear error naming the id and both service class names

---

### Requirement: Error-Handling Contract

Provider methods that fail SHALL surface errors through a documented contract, not as generic 500s. `list()`, `get()`, `create()`, `update()`, `delete()` SHALL throw one of: `ProviderUnavailableException` (underlying system down), `ProviderAuthException` (credentials missing/expired), `ProviderNotFoundException` (entity doesn't exist), or `ProviderValidationException` (payload rejected). `ObjectsController` SHALL map these to HTTP statuses (503, 401, 404, 422) with a consistent JSON error body.

#### Scenario: Underlying NC app unreachable returns 503

- **GIVEN** the `deck` integration's backing Deck app crashes mid-request
- **WHEN** `GET /api/objects/{register}/{schema}/{id}/deck` is called
- **THEN** `DeckProvider::list()` MUST throw `ProviderUnavailableException`
- **AND** the response MUST be HTTP 503 with body `{"error": "Integration unavailable", "integration": "deck", "details": "..."}`

#### Scenario: External auth expired returns 401 with reconnect hint

- **GIVEN** an external provider `openproject` whose OpenConnector source has expired OAuth tokens
- **WHEN** `POST /api/objects/.../openproject` is called
- **THEN** `OpenProjectProvider::create()` MUST throw `ProviderAuthException`
- **AND** the response MUST be HTTP 401 with body including a `reconnectUrl` field

#### Scenario: Unknown integration id returns 404

- **GIVEN** a request targets integration id `foobar` that is not registered
- **WHEN** `GET /api/objects/.../foobar` is called
- **THEN** the response MUST be HTTP 404 with body `{"error": "Unknown integration", "integration": "foobar"}`

---

### Requirement: Pagination on List Endpoints

List operations (`IntegrationProvider::list()` and `GET /api/objects/.../{integrationId}`) SHALL support pagination via `limit` and `offset` query parameters. Default limit SHALL be 20; maximum limit SHALL be 100. Responses SHALL include `total` (the unfiltered count) and `hasMore` (boolean convenience flag).

#### Scenario: Default pagination

- **GIVEN** an object with 150 linked emails
- **WHEN** `GET /api/objects/{register}/{schema}/{id}/email` is called without pagination params
- **THEN** the response MUST include the first 20 emails
- **AND** MUST include `{total: 150, limit: 20, offset: 0, hasMore: true}`

#### Scenario: Explicit limit capped at 100

- **GIVEN** a caller requests `?limit=500`
- **WHEN** the list endpoint executes
- **THEN** the response MUST return at most 100 rows
- **AND** `limit` in the response metadata MUST equal `100`

---

### Requirement: Migration of Existing Schemas

A one-time data migration SHALL populate `configuration.linkedTypes` on every schema where the field is currently absent, setting it to `["files", "notes", "tasks", "tags", "audit-trail"]` — the five historically-hardcoded built-ins. This preserves user-visible behavior for existing deployments after the registry-driven filter is activated.

#### Rationale

Before this change, `CnObjectSidebar` showed all 5 hardcoded tabs regardless of `linkedTypes`. After, `linkedTypes` becomes the authoritative per-schema whitelist. Without the migration, every schema whose `linkedTypes` was absent (the common case) would lose all sidebar tabs on upgrade. Auto-populating preserves behavior and lets schema authors narrow the list when they want.

#### Scenario: Existing schema without linkedTypes is migrated

- **GIVEN** a schema saved before this change with no `configuration.linkedTypes`
- **WHEN** the migration runs as part of the release
- **THEN** the schema's `configuration.linkedTypes` MUST be set to `["files", "notes", "tasks", "tags", "audit-trail"]`
- **AND** the schema MUST be saved with a migration-audit entry recording the change

#### Scenario: Existing schema with linkedTypes is not touched

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes"]` already set
- **WHEN** the migration runs
- **THEN** the schema MUST NOT be modified

#### Scenario: Schema with stale integration id logs but does not fail

- **GIVEN** a schema with `linkedTypes: ["calendar"]` on an instance where the `calendar` provider is not yet registered
- **WHEN** the schema is loaded
- **THEN** validation MUST NOT reject on read
- **AND** a warning MUST be logged identifying the schema and the stale id
- **AND** the `calendar` tab MUST simply not render (stage 1 registry filter drops it)

#### Scenario: Schema with mid-rollout linkedTypes ids (umbrella deployed, leaf pending)

- **GIVEN** a schema with `linkedTypes: ["mail", "calendar"]` after the umbrella deploys
- **AND** the `mail` and `calendar` leaf providers have not yet merged (only the 5 built-ins are registered)
- **WHEN** `CnObjectSidebar` renders for an object of that schema
- **THEN** stage 1 registry filter MUST drop `mail` and `calendar` (no tabs rendered for those types)
- **AND** the schema MUST NOT be modified by the umbrella's migration (auto-population only fires when `linkedTypes` is absent — present-but-stale is left intact for the leaves to satisfy on merge)
- **AND** an admin-visible dashboard notice (or warning log per schema) MUST identify the schema and the unregistered ids so admins can audit during rollout

---

### Requirement: Backwards Compatibility

The change SHALL preserve the public API of `CnObjectSidebar` and the existing `LinkedEntityService` shape. Existing consumers (apps, scripts, docs) SHALL continue to function with zero code changes after the schema migration has run.

#### Scenario: Existing CnObjectSidebar consumer works unchanged

- **GIVEN** an app using `<CnObjectSidebar :hidden-tabs="['tasks']" object-type="case" :object-id="id" />`
- **AND** the schema migration has populated `linkedTypes` on the corresponding schema
- **WHEN** the app upgrades `@conduction/nextcloud-vue` to the version including this change
- **THEN** the sidebar MUST render with the same 5 tabs minus `tasks`
- **AND** no console warnings or errors MUST appear

#### Scenario: Existing schema with linkedTypes works unchanged

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes"]` saved before this change
- **WHEN** the schema is loaded after the change
- **THEN** `linkedTypes` validation MUST pass
- **AND** the schema MUST continue to limit integrations to the same two types

#### Scenario: Schema author can narrow the migrated defaults

- **GIVEN** a schema was migrated to `linkedTypes: ["files", "notes", "tasks", "tags", "audit-trail"]`
- **WHEN** the schema author edits the list down to `["files", "notes"]`
- **THEN** the schema MUST save without error
- **AND** subsequent renders MUST honour the narrower whitelist
