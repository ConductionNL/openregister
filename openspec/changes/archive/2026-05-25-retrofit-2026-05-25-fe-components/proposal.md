# Retrofit — Frontend coverage: components (all `@spec exclude`)

The coverage scanner flagged 207 uncovered methods across `src/components/` Vue files (batch `fw-fe-components`). This change annotates every one of them with a JSDoc `@spec` tag per ADR-003.

## Why

Bucket 2b ("code exists, no spec to point at") for reusable `src/components/` widgets. After reading the code, every flagged method is reusable-UI plumbing — none defines a new spec-worthy domain contract that is not already captured elsewhere:

- **Computed display / formatter helpers** (`getToolName`, `formatDate`, `riskLabel`, `statusLabel`, `buttonLabel`, `ratioPercent`, `tooltipText`, `repositoryFullName`, `visiblePages`, `getSourceTypeLabel`, …) are pure presentation/derivation of props or store state.
- **Filter/search state writers** that emit or proxy sidebar filter changes (`updateCategory`, `updateType`, `updateStatus`, `updateRiskLevel`, `updateEnabled`, `clearFilters`, `handleSearchInput`, the whole `FacetComponent` `update*`/`get*`/`is*`/`toggle*` surface) orchestrate the search UI; the underlying facet/search contract lives in the search capability, not in the widget.
- **Store / API passthrough loaders** (`fetchObjects`, `fetchEmails`, `fetchSchedules`, `fetchExtractionStatus`, `loadSchemaOptions`, `refresh`, `createChain`, `createSchedule`, `runTest`, `save`) delegate to entity stores or backend endpoints; the data contract is owned by the relevant backend capability.
- **Emit / open-dialog / unlink UI handlers** (`handleEdit`, `handleDelete`, `handleExport`, `openCreateDialog`, `openLinkDialog`, `unlinkContact`, `unlinkCard`, `unlinkEmail`, `unlinkEvent`, `removeSchemaFromRegister`, `toggleExpand`, `toggleFacet`, `toggleType`, …) are pure UI plumbing.
- **i18n widget helpers** (`TranslationStatusChip`, `TranslationCompletenessBadge`, `TranslationFieldEditor`, `RegisterLanguagesEditor`, `BulkTranslateDialog`) surface behaviour already specified by the `register-i18n` capability; per the 2b-components triage these are dropped from widget-level annotation and excluded here.
- **Object-relations tab plumbing** (`ContactsTab`, `DeckTab`, `EmailsTab`, `EventsTab`, `RelationsTab`) renders the integration data owned by the per-integration capabilities; the tabs themselves are list/fetch/unlink plumbing.
- **RBAC table render helpers** (`hasPermission`, `hasAnyPermissions`, `sortedGroups`, `updatePermission`) are computed/emit plumbing over the RBAC capability's data.

The genuinely spec-worthy shared-widget contracts in `src/components/` (pagination bounds-check, ConfigurationCard import detection, collapsible settings card, settings-section HTML escape) were already minted as `shared-ui-components` REQ-001..REQ-004 by `retrofit-2026-05-24-2b-components`; the residual `PaginationComponent` and `ConfigurationCard` methods in this batch are the surrounding display/emit plumbing around those contracts.

Every method therefore received `@spec exclude <reason>` with a specific reason. **Zero new REQs are minted.**

Per the retrofit playbook, an all-exclude change carries no spec delta. `--strict` requires a delta, so this change is intentionally delta-less and should not be validated with `--strict` on a (nonexistent) spec delta.

## What changes

- 207 methods across `src/components/` annotated with JSDoc `@spec exclude <reason>` (ADR-003 two-tool approach: annotation only, comment-only change).
- No code behaviour changes. No REQs minted, so there is no spec delta.

## Outcome

**0 reverse-spec'd / 207 excluded / 0 new REQs.** Comment-only change.

## Scope of this batch

**Batch source**: `/tmp/or-scan/fw-fe-components.json` — 207 methods across `src/components/` files.
