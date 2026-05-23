- **Vue 2 + Pinia + @nextcloud/vue + @conduction/nextcloud-vue**. NO Vuex. Options API only.
- State: Pinia stores in `src/store/modules/`. Use `createObjectStore` for OpenRegister CRUD.
- API calls: `axios` from `@nextcloud/axios` — auto-attaches CSRF token. NEVER raw `fetch()` for mutations.
  Loading state with `try/finally`.
- Translations: ALL user-visible strings via `t(appName, 'text')`. NO hardcoded strings.
  Translation keys MUST be English — Dutch translations go in `l10n/nl.json`.
- CSS: ONLY Nextcloud CSS variables (`var(--color-primary-element)`, etc.). NO hardcoded colors.
  NEVER reference `--nldesign-*` directly — nldesign app handles theming.
- Router: history mode, base `generateUrl('/apps/{app}/')`. Requires matching PHP routes in `routes.php`.
  Deep link URL templates MUST match the router mode — use path format (`/apps/{app}/entities/{uuid}`),
  NOT hash format (`/apps/{app}/#/entities/{uuid}`).
- OpenRegister dependency: settings returns `openRegisters` (bool) + `isAdmin`.
  Show empty state if OR missing. NEVER use `OC.isAdmin` — get from backend.
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog` (WCAG, theming).
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API or store.
- NEVER pass server-side data (e.g. app version) via DOM attributes. Use `IInitialState::provideInitialState('key', $value)` in PHP and `loadState('appid', 'key', default)` from `@nextcloud/initial-state` in Vue. DOM data-attributes are not the Nextcloud-idiomatic pattern and break on CSP-hardened instances.
- NEVER add admin settings Vue components (e.g. `AdminRoot.vue`) to the vue-router. Admin settings are registered via `AdminSettings.php` and rendered by Nextcloud's settings framework — adding them to the router makes them publicly accessible as frontend routes, bypassing all server-side access checks.
- NEVER create manual `<label>` elements for `NcSelect` — always use the built-in `inputLabel` prop (or `ariaLabelCombobox` for combobox mode). Manual labels break the component's internal accessibility wiring.
- NEVER write modal or dialog markup inline inside a parent component. Every modal/dialog MUST live in its own `.vue` file: `src/modals/` for `NcModal`-based components, `src/dialogs/` for `NcDialog`-based ones. Import and register it in the parent.
- EVERY `await store.action()` call MUST be wrapped in `try/catch` with user-facing error feedback.
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports all
  NC components plus Conduction components. This ensures consistent theming and component versions.
- EVERY component used in `<template>` MUST be imported AND registered in `components: {}`.
  Vue 2 silently renders unknown elements — missing imports cause invisible runtime failures.

### NL Design System

- ALL UI components MUST use CSS custom properties from NL Design System tokens.
- MUST support theme switching via nldesign app's token sets.
- MUST meet WCAG AA compliance: keyboard-navigable, associated labels, color is not the sole
  method of conveying information.
- SHOULD work on 320px–1920px viewports; critical functionality MUST work at 768px (tablet).
- Exceptions: PDF generation (docudesk), admin-only screens (simpler styling allowed).

### @conduction/nextcloud-vue — ALWAYS check before building custom

**Pages & Layout:**
  `CnIndexPage` (schema-driven list+CRUD) | `CnDetailPage` (detail+sidebar) |
  `CnPageHeader` (title+icon) | `CnActionsBar` (add+search+toggle)

**Data Display:**
  `CnDataTable` (sortable+paginated) | `CnCardGrid` + `CnObjectCard` (card views) |
  `CnDetailGrid` (label-value pairs) | `CnFilterBar` (search+filters) |
  `CnFacetSidebar` (faceted filters) | `CnPagination` | `CnCellRenderer` (type-aware)

**Forms & Dialogs:**
  `CnFormDialog` (schema-driven create/edit) | `CnAdvancedFormDialog` (properties+JSON+metadata) |
  `CnSchemaFormDialog` (JSON Schema editor) | `CnTabbedFormDialog` (tabbed form framework) |
  `CnDeleteDialog` | `CnCopyDialog`

**Mass Actions:**
  `CnMassDeleteDialog` | `CnMassCopyDialog` | `CnMassExportDialog` (CSV/JSON/XML) |
  `CnMassImportDialog` (upload+summary) | `CnMassActionBar` (floating selection bar)

**Dashboard & Widgets:**
  `CnDashboardPage` (GridStack drag-drop layout) | `CnDashboardGrid` (layout engine) |
  `CnWidgetWrapper` (widget shell) | `CnWidgetRenderer` (NC Dashboard API v1/v2) |
  `CnChartWidget` (ApexCharts: area/line/bar/pie/donut/radial) |
  `CnTableWidget` (data table widget) | `CnTileWidget` (quick-access tile) |
  `CnInfoWidget` (label-value grid) | `CnKpiGrid` (responsive KPI layout) |
  `CnStatsBlock` (metric card) | `CnStatsPanel` (stats sections) | `CnProgressBar` |
  `CnObjectDataWidget` (schema-driven editable data grid, inline edit + save via objectStore) |
  `CnObjectMetadataWidget` (read-only object metadata display)

**UI Elements:**
  `CnStatusBadge` | `CnEmptyState` | `CnIcon` (MDI) | `CnCard` | `CnDetailCard` |
  `CnRowActions` | `CnTimelineStages` (workflow progression) |
  `CnUserActionMenu` (user context menu) | `CnJsonViewer` (CodeMirror)

**Detail Sidebar:**
  `CnObjectSidebar` (Files/Notes/Tags/Tasks/Audit tabs) | `CnIndexSidebar` |
  `CnNotesCard` (inline notes) | `CnTasksCard` (inline tasks)

**Settings:**
  `CnSettingsSection` + `CnVersionInfoCard` (MUST be first on admin pages) |
  `CnSettingsCard` | `CnConfigurationCard` | `CnRegisterMapping`
  User settings: `NcAppSettingsDialog` (NOT `NcDialog`)

**Composables:**
  `useListView` (search/filter/sort/pagination) | `useDetailView` (load/edit/delete) |
  `useSubResource` (related items) | `useDashboardView` (widgets/layout/edit)

**Store Plugins:**
  `auditTrailsPlugin` | `relationsPlugin` | `filesPlugin` | `lifecyclePlugin` |
  `selectionPlugin` | `searchPlugin` | `registerMappingPlugin`

**Utilities:**
  `columnsFromSchema()` | `filtersFromSchema()` | `fieldsFromSchema()` |
  `formatValue()` | `buildHeaders()` | `buildQueryString()`

### Page Construction Patterns (follow these recipes)

**App.vue:** `NcContent` → 3 states: loading (`NcLoadingIcon`), no-OpenRegister (`NcEmptyContent`),
  ready (`MainMenu` + `NcAppContent` + `router-view` + optional `CnIndexSidebar`).
  Inject `sidebarState` for child components. `created()` calls `initializeStores()`.

**MainMenu:** `NcAppNavigation` with `NcAppNavigationItem` per route (icon + name + `:to`).
  Footer: `NcAppNavigationSettings` (gear foldout) with admin/config nav items.
  Settings item emits `@click="$emit('open-settings')"` — opens `NcAppSettingsDialog` modal.
  Do NOT route to `/settings` — in-app settings is a modal overlay, not a page.

**Dashboard:** `CnDashboardPage` with `CnStatsBlock` KPIs (4 cards: open/overdue/value/completed),
  status distribution chart, "My Work" list (grouped: overdue → due this week → rest).
  Fetch all collections in parallel via `Promise.all`. Widget templates via `#widget-{id}` slots.

