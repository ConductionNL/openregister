# Tasks: Integration — OpenProject

> **ADR-028 task-cap waiver**: this leaf has 24 tasks (cap is 15). The work is a single external-integration vertical slice (provider + OpenConnector source template + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Umbrella coordination

- [ ] Add `getOpenConnectorSource(): ?string` to umbrella `IntegrationProvider` interface (tiny umbrella PR — tracked separately; this leaf adds the method to the local interface and AbstractIntegrationProvider)
- [x] `AbstractIntegrationProvider` default returns `null`

## Backend

- [x] Create `lib/Service/Integration/Providers/OpenProjectProvider.php` — id='openproject', label='Projects', icon='Briefcase', group='external', requiredApp=null, storage='external', `getOpenConnectorSource()` returns 'openproject'
- [x] Declare auth requirements `{type: 'oauth2', configSchema: {url, client_id, client_secret, scope}}`
- [x] Delegate CRUD to `ExternalIntegrationRouter` (from umbrella)
- [x] Ship OpenConnector source config template `config/openconnector-sources/openproject.yaml`
- [x] DI-tag (registered in Application.php `registerIntegrationServices()`)
- [x] Unit tests (OpenConnector client mocked) — 45 tests in tests/Unit/Service/Integration/

## Frontend — Tab

- [x] `CnOpenProjectTab.vue` — linked WP list with status/assignee/progress badges, link-by-id, link-by-URL, unlink, auth-expired banner
- [x] Barrel (`src/integrations/index.js`)

## Frontend — Widget

- [x] `CnOpenProjectCard.vue`:
  - `user-dashboard`: open WPs assigned to user across linked objects
  - `app-dashboard`: scoped
  - `detail-page`: full WP list with status
  - `single-entity`: WP chip with status badge
- [x] Barrel + surface rendering in CnOpenProjectCard (surface prop)

## Registration

- [x] `src/integrations/builtin/openproject.js` — register with `referenceType: 'openproject'`

## Quality

- [x] Parity gate (tab + widget); phpcs strict; all 14 hydra gates green

## Acceptance verification

- [ ] E2E: configure OpenConnector openproject source, link a WP, verify status in tab
- [ ] Auth test: revoke OAuth, verify tab shows expired state with reconnect link
- [ ] Hide test: delete OpenConnector source, verify integration hidden
- [ ] Reference-property test: schema `{wp: { type: 'string', referenceType: 'openproject' }}` renders chip
- [ ] OCS capabilities includes `openproject` with `authStatus` when source present
