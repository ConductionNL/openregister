# Tasks — Retrofit shared-ui-components

All tasks are retroactive annotations of behaviour that already exists in `src/components/`. The code already implements the REQ; the only work captured here is the `@spec` pointer.

- [x] task-1: shared-ui-components#REQ-001 — Pagination component clamps page-change requests to the valid range (retroactive annotation of `src/components/PaginationComponent.vue::changePage`)
- [x] task-2: shared-ui-components#REQ-002 — ConfigurationCard detects already-imported discovered configurations via backend lookup (retroactive annotation of `src/components/cards/ConfigurationCard.vue::checkIfImported`)
- [x] task-3: shared-ui-components#REQ-003 — Collapsible settings card toggles on header click and emits a toggle event (retroactive annotation of `src/components/shared/SettingsCard.vue::toggleCollapsed`)
- [x] task-4: shared-ui-components#REQ-004 — SettingsSection escapes HTML in detailed descriptions before rendering (retroactive annotation of `src/components/shared/SettingsSection.vue::sanitizeHtml`)