**Index page:** `CnIndexPage` with `useListView(entityType, { sidebarState, objectStore })`.
  Inject sidebarState. Row click → `$router.push({ name: 'EntityDetail', params: { id } })`.
  Add button → new entity detail with id='new'.

**Detail page:** Two modes — edit (form component) / view (`CnDetailPage` + `CnDetailCard` sections).
  Header actions: Edit + Delete buttons. Related entities in table inside `CnDetailCard`.
  Props: `entityId` from route. `isNew = entityId === 'new'`. Sidebar via `CnObjectSidebar`.
  **Relations:** Every entity referenced in the spec MUST have a `CnDetailCard` section.
  Use `fetchUsed` for reverse lookups (find objects that reference THIS entity) and
  `fetchUses` for forward lookups (find objects THIS entity references).
  If the spec lists a "linked X section", it MUST be implemented — not deferred or stubbed.

**Settings — two surfaces, never a route:**
  *Admin settings* (`/settings/admin/{appid}`): `AdminRoot.vue` rendered by `settings.js` entry point,
  registered via `AdminSettings.php`. Layout: `CnVersionInfoCard` (FIRST) → `CnRegisterMapping` →
  `CnSettingsSection` per feature. Load via `GET /api/settings`, save via `POST /api/settings`.
  *In-app settings*: `UserSettings.vue` wrapping `NcAppSettingsDialog` — opened as a modal from the
  gear menu (`@open-settings` event on MainMenu), handled in `App.vue` with `:open` / `@update:open`.
  Do NOT create a `/settings` route. Do NOT create a standalone `SettingsView.vue` page component.

**Router:** Flat routes (no nesting), all named, props via arrow function for params.
  Routes: `/` (Dashboard), `/{entities}` (list), `/{entities}/:id` (detail).
  No `/settings` route — settings is a modal (see Settings section above).

