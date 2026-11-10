# Tasks: retrofit-2026-05-24-files-sidebar-tabs

## 1. Reverse-spec coverage

- [x] task-1 — REQ "Debounced search input emits update:search after 500ms": annotate `EntitiesSidebar.handleSearchInput` and `WebhooksSidebar.handleSearchInput`.
- [x] task-2 — REQ "Register selection cascade resets dependent schema state": annotate `DashboardSideBar.handleRegisterChange` (and document the DeletedSideBar variant via the same REQ in the canonical spec).
- [x] task-3 — REQ "Deleted sidebar serialises filter state into the route query": annotate `DeletedSideBar.applyFilters`.
