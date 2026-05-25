# Tasks: retrofit-2026-05-25-fe-sidebars

## New REQs (spec delta)

- [x] task-1 — saved-search-views#REQ-001 "Saved view lifecycle": annotate `SearchSideBar` view-management methods (`handleViewChange`, `applyViewConfiguration`, `saveView`, `updateActiveView`, `loadView`, `confirmDeleteView`, `confirmDeleteActiveView`, `openEditDialogForActiveView`, `openEditDialog`, `cancelEditView`, `cancelSaveView`, `handleDeleteClose`, `isActiveView`) + view computeds (`viewOptions`, `selectedViewValue`).
- [x] task-2 — saved-search-views#REQ-002 "View favoriting, default auto-apply, and list filtering": annotate `SearchSideBar.isFavorited`, `SearchSideBar.toggleFavorite`, `SearchSideBar.filteredViews`.
- [x] task-3 — zoeken-filteren#REQ "Search-trail analytics dashboard": annotate `SearchTrailSideBar` analytics loaders/formatters (`loadStatistics`, `loadPopularTerms`, `loadRegisterSchemaStats`, `loadUserAgentStats`, `loadActivityData`, `loadSearchTrailData`, `getComplexityPercentage`, `formatActivityPeriod`, `getRegisterSchemaName`, `getBrowserName`, `updateFilteredCount`, `mounted`).

## Cross-reference annotation (existing capabilities — no new REQs)

- [x] task-4 — chat-ai#REQ-002 (conversation lifecycle): annotate `ChatSideBar` conversation handlers (`handleNewConversation`, `handleSelectConversation`, `handleArchiveConversation`, `handleRestoreConversation`, `handleDeleteConversation`).
- [x] task-5 — files-sidebar-tabs "Register selection cascade resets dependent schema state": annotate the `handleRegisterChange` / `handleSchemaChange` cascade across `DashboardSideBar`, `DeletedSideBar`, `SearchTrailSideBar`, `RegistersSideBar`, `SearchSideBar`.
- [x] task-6 — files-sidebar-tabs "Deleted sidebar serialises filter state into the route query": annotate route-query serialisation + filter-apply plumbing (`applyFilters`, `buildQueryFromState`, `queriesEqual`, `updateRouteQueryFromState`, `applyQueryParamsFromRoute`, `applyFiltersToStore`, `clearAllFilters`, `clearFilters`, `debouncedApplyFilters`, `handleSearchTermFilterChange`, `handleExecutionTimeChange`, `handleResultCountChange`, `handleSearch`) across `DeletedSideBar`, `SearchTrailSideBar`, `SearchSideBar`, `DashboardSideBar`.
- [x] task-7 — faceting-configuration (`_facetable` discovery + `_facets` request config): annotate `SearchSideBar` facet UI methods (`discoverFacets`, `buildFacetConfiguration`, `toggleFacet`, `getFacetOptions`, `getFacetLabel`, `capitalizeFieldName`, `applyFacetFilters`, `applyFiltersToObjectStore`, `updateFacetFilter`, `resetFacets`, `performSearchWithFacets`).
- [x] task-8 — zoeken-filteren (full-text search + filtering): annotate `SearchSideBar` search execution + term plumbing (`performSearch`, `onSearchInput`, `onFilterChange`, `onColumnsChange`, `addSearchTerms`, `removeSearchTerm`, `handleSearchInput`, `syncSearchParamsAndRefetch`, `resolveInheritedProperties`, `searchValueForSidebar`, `searchPlaceholder`, `canSearch`, `selectedSchemasWithProperties`, `toggleSchemaGroup`).
- [x] task-9 — entity-management-modals (create/edit modals submit through the store): annotate `RegisterSideBar` register-edit + schema-edit modal methods (`onSaveRegister`, `editSchema`, `loadSchemaOptions`, `getSchemaSelectValue`, `register`, `registerSchema`, `showEditDialog` watch).
- [x] task-10 — openapi-generation (downloadable OAS per register): annotate `RegisterSideBar.downloadOas`, `RegisterSideBar.viewOasDoc`.
- [x] task-11 — built-in-dashboards (stats overview surface): annotate statistics-display computeds + stat loaders + register/schema dropdown options (`systemTotals`, `orphanedItems`, `filteredRegisters`, `totalSchemas`, `metadataColumns`, `registerOptions`, `schemaOptions`, `selectedRegisterValue`, `selectedSchemaValue`, `userOptions`, `calculateSizes`, `onDateRangeChange`, `loadStatistics`, `loadTopDeleters`, `canSaveView`) across `DashboardSideBar`, `RegistersSideBar`, `RegisterSideBar`, `DeletedSideBar`, `AuditTrailSideBar`.

## Excludes

- [x] task-12 — presentation/format/plumbing helpers carry `@spec exclude <reason>` (date/byte formatters, breakdown formatters, tab-switch state, fire-and-forget `mounted` loaders, scanner-captured getter/setter/watch-handler nodes).
