# Design — Retrofit frontend coverage, views (chunk 2)

**Documentation-only retrofit change. Tasks describe retroactive `@spec exclude` annotation, not new implementation work. No spec delta.**

## Context

The coverage scan placed 223 methods across 18 `src/views/**/*.vue` files into the "missing @spec" bucket. ADR-003 requires every code unit either to point at a spec task (`@spec openspec/changes/{change}/tasks.md#task-N`) or to carry an explicit `@spec exclude <reason>`. This change closes that gap for the chunk.

## Why exclude rather than reverse-spec

Frontend views in openregister are thin rendering + wiring over store/API behavior that is already specified at the capability layer. Concretely, the chunk's methods fall into recurring plumbing shapes:

- **Computed list/selection state** — `paginatedX`, `allSelected`, `someSelected`, `totalPages`, `currentPage`, `filteredX`, `emptyContentName/Description`.
- **Pagination/selection handlers** — `onPageChanged`, `onPageSizeChanged`, `toggleSelectAll`, `toggleXSelection`, `toggleSidebar`, `toggleSort`.
- **Lifecycle fetch** — `mounted`, `beforeDestroy`, store-refresh watchers.
- **Formatting helpers** — `formatDate`, `formatTime`, `formatBytes`, `formatFileSize`, `formatMimeType`, `formatStatus`, `formatRiskLevel`, `formatExecutionTime`, `formatGroups`, `formatPurgeDate`.
- **Navigation / store wiring / dialog glue** — `viewX`, `editX`, `openXModal`, `selectX`, `saveX`, `loadX`, `refreshX`, clipboard/download/redoc-link openers.

Each renders behavior owned by an existing capability (`object-lifecycle`, `linked-entity-types`, `archivering-vernietiging`, `zoeken-filteren`, `ai-chat-companion`, `auth-system`, `tenant-lifecycle`, `registers-management`, `oas-validation`, schema workflow). Minting a new REQ for a `formatDate` helper or a `toggleSelectAll` checkbox would dilute the spec without describing a contract a consumer depends on. Per the playbook's "bias toward exclude for UI plumbing," every method is excluded with a specific reason.

## Why no new REQs

No method in the chunk introduces a user-facing contract that is both novel and unowned. The closest candidates (chat feedback, permission-matrix bulk role apply, OAS download) are frontend surfaces over already-specified backend capabilities; their contracts live with those capabilities, not in the view. Reverse-speccing them here would create cross-capability REQ drift. They are therefore excluded, not spec'd.

## Tag format

Each method's JSDoc block carries `@spec exclude <reason>` where `<reason>` is method-specific (e.g. `list-view pagination plumbing`, `detail-view date formatting helper`, `settings-section store fetch`). Existing JSDoc blocks gain the tag line; methods without a block get a minimal block. No behavior is changed.
