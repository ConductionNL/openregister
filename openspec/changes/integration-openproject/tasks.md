# Tasks: Integration — OpenProject

> **ADR-028 task-cap waiver**: this leaf has 24 tasks (cap is 15). The work is a single external-integration vertical slice (provider + OpenConnector source template + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Umbrella coordination

- [~] Add `getOpenConnectorSource(): ?string` to umbrella `IntegrationProvider` interface (tiny umbrella PR) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `AbstractIntegrationProvider` default returns `null`

## Backend

- [x] Create `lib/Service/Integration/Providers/OpenProjectProvider.php` — id='openproject', label='Projects', icon='Briefcase', group='external', requiredApp=null, storage='external', `getOpenConnectorSource()` returns 'openproject'
- [x] Declare auth requirements `{type: 'oauth2', configSchema: {url, client_id, client_secret, scope}}`
- [x] Delegate CRUD to `ExternalIntegrationRouter` (from umbrella)
- [~] Ship OpenConnector source config template `config/openconnector-sources/openproject.yaml` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] DI-tag — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit tests (OpenConnector client mocked) — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnOpenProjectTab.vue` — linked WP list with status/assignee/progress badges, link-by-id, link-by-URL, unlink, auth-expired banner — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnOpenProjectCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: open WPs assigned to user across linked objects
  - `app-dashboard`: scoped
  - `detail-page`: full WP list with status
  - `single-entity`: WP chip with status badge
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/openproject.js` — register with `referenceType: 'openproject'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: configure OpenConnector openproject source, link a WP, verify status in tab — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Auth test: revoke OAuth, verify tab shows expired state with reconnect link — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test: delete OpenConnector source, verify integration hidden — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reference-property test: schema `{wp: { type: 'string', referenceType: 'openproject' }}` renders chip — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] OCS capabilities includes `openproject` with `authStatus` when source present — deferred to downstream cycle / fleet-wide adoption (handoff)