**Store init:** `initializeStores()` in `store/store.js` — fetches settings, then calls
  `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each entity.
  Object store uses `createObjectStore` with plugins (files, auditTrails, relations).
  Settings store: Pinia `defineStore` with `fetchSettings()` and `saveSettings()`.

### Build / bundling — webpack.config.js

The base `@nextcloud/webpack-vue-config` ships sensible defaults, but most app
configs replace `webpackConfig.plugins` wholesale to add VueLoaderPlugin /
NodePolyfillPlugin without duplicates. That replacement strips the base's
`DefinePlugin` for `appName` / `appVersion` along with everything else. Every
config that touches `webpackConfig.plugins` MUST add them back explicitly.

- **MUST set `appName` and `appVersion` defines** when `webpackConfig.plugins` is replaced.
  `@nextcloud/vue` reads them at module-eval time as bare globals:

  ```js
  let realAppName = 'missing-app-name'
  try { realAppName = appName }     catch { logger.error('appName was not set...') }
  let realAppVersion = ''
  try { realAppVersion = appVersion } catch { logger.error('appVersion was not set...') }
  ```

  Without `DefinePlugin` replacing those bare identifiers at build time the try
  blocks throw and every widget mount logs `[ERROR] @nextcloud/vue: The
  '@nextcloud/vue' library was used without setting / replacing the 'appName'`.
  The required block, after `new VueLoaderPlugin()` and `new NodePolyfillPlugin(...)`:

  ```js
  new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
  new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
  ```

- **NEVER unconditionally override `devtool` to `inline-source-map`.** The earlier
  `webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'` line picks
  the right setting for both modes — both write the map to a separate `.js.map`
  file. An `inline-source-map` override base64-encodes the entire map *into*
  every emitted JS file, ~doubling each bundle. Source-map debugging works the
  same way either way; browser dev-tools pick up `.js.map` automatically.

- **SHOULD apply `optimization.splitChunks` when an app has 2+ entry-points** —
  each dashboard widget, settings page, and main bundle is one entry-point, and
  every entry independently inlines Vue + `@nextcloud/vue` + `pinia` +
  `vue-material-design-icons` + `@conduction/nextcloud-vue` (~3 MB minified)
  unless told otherwise. The base config sets `splitChunks` but only with the
  default `chunks: 'async'`, which never splits sync imports. Override to
  `chunks: 'all'` with explicit `cacheGroups` and **stable filenames** so each
  entry's PHP `Util::addScript` call can reference the chunk directly without a
  manifest:

  ```js
  webpackConfig.optimization = {
      ...(webpackConfig.optimization || {}),
      splitChunks: {
          ...(webpackConfig.optimization?.splitChunks || {}),
          chunks: 'all',
          cacheGroups: {
              default: false,
              defaultVendors: false,
              ncVue: {
                  name: appId + '-shared-nc-vue',
                  // Matches both node_modules entries AND the monorepo-dev alias
                  // `../nextcloud-vue/src/...` which webpack resolves outside
                  // node_modules when @conduction/nextcloud-vue is aliased to it.
                  test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
                  priority: 30, reuseExistingChunk: true, enforce: true,
                  filename: appId + '-shared-nc-vue.js',
              },
              vendor: {
                  name: appId + '-shared-vendor',
                  test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
                  priority: 20, reuseExistingChunk: true, enforce: true,
                  filename: appId + '-shared-vendor.js',
              },
          },
      },
  }
  ```

  Each entry's PHP `load()` then attaches the shared chunks **before** the
  per-entry bundle. `Util::addScript` dedupes by `(app, file)` so the shared
  chunks emit once even when every dashboard widget calls it:

  ```php
  Util::addScript(Application::APP_ID, Application::APP_ID.'-shared-vendor');
  Util::addScript(Application::APP_ID, Application::APP_ID.'-shared-nc-vue');
  Util::addScript(Application::APP_ID, Application::APP_ID.'-myWidget');
  ```

  Working examples: `pipelinq/webpack.config.js`, `procest/webpack.config.js`,
  `docudesk/webpack.config.js`. Order matters in PHP: vendor → nc-vue → entry.

- **TypeScript apps**: handle `.ts` via `babel-loader` + `@babel/preset-typescript`,
  NOT `ts-loader`. Mixing `ts-loader` with the base config's `babel-loader` for `.js`
  produces two different module-ID schemes; `splitChunks: { chunks: 'all' }` then
  fails at first widget mount with `TypeError: Cannot read properties of undefined
  (reading 'call')` because the per-widget runtime can't resolve modules emitted
  into shared chunks under the foreign ID space (even with `runtimeChunk: 'single'`).
  Babel handling both `.js` and `.ts` produces one consistent module graph that
  survives the split. Type-checking moves to `npx tsc --noEmit` (run separately in
  CI / IDE) — the build only strips types. Required pieces:

  ```js
  // .babelrc — add the preset alongside @babel/preset-env
  { "presets": ["@babel/preset-env", "@babel/preset-typescript"] }
  ```

  ```js
  // webpack.config.js — filter out the base's ts-loader rule, then add babel
  webpackConfig.module.rules = webpackConfig.module.rules.filter(rule =>
      !(rule && rule.use && (
          (typeof rule.use === 'string' && rule.use === 'ts-loader')
          || (Array.isArray(rule.use) && rule.use.some(u => (u?.loader || u) === 'ts-loader'))
          || (typeof rule.use === 'object' && rule.use.loader === 'ts-loader')
      ))
      && !(rule && rule.loader === 'ts-loader')
  )
  webpackConfig.module.rules.push({
      test: /\.ts$/,
      exclude: /node_modules/,
      use: { loader: 'babel-loader' },
  })
  ```

  Working example: `opencatalogi/webpack.config.js` (also includes the standard
  `appName`/`appVersion` `DefinePlugin` and the splitChunks block).
