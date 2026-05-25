# Retrofit — Frontend coverage, views (chunk 3)

## Why

The `/opsx-coverage-scan` coverage audit reported 223 uncovered methods across 17
`src/views/**/*.vue` files (batch `fe-views-3`). These are frontend view components:
list/index pages, detail pages, settings sections, and account self-service sections.
Bucket 2b means "we have code but no spec REQ to point at."

Per ADR-003 and the retrofit playbook, frontend view code is largely UI plumbing —
list/detail rendering, data fetching for display, formatting helpers, dialog
open/close toggles, pagination handlers, and lifecycle hooks that wire stores to the
view. None of these establish a novel user-facing contract beyond what the owning
backend capabilities already specify. They are therefore annotated with
`@spec exclude <reason>` (ADR-003 documentation-only exclusion) rather than minting
new REQs.

## What the cluster contains

All 223 methods fall into UI-plumbing categories:

- **Lifecycle hooks** (`mounted`, `created`, `updated`, `beforeDestroy`, `setup`) —
  framework glue that loads view data on entry and tears down timers on exit.
- **Data-load methods** (`loadStats`, `loadLogs`, `loadCacheStats`, `getFiles`,
  `getRelations`, …) — fetch backend data for display; the contract lives in the
  backend service/controller specs.
- **Formatting / display helpers** (`formatBytes`, `formatDate`, `formatTime`,
  `truncateText`, `getHitRateClass`, `widgetIcon`, …) — pure presentation.
- **Computed properties** (`loading`, `tabs`, `stats`, `paginationData`,
  `tableColumns`, `normalizedObjects`, …) — derived view state.
- **Dialog / sidebar toggles** (`openCreateDialog`, `closeDialog`,
  `showClearCacheDialog`, `hideClearAuditTrailsDialog`, …) — UI visibility state.
- **Pagination / sort / selection handlers** (`onPageChanged`, `handleSort`,
  `handleSelect`, `nextPage`, …) — table interaction plumbing.
- **Action triggers** that delegate to a store/API already specified elsewhere
  (`publishSchema`, `clearAllAuditTrails`, `triggerWarmup`, `exportData`, …).

## Approach

Every one of the 223 methods is tagged with JSDoc `@spec exclude <reason>` where the
reason names the UI-plumbing role. No new REQs are minted; this is a
documentation-only ghost change with no `specs/` delta.

## No new REQs

This change drafts **no new REQs**. All 223 methods are UI plumbing fully covered (at
the contract level) by their owning backend capabilities, or are pure presentation
with no specifiable contract. The behaviors are excluded per ADR-003, not specified.

## Affected files

- `src/views/account/sections/AvatarSection.vue`
- `src/views/account/sections/ExportSection.vue`
- `src/views/avg/AvgIndex.vue`
- `src/views/dashboard/DashboardIndex.vue`
- `src/views/logs/AuditTrailIndex.vue`
- `src/views/object/ObjectDetails.vue`
- `src/views/reports/ReportView.vue`
- `src/views/reports/ReportsIndex.vue`
- `src/views/schema/CalendarProviderTab.vue`
- `src/views/schema/SchemaDetails.vue`
- `src/views/schema/SchemasIndex.vue`
- `src/views/search/SearchIndex.vue`
- `src/views/settings/Settings.vue`
- `src/views/settings/sections/CacheManagement.vue`
- `src/views/settings/sections/LlmConfiguration.vue`
- `src/views/settings/sections/RetentionConfiguration.vue`
- `src/views/settings/sections/StatisticsOverview.vue`
- `src/views/webhooks/WebhookLogsIndex.vue`

## Out of scope

- Any reshaping of observed behavior — exclusions document, they do not change code.
- The backend capabilities these views render — covered by their own specs.

Source: `/opsx-coverage-scan` batch `/tmp/or-scan/fw-fe-views-3.json`.
See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
