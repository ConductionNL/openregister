---
status: proposed
---

# Universal Shared Integration Registry — global bootstrap

## Purpose

Make OpenRegister's integration registry a **window-global, shared,
converge-not-clobber** singleton that is installed and populated on every
full-page render, including pages served by **consuming apps** (OpenCatalogi,
OpenConnector, etc.). Leaf apps that queue integration descriptors via
`window.OCA.OpenRegister.integrations.register(...)` SHALL have those
descriptors drained and rendered no matter which app's bundle hosts the page.

**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Global Bootstrap Entry

OpenRegister SHALL ship a small webpack entry
`openregister-integration-global.js` that imports and calls
`ensureIntegrationRegistry()` exactly once per page load.

#### Scenario: Bootstrap entry is idempotent

- **GIVEN** the global bootstrap script is included twice on the same page
- **WHEN** both copies execute
- **THEN** only one shared registry instance MUST exist on `window`
- **AND** builtin descriptors MUST NOT be registered twice

### Requirement: Shared Registry via nc-vue Primitives

`ensureIntegrationRegistry()` SHALL resolve the shared registry through
`getSharedRegistry(window)` (converge-not-clobber + install-if-needed)
from `@conduction/nextcloud-vue` rather than a per-bundle module singleton.

#### Scenario: Foreign-app useIntegrationRegistry sees populated registry

- **GIVEN** the global bootstrap has run on an OpenCatalogi publication page
- **WHEN** OpenCatalogi's bundle calls `useIntegrationRegistry()`
- **THEN** the returned registry MUST be the same window-global instance
- **AND** the registry MUST contain every builtin + leaf descriptor that was
  registered or queued before the call

### Requirement: BeforeTemplateRenderedEvent Listener

OpenRegister SHALL register an `IntegrationGlobalScriptListener` on `BeforeTemplateRenderedEvent` that unconditionally calls `Util::addInitScript('openregister', 'openregister-integration-global')` on every full-page render, so the bootstrap is present even when OpenRegister's own SPA is not loaded.

#### Scenario: Bootstrap loads on a consuming app's page

- **GIVEN** a user opens an OpenCatalogi publication detail page
- **WHEN** the template renders
- **THEN** the page MUST include `openregister-integration-global.js`
- **AND** the `window.OCA.OpenRegister.integrations` registry MUST be
  installed before any consuming-app bundle runs

### Requirement: Leaf Queue Drain on Foreign Pages

Descriptors queued via `window.OCA.OpenRegister.integrations.register(...)` from a leaf app's bundle SHALL be drained into the shared registry once the bootstrap installs it, regardless of whether OpenRegister's main bundle has loaded.

#### Scenario: OpenConnector sync-contract tab renders on an OpenCatalogi page

- **GIVEN** OpenConnector's Path-2 component bundle has queued a
  `sync-contract` descriptor on a page served by OpenCatalogi
- **WHEN** the global bootstrap installs + populates the shared registry
- **THEN** the queued descriptor MUST be drained into the shared registry
- **AND** the "Synced from" tab/widget MUST render in
  OpenCatalogi's `CnObjectSidebar` instance

### Requirement: Zero Changes Required in Consuming Apps

This change SHALL NOT require any code change in consuming apps
(OpenCatalogi, OpenConnector, etc.) for the shared registry to work.

#### Scenario: OpenCatalogi unchanged still hosts the shared registry

- **GIVEN** OpenCatalogi has zero source changes
- **WHEN** the user opens a publication detail page after this change ships
- **THEN** the shared registry MUST be installed + populated
- **AND** the integration widgets MUST render through OpenCatalogi's existing
  `CnObjectSidebar` / `CnDetailPage` mount points
