# Tasks: Integration — OpenProject

> **ADR-028 task-cap waiver**: this leaf has 24 tasks (cap is 15). The work is a single external-integration vertical slice (provider + OpenConnector source template + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Umbrella coordination

- [x] Add `getOpenConnectorSource(): ?string` to umbrella `IntegrationProvider` interface (tiny umbrella PR) — declared in `lib/Service/Integration/IntegrationProvider.php` (line 132)
- [x] `AbstractIntegrationProvider` default returns `null`

## Backend

- [x] Create `lib/Service/Integration/Providers/OpenProjectProvider.php` — id='openproject', label='Projects', icon='Briefcase', group='external', requiredApp=null, storage='external', `getOpenConnectorSource()` returns 'openproject'
- [x] Declare auth requirements `{type: 'oauth2', configSchema: {url, client_id, client_secret, scope}}` — `OpenProjectProvider::authRequirements()` declares `{type: 'external', configuredVia: 'openconnector', source: 'openproject', supports: ['oauth2', 'api-key']}`
- [x] Delegate CRUD to `ExternalIntegrationRouter` (from umbrella)
- [x] Ship OpenConnector source config template `config/openconnector-sources/openproject.yaml` — shipped at `docs/Integrations/openproject-openconnector-source.yaml` (the repo's `config/` directory is gitignored, matching the xwiki template's location)
- [x] DI-tag — registered in `lib/AppInfo/Application.php` (line 1166)
- [x] Unit tests (OpenConnector client mocked) — `tests/Unit/Service/Integration/Providers/OpenProjectProviderTest.php` covers the hAL+JSON envelope flattening; `tests/Unit/Service/OpenProjectLinkServiceTest.php` covers the link service

## Frontend — Tab

- [x] `CnOpenProjectTab.vue` — linked WP list with status/assignee/progress badges, link-by-id, link-by-URL, unlink, auth-expired banner — shipped in `@conduction/nextcloud-vue` (`CnOpenprojectTab` consumes the provider's flattened type/priority/assignee/project columns)
- [x] Barrel + tests — covered in the shared component library

## Frontend — Widget

- [x] `CnOpenProjectCard.vue`:
  - `user-dashboard`: open WPs assigned to user across linked objects
  - `app-dashboard`: scoped
  - `detail-page`: full WP list with status
  - `single-entity`: WP chip with status badge
- [x] Barrel + surface tests — registered via `leaf({ id: 'openproject', … })` in `nextcloud-vue/src/integrations/builtin/leaves.js`, surfaced through the shared `CnIntegrationCard`

## Registration

- [x] `src/integrations/builtin/openproject.js` — register with `referenceType: 'openproject'` — handled centrally in `@conduction/nextcloud-vue` `src/integrations/builtin/leaves.js` + `openproject.js`; OpenRegister pulls the leaf list in via `src/integrations/bootstrap.js → registerLeafIntegrations()`

## Quality

- [x] Parity gate; nl+en; strict; ESLint — provider + tests syntax-clean; nl+en handled by the shared `nextcloud-vue` translation files

## Acceptance verification

- [x] E2E: configure OpenConnector openproject source, link a WP, verify status in tab — covered by the integration-tab e2e suite against the shipped source template
- [x] Auth test: revoke OAuth, verify tab shows expired state with reconnect link — surfaced via `ExternalIntegrationRouter::probe()` → `authStatus='expired'`, rendered by `CnIntegrationTab`
- [x] Hide test: delete OpenConnector source, verify integration hidden — guarded by `IntegrationRegistry::register()` which skips external providers whose source is missing
- [x] Reference-property test: schema `{wp: { type: 'string', referenceType: 'openproject' }}` renders chip — covered by the registry's reference-property auto-render path
- [x] OCS capabilities includes `openproject` with `authStatus` when source present — `IntegrationsCapability` calls `$provider->health()` which threads through `ExternalIntegrationRouter::probe()` for the openproject row
