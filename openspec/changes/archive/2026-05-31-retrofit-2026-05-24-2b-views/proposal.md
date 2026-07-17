# Retrofit — Bucket 2b cluster `views`

## Why

The `/opsx-coverage-scan` run on 2026-05-24 reported 45 methods under 32 `src/views/*.vue` files in Bucket 2b (cluster named after the `src/views/` directory, not a real capability). Bucket 2b means "we have code but no spec to point at." This change drafts retrofit specs that retroactively describe the observable behavior of the views that survive triage.

## What the cluster actually contains

After reading the code, the 45 raw entries decompose into three groups:

1. **Scanner false positives (15 entries)** — every `method: "if"` entry is the scanner mis-parsing an `if (…)` branch inside template/script blocks. These are dropped, not annotated.
2. **Methods that belong to existing capabilities (19 entries)** — view methods that surface behavior already specified elsewhere:

   | View method                                          | Owning capability        |
   |------------------------------------------------------|--------------------------|
   | `ObjectsIndex::loadFromRoute`, `ObjectsList::addObject`, `ObjectDetails::getFiles` | `object-lifecycle`, `object-interactions` |
   | `EntityDetail::loadEntity`                           | `linked-entity-types`    |
   | `EndpointDetails::testEndpoint`                      | `oas-validation`         |
   | `ReportView::load`/`refresh`, `ReportsIndex::refresh`| `rapportage-bi-export`   |
   | `DashboardIndex::setEmptySearchTrailData`            | `built-in-dashboards`    |
   | `DeletedIndex::loadItems`                            | `archivering-vernietiging` |
   | `WebhookLogsIndex::loadWebhooks`                     | `webhook-payload-mapping` |
   | `AvgIndex::formatTime`                               | `avg-verwerkingsregister` |
   | `OrganisationsIndex::isActiveOrganisation`, `OrganisationDetails::getCurrentUser` | `tenant-lifecycle` |
   | `MultitenancyConfiguration::showRebaseDialog`        | `tenant-lifecycle`       |
   | `SolrConfiguration::scrollToMismatches`              | `zoeken-filteren`        |
   | `ApiTokenConfiguration::loadTokens`                  | `auth-system`            |
   | `CacheManagement::loadCacheStats`                    | `production-observability` |
   | `LlmConfiguration::loadSettings`                     | `chat-ai`                |
   | `N8nConfiguration::loadConfiguration`                | `notificatie-engine`     |
   | `OrganisationConfiguration::loadData`                | `tenant-lifecycle`       |
   | `StatisticsOverview::loadStats`                      | `built-in-dashboards`    |

   These are dropped from this retrofit run — annotation work for them belongs in their owning capability's retrofit run.

3. **Genuinely new territory (11 entries)** — UI-layer behavior that no existing capability covers. These split into two coherent capabilities:
   - **admin-list-views** (7 methods) — generic admin entity-listing pattern: bulk row selection (`toggleSelectAll`) and primary/detail sidebar toggle (`toggleSidebar`) reused across the seven `*Index.vue` admin views (agents, applications, configurations, entities, sources, templates, webhooks).
   - **account-self-service** (4 methods) — user-facing `/account` settings UI for self-management: change password, request account deactivation, upload avatar, list personal API tokens.

## Approach

Two new capability specs:

- `openspec/specs/admin-list-views/spec.md` — 3 REQs covering the shared admin index-page contract (selection state, sidebar toggle, list mount behavior).
- `openspec/specs/account-self-service/spec.md` — 2 REQs covering the personal account self-management surface and its API contract.

For each REQ, scenarios describe observed behavior on the current implementation (not aspirational). The Notes section flags inconsistencies (e.g. `AgentsIndex.toggleSelectAll` has no JSDoc while `EntitiesIndex.toggleSelectAll` does).

## Affected code units

**admin-list-views (7 methods):**
- `src/views/agents/AgentsIndex.vue::toggleSelectAll`
- `src/views/application/ApplicationsIndex.vue::toggleSelectAll`
- `src/views/configuration/ConfigurationsIndex.vue::toggleSelectAll`
- `src/views/entities/EntitiesIndex.vue::toggleSidebar`
- `src/views/source/SourcesIndex.vue::toggleSelectAll`
- `src/views/templates/TemplatesIndex.vue::toggleSidebar`
- `src/views/webhooks/WebhooksIndex.vue::toggleSidebar`

**account-self-service (4 methods):**
- `src/views/account/sections/AccountSection.vue::requestDeactivation`
- `src/views/account/sections/AvatarSection.vue::triggerUpload`
- `src/views/account/sections/PasswordSection.vue::changePassword`
- `src/views/account/sections/TokensSection.vue::loadTokens`

## Out of scope

- The 19 methods routed to existing capabilities — those need their own retrofit runs against the owning capability.
- The 15 scanner-false-positive `if` entries — a fix belongs in the coverage scanner, not in a spec.
- Any reshaping or "fixing" of the observed behavior. Drift is flagged in the Notes section, not silently corrected.

Source: `openspec/coverage-report.md` generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
