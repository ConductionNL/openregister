# Retrofit — 2b-store (Pinia stores)

Describes observed behavior of 27 public Pinia store methods across 16 files classified by the scanner under Bucket 2b (`store` cluster). All methods are frontend state-management modules in `src/store/`. Code already exists — this change retroactively specifies it.

## Approach

The scanner clustered everything under a generic `store` slug because the methods all live in `src/store/`. In practice each store mirrors a backend domain that is already covered by an existing capability spec — these stores are the frontend reactive state for those backends and SHOULD be annotated as cross-capability code, not minted as a new spec each.

Only the **UI shell** state (navigation/dialog/sidebar coordination + the cross-section admin settings panel) is genuinely not covered by any backend-domain spec: this is the frontend's "where am I, what dialog is open, which sidebar section is collapsed" state machine. That small surface gets a new `frontend-ui-shell` capability with 2 REQs.

## Affected code units (frontend stores)

### Cross-capability annotations (existing specs)
- `src/store/modules/agent.js` → `chat-ai#REQ-004` (agent management)
- `src/store/modules/conversation.ts` → `chat-ai#REQ-002` (conversation lifecycle)
- `src/store/modules/avg.js` → `avg-verwerkingsregister` (Art 30 register + data-subject rights)
  - `useAvgStore`, `RECHTSGROND_VOCABULARY`, `STATUS_VOCABULARY`
- `src/store/modules/reports.js` → `rapportage-bi-export` (dashboards, chart data, widget cache)
  - `useReportsStore`, `widgetCacheKey`, `DEFAULT_REPORTS_REGISTER`, `DEFAULT_DASHBOARD_SCHEMA`
- `src/store/modules/translations.js` → `register-i18n` (translation lifecycle, RTL, bulk translate)
  - `useTranslationsStore`, `RTL_LANGUAGES`, `TRANSLATION_STATUSES`, `isRtlLanguage`
- `src/store/modules/object.js` → `object-lifecycle#REQ-001` (object CRUD pipeline mirrored on frontend via `createObjectStore` adapter)
  - `useObjectStore`, `getCurrentType`, `openregisterObjectPlugin`
- `src/store/modules/deleted.js` → `object-lifecycle` (soft-delete / restore UI)
- `src/store/modules/source.js` → `data-sync-harvesting#REQ-001` (sync source definitions)
- `src/store/modules/application.js` → `auth-system` (Application entity + Nextcloud group access control)
- `src/store/modules/configuration.js` → `data-import-export` (Configuration bundle import/export)
- `src/store/modules/organisation.js` → `tenant-lifecycle` (organisation entity + multi-tenancy state)
- `src/store/modules/views.js` → `zoeken-filteren` (saved search views composition)
- `src/store/modules/dashboard.js` → `rapportage-bi-export` (chart data API for built-in dashboards)
  - `useDashboardStore`, `setupDashboardStoreWatchers`

### Net-new capability (frontend-ui-shell, 2 REQs)
- `src/store/modules/navigation.js` → `frontend-ui-shell#REQ-001`
- `src/store/settings.js` → `frontend-ui-shell#REQ-002`

### Dropped (no clean spec home — known coverage gap)
- `src/store/modules/endpoints.ts` (`useEndpointStore`) — custom REST endpoint registry has no backend-domain spec; flagged for follow-up

## Total REQ count

2 new REQs in 1 new capability (`frontend-ui-shell`). All other store methods annotated against existing specs.

Source: `/tmp/or-scan/rspec-2b-store.json` generated 2026-05-24. See retrofit playbook.
