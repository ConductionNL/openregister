<!--
SPDX-FileCopyrightText: 2026 Open Register Contributors
SPDX-License-Identifier: EUPL-1.2
-->

# API-direct specs (excluded from the UI regression project)

These Playwright specs assert OpenRegister **HTTP/JSON API contracts** via the
Playwright `request` fixture. They never drive the UI in a browser, so they are
an anti-pattern for an *end-to-end* (UI) suite per ADR-020 / gate-19
(Playwright = UI; API/contract = Newman).

They are excluded from the `chromium` regression project
(`testIgnore: ['**/api-direct/**']` in `playwright.config.ts`) and kept on disk
only as a reference / recovery point. **The authoritative home for these API
assertions is the Newman collections:**

- `tests/integration/openregister-crud.postman_collection.json` — registers,
  schemas, objects CRUD, OAS generation, import/export, i18n titles.
- `tests/integration/openregister-integrations.postman_collection.json` —
  integration registry / OCS capabilities + sub-resource endpoints.
- `tests/integration/openregister-referential-integrity.postman_collection.json`
  — relations, referential integrity.
- `tests/newman/openregister-auth-matrix.postman_collection.json` — auth-system
  / RBAC posture.
- `tests/newman/openregister-error-matrix.postman_collection.json` — error
  responses.
- `tests/newman/openregister-files-domain.postman_collection.json` — file
  actions / risk classification.
- `tests/newman/openregister-chat-streaming.postman_collection.json` /
  `agent-cms-testing.postman_collection.json` — chat / agent surfaces.

Run the Newman suite via `tests/newman/run-all.sh` and the integration
collections via `tests/integration/` (`npm test` there).

| Relocated spec | API surface | Newman home |
| --- | --- | --- |
| `registers-schemas` | registers/schemas CRUD, OAS, export, i18n | crud |
| `configurations-endpoints` | configurations, endpoints, OAS, workflow | crud |
| `entities-sources` | entities, sources, actions, relations | crud + referential-integrity |
| `advanced-features` | URN addressing, lock/publish, webhooks, retention | crud + referential-integrity |
| `core-crud-lifecycle` | object POST/PUT/GET/DELETE lifecycle (REQ-001..003) | crud |
| `audit-content-versioning` | audit trail, versioning, deletion audit | crud |
| `search-views` | search/filter, faceting, saved views | crud |
| `files-templates` | file actions, risk classification, schema introspection | files-domain |
| `chat-agents` | chat, activity, profile, contacts | chat-streaming + agent-cms |
| `graphql-mcp` | GraphQL, MCP discovery, AI MCP tools | agent-cms |
| `platform-admin` | health/status, settings, NC compat, OTAP | crud + auth-matrix |
| `security-rbac` | auth methods, RBAC scopes, row/field security, tenant isolation | auth-matrix |
| `reporting-avg` | reports, AVG register, SSE, aggregations, vectors | crud |
| `api-smoke` | OAS ETag, notification CRUD, listing envelope | openregister + crud |
| `integration-registry` | integration registry OCS capabilities + sub-resource | integrations |
| `leaf-verification` | per-leaf probe harness → `../leaf-verification.json` | integrations |

The UI partners stay in `tests/e2e/`: `integration-mount.spec.ts` (mounts each
provider tab on the real `ObjectDetails.vue` page) and
`leaf-screenshots.spec.ts` (one PNG per provider via the isolated
`IntegrationsView`).
