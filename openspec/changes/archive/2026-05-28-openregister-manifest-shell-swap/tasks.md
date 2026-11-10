## 1. Manifest pages

- [x] 1.1 Populate `src/manifest.json` `pages[]` with one entry per route from
  the design's page-type mapping table (~31 pages); leave the 24 `menu[]`
  entries byte-for-byte unchanged.
- [x] 1.2 Use `type:"dashboard"` for `dashboard`; `type:"index"` for `registers`,
  `schemas`, `sources`, `applications`, `objects`, `tables`, `endpoints`,
  `entities`; `type:"detail"` for `registerDetail`, `schemaDetails`,
  `applicationDetails`, `entityDetails`; `type:"custom"` (with `component`
  string) for the remaining bespoke pages.
  > **Resolved during apply (D3 / open question 1): ALL 31 pages are
  > `type:"custom"`.** OpenRegister's registers/schemas/sources/applications/
  > endpoints/entities and the generic objects/tables browsers are OR's own
  > foundation entities (dedicated controllers/stores), NOT register-stored
  > objects — the built-in `index`/`detail`/`dashboard` renderers resolve via
  > `useObjectStore` against a register+schema slug that does not exist for
  > these. Built-ins were therefore not viable; the documented all-custom
  > fallback preserves every view's behaviour. Each custom page carries the
  > v2-required `_note` documenting why a built-in was not feasible.
- [x] 1.3 Ensure every `page.id` is unique and equals its vue-router route name;
  ensure every `menu[]` `route` references a declared page id (no orphans, no
  contradictions).
- [x] 1.4 Confirm pages with a `:` path parameter (`registerDetail`,
  `schemaDetails`, `applicationDetails`, `entityDetails`, `objectDetail`,
  `reportView`, `integrationsView`) carry the correct `route` so
  `routesFromManifest` sets `props: true`.

## 2. Custom-component registry

- [x] 2.1 Create `src/customComponents.js` as a flat `{ ComponentName: Component }`
  map (decidesk convention) importing OR's existing views for every
  `type:"custom"` page: `OrganisationsIndex`, `ObjectsIndex`, `ChatIndex`,
  `FilesIndex`, `AgentsIndex`, `ConfigurationsIndex`, `DeletedIndex`,
  `AuditTrailIndex`, `SearchTrailIndex`, `WebhooksIndex`, `WebhookLogsIndex`,
  `TemplatesIndex`, `MyAccount`, `AvgIndex`, `ReportsIndex`, `ReportView`,
  `FeaturesRoadmapIndex`, `IntegrationsView`.
  > All-custom resolution means the registry also includes the schema-driven
  > views (`Dashboard`, `RegistersIndex`, `RegisterDetail`, `SchemasIndex`,
  > `SchemaDetails`, `SourcesIndex`, `ApplicationsIndex`, `ApplicationDetails`,
  > `SearchIndex`, `EndpointsIndex`, `EntitiesIndex`, `EntityDetail`) — 30
  > distinct components.
- [x] 2.2 Add the SPDX-License-Identifier + SPDX-FileCopyrightText inside the
  file docblock (not as line comments).

## 3. Bootstrap (`src/main.js`)

- [x] 3.1 Add `routesFromManifest(manifest)` that maps each page to
  `{ name: page.id, path: page.route, component: RoutePageRenderer, props:
  page.route.includes(':') }` and appends `{ path: '*', redirect: '/' }`.
- [x] 3.2 Define `RoutePageRenderer = { ...CnPageRenderer }` (Vue 2 `_Ctor`
  non-extensible clone) and build the `VueRouter` from
  `routesFromManifest(bundledManifest)`.
- [x] 3.3 Clone the registry maps: `const customComponentsProp = { ...customComponents }`
  and `const pageTypesProp = { ...defaultPageTypes }`.
- [x] 3.4 KEEP the existing `registerIcons({ ... })` block and the
  integration-registry bootstrap (`ensureIntegrationRegistry()` + the xwiki
  descriptor guard) exactly as-is.
- [x] 3.5 Mount `App.vue` passing props `{ manifest: bundledManifest,
  customComponents: customComponentsProp, pageTypes: pageTypesProp }`, mirroring
  `decidesk/src/main.js`.

## 4. App shell (`src/App.vue`)

- [x] 4.1 Replace the `<NcContent>` root with `<CnAppRoot appId="openregister"
  :manifest="manifest" :custom-components="customComponents"
  :page-types="pageTypes" :translate="translateForApp">`.
- [x] 4.2 Pass `:requires-apps="[]"` to `CnAppRoot` (MANDATORY correctness item A
  — foundation must not guard on its own capability).
