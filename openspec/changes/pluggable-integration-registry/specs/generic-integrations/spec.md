---
status: proposed
---

# Generic Integrations

## Purpose

OpenRegister exposes a uniform contract for "things linked to an object" — Nextcloud-native entities (files, notes, tasks, calendar events, mail, contacts, deck cards, talk conversations) and external services (OpenProject, XWiki, etc.) — so consuming apps and third-party integrators can extend OR's reach without modifying OR's core or `@conduction/nextcloud-vue`.

This spec defines the **integration registry**: a backend service + frontend registry + provider contract + three-stage filter (registry existence → schema relevance → component context) that governs which integrations are visible where.

**Standards**: Nextcloud OCS Capabilities, JSON Schema, RFC 5545 (iCalendar), RFC 6350 (vCard), Nextcloud DI tag system
**Cross-references**: [nextcloud-entity-relations](../../../specs/nextcloud-entity-relations/spec.md), [object-interactions](../../../specs/object-interactions/spec.md), [authorization-rbac](../../../specs/authorization-rbac/spec.md)

---

## Requirements

### Requirement: Integration Provider Contract

The system SHALL define a PHP interface `OCA\OpenRegister\Service\Integration\IntegrationProvider` that every backend integration implements. The interface SHALL be relations-shaped (generic "linked thing" terminology) so that future unification with `RelationsService` (object↔object) is possible without breaking the contract.

#### Rationale

A uniform contract is the entire point of a registry. Each integration ships a vertical slice (provider + tab + widget); the contract guarantees they can be composed without core knowing about them individually.

#### Scenario: A new integration implements the contract

- **GIVEN** a developer wants to add a new integration named `forms`
- **WHEN** they create a class `FormsProvider implements IntegrationProvider`
- **THEN** their class MUST implement: `getId()`, `getLabel()`, `getIcon()`, `getGroup()`, `getRequiredApp()`, `getStorageStrategy()`, `isEnabled()`, `requiresPermission()`, `authRequirements()`, `list()`, `get()`, `create()`, `update()`, `delete()`, `health()`
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

#### Rationale

User explicit preference — every integration gets both surfaces. Enforcing it at registration time prevents drift; making it a CI gate prevents merges from sneaking past local hooks.

#### Scenario: A registration without a widget fails the parity check

- **GIVEN** a JS file calls `OCA.OpenRegister.integrations.register({ id: 'foo', tab: FooTab })` (missing `widget`)
- **WHEN** `scripts/check-integration-parity.sh` runs
- **THEN** the script MUST exit non-zero with an error naming the integration id and the missing component
- **AND** the CI workflow `integration-parity` MUST fail
- **AND** the hydra quality gate MUST report the failure under gate `integration-parity`

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

The system SHALL include an `integrations` block in the response from `/ocs/v2.php/cloud/capabilities` containing one entry per registered + enabled integration. Each entry SHALL include: `id`, `label`, `group`, `enabled`, `requiresPermission`, `authStatus`, `surfaces` (the list of surfaces the integration supports).

#### Scenario: Capabilities response advertises the registry

- **GIVEN** the registry has 8 enabled integrations
- **WHEN** `GET /ocs/v2.php/cloud/capabilities` is called
- **THEN** the response MUST include `data.capabilities.openregister.integrations` as an array of 8 objects
- **AND** each object MUST include the documented fields

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

---

### Requirement: Backwards Compatibility

The change SHALL preserve the public API of `CnObjectSidebar` and the existing `LinkedEntityService` shape. Existing consumers (apps, scripts, docs) SHALL continue to function with zero code changes.

#### Scenario: Existing CnObjectSidebar consumer works unchanged

- **GIVEN** an app using `<CnObjectSidebar :hidden-tabs="['tasks']" object-type="case" :object-id="id" />`
- **WHEN** the app upgrades `@conduction/nextcloud-vue` to the version including this change
- **THEN** the sidebar MUST render with the same 5 tabs minus `tasks`
- **AND** no console warnings or errors MUST appear

#### Scenario: Existing schema with linkedTypes works unchanged

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes"]` saved before this change
- **WHEN** the schema is loaded after the change
- **THEN** `linkedTypes` validation MUST pass
- **AND** the schema MUST continue to limit integrations to the same two types
