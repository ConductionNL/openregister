# Retrofit — frontend coverage cluster `fe-sidebars`

## Why

The `/opsx-coverage-scan` run on 2026-05-25 reported **149 uncovered methods** across the eight `src/sidebars/**/*.vue` files (bundle `fe-sidebars` — `fw-fe-sidebars.json`). The cluster is named after the `src/sidebars/` directory, not a real capability: it bundles tab-switching, register/schema filter pickers, route-query serialisation, statistics display, and a handful of genuinely novel domain surfaces (saved views, search analytics) that drive backend behaviour.

This change brings every one of the 149 under ADR-003's `@spec` convention via the two-tool approach: **annotate against an existing capability** where the sidebar drives a real domain interaction, **mint a new REQ** only for genuinely novel UI behaviour, and **`@spec exclude <reason>`** for presentation/format/plumbing helpers that should never carry a capability reference.

## What Changes

After reading all eight files, the 149 methods decompose into the buckets below. Each
method ends tagged — annotated to a REQ (new or existing) or carried as a reasoned
`@spec exclude`. This is a retroactive specification; no runtime behaviour changes.

### 1. Genuinely novel behaviour — NEW REQs (3 minted, ≤3 cap)

- **saved-search-views** (NEW capability spec, 2 REQs) — `SearchSideBar.vue` exposes a full saved-view surface backed by `/api/views`: list/activate/load/create/update/delete named views, plus public/default/favorite flags. A "view" persists a query configuration (registers, schemas, search terms, facet filters, enabled facets) and re-applies it to the live search. No existing capability describes this UI contract.
  - REQ-001 — Saved view lifecycle (create / update / activate / load / delete) through `viewsStore` + `/api/views`.
  - REQ-002 — View favoriting, default-view auto-apply, and search-scoped filtering of the view list.
- **zoeken-filteren** extension (1 REQ, `retrofit_extensions`) — `SearchTrailSideBar.vue` renders a search-analytics dashboard (popular terms, register/schema breakdown, user-agent stats, activity-period buckets, query-complexity distribution) sourced from the search-trail store. The canonical `zoeken-filteren` spec records search-trail *persistence* but explicitly notes analytics reporting is unspecified. This REQ closes that gap for the read/display surface.
  - REQ — Search-trail analytics dashboard (period-bucketed activity, popular terms, user-agent and register/schema aggregation).

### 2. Methods that drive EXISTING capabilities — cross-reference annotation (no new REQs)

These sidebar methods surface behaviour already specified elsewhere; they are annotated against this ghost change's cross-reference tasks, which point at the owning capability:

| Sidebar behaviour | Owning capability |
|---|---|
| Conversation create / select / archive / restore / permanent-delete (`ChatSideBar` handlers) | `chat-ai` (REQ-002 conversation lifecycle) |
| Register-selection cascade + dependent schema reset (`handleRegisterChange`/`handleSchemaChange` across Dashboard / Deleted / SearchTrail / Registers / Search) | `files-sidebar-tabs` (Register selection cascade) |
| Route-query serialisation of filter state (`applyFilters`, `buildQueryFromState`, `queriesEqual`, `updateRouteQueryFromState`, `applyQueryParamsFromRoute`, `applyFiltersToStore`, `clearFilters`/`clearAllFilters`, debounced filter input) | `files-sidebar-tabs` (Deleted sidebar serialises filter state into the route query) |
| Facet discovery / configuration / filtering UI (`discoverFacets`, `buildFacetConfiguration`, `toggleFacet`, `getFacetOptions`, `getFacetLabel`, `capitalizeFieldName`, `applyFacetFilters`, `applyFiltersToObjectStore`, `updateFacetFilter`, `resetFacets`, `performSearchWithFacets`) | `faceting-configuration` (`_facetable` discovery + `_facets` request config) |
| Object search execution + search-term plumbing (`performSearch`, `onSearchInput`, `onFilterChange`, `onColumnsChange`, `addSearchTerms`, `removeSearchTerm`, `handleSearchInput`, `syncSearchParamsAndRefetch`, `resolveInheritedProperties`) | `zoeken-filteren` (full-text search + filtering) |
| Register edit / schema-edit modal launch + save (`onSaveRegister`, `editSchema`, `loadSchemaOptions`, `getSchemaSelectValue`, `register`, `registerSchema`, `showEditDialog`) | `entity-management-modals` (create/edit modals submit through the store) |
| OAS download / view (`downloadOas`, `viewOasDoc`) | `openapi-generation` (downloadable OAS per register) |
| Statistics display + register/schema dropdown options (`systemTotals`, `orphanedItems`, `filteredRegisters`, `totalSchemas`, `metadataColumns`, `registerOptions`, `schemaOptions`, `selectedRegisterValue`, `selectedSchemaValue`, `userOptions`, `calculateSizes`, `onDateRangeChange`, deletion + audit + search-trail stat loaders) | `built-in-dashboards` (stats overview surface) |

### 3. Presentation / format / plumbing — `@spec exclude`

Pure display helpers, date/byte formatters, tab-switch state, lifecycle `mounted` hooks that only fire-and-forget already-annotated loaders, and scanner-captured computed getter/setter/watch-handler nodes (`get`, `set`, `handler`). Each carries `@spec exclude <reason>` — never a bare exclude.

## Counts

- **Total methods**: 149
- **Spec'd** (new REQ or cross-reference to existing capability): 133
- **Excluded** (`@spec exclude <reason>`): 16
- **New REQs minted**: 3 (saved-search-views ×2, zoeken-filteren extension ×1)

## Affected files

- `src/sidebars/chat/ChatSideBar.vue`
- `src/sidebars/dashboard/DashboardSideBar.vue`
- `src/sidebars/deleted/DeletedSideBar.vue`
- `src/sidebars/logs/AuditTrailSideBar.vue`
- `src/sidebars/logs/SearchTrailSideBar.vue`
- `src/sidebars/register/RegisterSideBar.vue`
- `src/sidebars/register/RegistersSideBar.vue`
- `src/sidebars/search/SearchSideBar.vue`

## Impact

- **New capability**: `saved-search-views` (2 REQs) — canonical home for the `/api/views` saved-view UI contract.
- **Extended capability**: `zoeken-filteren` (+1 REQ, `retrofit_extensions`) — the search-trail analytics-reporting surface previously flagged unspecified.
- **Specs touched**: `specs/saved-search-views/spec.md` and `specs/zoeken-filteren/spec.md` (ADDED only).
- **Code**: none — annotation-only retrofit across the eight `src/sidebars/**/*.vue` files.
- **Cross-references**: sidebar methods mapped to `chat-ai`, `files-sidebar-tabs`, `faceting-configuration`, `entity-management-modals`, `openapi-generation`, and `built-in-dashboards` gain pointers to those owners; no requirement text in those capabilities changes.

## Out of scope

- Any reshaping of observed behaviour. Drift (e.g. `console.info` debug noise in `SearchSideBar.handleRegisterChange`, the `OC.getCurrentUser` favoriting TODO) is captured as observed, not corrected.
- The canonical `built-in-dashboards` spec (root openspec) — the local slug is a redirect stub; stats-display methods cross-reference it as their owner.
- Methods outside this batch's eight files.

Source: `/tmp/or-scan/fw-fe-sidebars.json`. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