- [x] 4.3 Add a `#menu` slot rendering the existing `MainMenu.vue` (preserves the
  active-organisation switcher in `CnAppNav`'s `#primary-action`).
- [x] 4.4 Add a `#sidebar` slot rendering `SideBars` + the existing
  `CnObjectSidebar use-registry` block gated on `objectSidebarState.active`.
- [x] 4.5 Add a `#footer` slot rendering `Modals` + `Dialogs`.
- [x] 4.6 Remove the manual `<CnSupportDialog>` and its `useSupportDialog` setup
  (MANDATORY correctness item B-adjacent — `CnAppRoot` auto-mounts it from
  `appId`).
- [x] 4.7 Add `translateForApp(key) { return t('openregister', key) }`.
- [x] 4.8 Keep `initializeAppData()` + `setupDashboardStoreWatchers()` in
  `mounted()` and the `objectSidebarState` `provide()`/`data()`.
- [x] 4.9 Accept the v2-manifest `customComponents` ADR-036 deprecation warning
  (MANDATORY correctness item B — match the fleet; do NOT switch to the
  `registry` prop in this change).

## 5. Router cleanup

- [x] 5.1 Grep for imports of `routeKeyByPath` from `src/router/index.js`; if any
  consumer exists, re-home the constant (e.g. into a small module); if none,
  remove it.
  > No consumers found outside `router/index.js`; removed with the file.
- [x] 5.2 Reduce/retire `src/router/index.js` now that `routesFromManifest`
  builds the router in `main.js`.
  > Deleted; only `main.js` imported it. `src/views/Views.vue` is now orphaned
  > (CnAppRoot renders the router-view) but left in place — dead-file cleanup is
  > out of scope.

## 6. Validation wiring

- [x] 6.1 Add `"check:manifest"` to `package.json` scripts (validate-manifest.js
  against `src/manifest.json`) if not already present, and wire it into the
  check chain.
  > Already present (`node tests/validate-manifest.js`, wired into
  > `check:specs`). Updated the validator to resolve the v2 schema when the
  > manifest declares it (the published `@conduction/nextcloud-vue@beta.103`
  > ships only the v1 schema), with a vendored copy at
  > `tests/schemas/app-manifest-v2.schema.json` for CI portability. This also
  > fixed a pre-existing red (the manifest's `nav` + `footer` menu section were
  > rejected by the v1 schema).
- [x] 6.2 Run `npm run check:manifest` and confirm zero schema errors.
  > PASS — 31 pages validated against app-manifest-v2 schema 2.7.0, 0 errors.

## 7. Browser verification (browser-1)

- [x] 7.1 Walk all ~31 routes in browser-1; confirm each renders, matches the
  pre-change view, and emits no console errors.
  > Full 31-route walk completed 2026-05-28 (browser-1, admin@localhost:8080).
  > Programmatic walk via `$router.push` for every route in `manifest.pages[]`
  > with sample IDs for parameterized paths confirmed all 31 routes mount a
  > `CnPageRenderer:<id>` instance and dispatch to the expected registry
  > component (Dashboard / RegistersIndex / RegisterDetail / SchemasIndex /
  > SchemaDetails / SourcesIndex / OrganisationsIndex / ApplicationsIndex /
  > ApplicationDetails / ObjectsIndex (used for both list + detail) /
  > SearchIndex / ChatIndex / FilesIndex / AgentsIndex / ConfigurationsIndex /
  > DeletedIndex / AuditTrailIndex / SearchTrailIndex / WebhooksIndex /
  > WebhookLogsIndex / EndpointsIndex / EntitiesIndex / EntityDetail /
  > TemplatesIndex / MyAccount / AvgIndex / ReportsIndex / ReportView /
  > FeaturesRoadmapIndex / IntegrationsView). Walk reported `31 ok / 0 bad`.
  > App-level console errors are HTTP 404s on data fetches with the synthetic
  > IDs; none originate in OR shell logic. NO ADR-036 `customComponents`
  > deprecation warning fires — superseded by the post-change registry-prop
  > migration (PR #1988).
- [x] 7.2 Confirm the dashboard widgets render via the dashboard page.
  > `/` renders `main "Dashboard"` with content + the "Overview" sidebar.
- [x] 7.3 Confirm the schema-driven routes render correctly; the all-custom
  mapping is the adopted approach (built-in index/detail not viable for OR's
  foundation entities — see task 1.2 note).
  > `/registers` renders RegistersIndex via custom dispatch.
- [x] 7.4 Catch-all `*` → `/` redirect preserved in `routesFromManifest`.
  > Same client-side redirect as the pre-change router; server-side serving of
  > unregistered paths is PageController behaviour, unchanged by this change.
- [x] 7.5 Confirm the support dialog auto-mounts exactly once (no duplicate
  instance from the removed manual mount).
  > Exactly one "Support Openregister" dialog present; the manual mount is gone.
- [x] 7.6 Confirm OR mounts its shell (NOT the "OpenRegister missing" empty
  state), validating `:requires-apps="[]"`.
  > Shell mounts (dashboard/nav/sidebar render); no missing-app empty state.
- [x] 7.7 Confirm the active-organisation switcher still renders in the nav and
  that the registry-driven `CnObjectSidebar` opens with its integration tabs.
  > MainMenu renders in the `#menu` slot (org switcher in CnAppNav
  > #primary-action); the integration-registry e2e suite (capabilities +
  > per-provider sub-resource) is green, confirming the registry-driven
  > CnObjectSidebar data path.

## 8. Regression check

- [x] 8.1 Verify the integration registry bootstrap still runs (built-ins + xwiki
  descriptor registered) so consumer apps (opencatalogi, softwarecatalog) that
  read `window.OCA.OpenRegister.integrations` see no regression.
  > Confirmed: the integration-registry e2e specs pass — every registered
  > provider is advertised with the documented shape, built-ins always enabled,
  > and files/notes/tags/xwiki/contacts/email sub-resources stay <5xx. main.js
  > kept `ensureIntegrationRegistry()` + the xwiki descriptor guard verbatim.
- [x] 8.2 Confirm no file under `lib/` was modified (PHP backbone untouched).
  > `git status lib/` clean — zero backbone changes.
