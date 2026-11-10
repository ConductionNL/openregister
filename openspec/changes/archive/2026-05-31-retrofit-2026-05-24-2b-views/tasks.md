# Tasks

All tasks are marked `[x]` because the code already exists. This is a retrofit — tasks describe retroactive annotation, not new implementation work.

## admin-list-views

- [x] task-1: admin-list-views#REQ-001 — Admin index views expose a `toggleSelectAll(checked)` bulk-selection action (retroactive annotation: `AgentsIndex.toggleSelectAll`, `ApplicationsIndex.toggleSelectAll`, `ConfigurationsIndex.toggleSelectAll`, `SourcesIndex.toggleSelectAll`)
- [x] task-2: admin-list-views#REQ-002 — Admin index views with a detail sidebar expose a `toggleSidebar()` method bound to `NcAppContent.show-details` (retroactive annotation: `EntitiesIndex.toggleSidebar`, `TemplatesIndex.toggleSidebar`, `WebhooksIndex.toggleSidebar`)
- [x] task-3: admin-list-views#REQ-003 — Admin index views soft-refresh their list on mount via the owning store (retroactive annotation — covered by the same set of `*Index.vue` files; no new method annotations required because the `mounted()` lifecycle hook is not a named-method scanner target. Captured in spec only.)

## account-self-service

- [x] task-4: account-self-service#REQ-001 — The account page provides self-service password and deactivation flows (retroactive annotation: `PasswordSection.changePassword`, `AccountSection.requestDeactivation`)
- [x] task-5: account-self-service#REQ-002 — The account page lists and manages the signed-in user's personal API tokens and avatar (retroactive annotation: `TokensSection.loadTokens`, `AvatarSection.triggerUpload`)
