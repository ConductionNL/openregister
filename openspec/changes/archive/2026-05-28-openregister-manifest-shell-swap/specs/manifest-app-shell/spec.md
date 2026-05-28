## ADDED Requirements

### Requirement: Foundation guard opt-out

OpenRegister's `CnAppRoot` mount SHALL pass `:requires-apps="[]"` so that
`CnAppRoot` never runs the OpenRegister-availability capabilities guard against
OpenRegister itself. OpenRegister is the foundation app and MUST NOT render the
"OpenRegister missing" empty state inside its own frontend.

#### Scenario: OpenRegister mounts its own shell without self-guarding

- **WHEN** the OpenRegister frontend loads and `CnAppRoot` mounts
- **THEN** `requiresApps` is the empty array, no capabilities check runs, no
  capabilities-loading spinner flashes, and the shell renders on first paint
- **AND** the "OpenRegister missing" / `#or-missing` empty state is NEVER shown

#### Scenario: Default guard would brick the app

- **WHEN** `requiresApps` were left at its `['openregister']` default and the
  Nextcloud capabilities payload did not advertise the `openregister` key
- **THEN** `CnAppRoot` would render the missing-app empty state instead of the
  app — which is the failure mode `:requires-apps="[]"` prevents

### Requirement: Manifest-driven app shell

The OpenRegister frontend SHALL mount the `@conduction/nextcloud-vue`
`CnAppRoot` component as its root, driven by the bundled `src/manifest.json`.
`CnAppRoot` SHALL receive `appId="openregister"`, the bundled `manifest`, a
cloned `customComponents` registry, a cloned `pageTypes` map, and a `translate`
closure of the form `(key) => t('openregister', key)`.

#### Scenario: Shell renders with the manifest

- **WHEN** the app boots
- **THEN** `CnAppRoot` mounts with the bundled manifest, the menu renders from
  `manifest.menu[]`, and the active route's manifest page renders inside the
  shell

#### Scenario: Translate closure resolves menu and page labels

- **WHEN** `CnAppRoot` / `CnAppNav` / `CnPageRenderer` resolve a `label` or
  `title` i18n key
- **THEN** the key resolves through the `(key) => t('openregister', key)` closure
  against OpenRegister's nl and en catalogues

### Requirement: Every route declared as a manifest page

`src/manifest.json` `pages[]` SHALL declare one entry per existing route, with a
unique `id` matching the vue-router route name. Schema-driven pages MAY use
built-in `type:"index"` / `type:"detail"`; the dashboard SHALL use
`type:"dashboard"`; bespoke admin / log / settings / workspace pages SHALL use
`type:"custom"` referencing their component by string name. No page SHALL
duplicate or contradict an existing `menu[]` entry's route.

#### Scenario: Router built from the manifest

- **WHEN** `main.js` builds the vue-router via `routesFromManifest(manifest)`
- **THEN** each `manifest.pages[]` entry yields a route `{ name: page.id, path:
  page.route, component: clonedCnPageRenderer }`, and routes whose path contains
  a `:` parameter receive `props: true`

#### Scenario: Menu entries point at declared pages

- **WHEN** the manifest is validated
- **THEN** every `menu[]` entry with a `route` references a `pages[]` `id`/route
  that exists, and no `menu[]` entry is orphaned

#### Scenario: Manifest passes validation

- **WHEN** `npm run check:manifest` runs against `src/manifest.json`
- **THEN** the manifest validates against the canonical schema with zero errors

### Requirement: Custom-page component registry

OpenRegister SHALL provide `src/customComponents.js` as a flat map of
component-name → Vue component, passed to `CnAppRoot` via the `customComponents`
prop. Every `type:"custom"` page's `component` string SHALL resolve to an entry
in this map. The map SHALL be shallow-cloned before being passed as a prop to
avoid the Vue 2 `_Ctor` non-extensible error.

#### Scenario: Custom page resolves its component

- **WHEN** a `type:"custom"` manifest page is routed to
- **THEN** `CnPageRenderer` resolves `page.component` against the
  `customComponents` map and mounts the corresponding OpenRegister view

#### Scenario: Registry maps are cloned

- **WHEN** `main.js` passes `customComponents` and `pageTypes` to `CnAppRoot`
- **THEN** each is a shallow clone (`{ ...customComponents }`,
  `{ ...defaultPageTypes }`) and `CnPageRenderer` is cloned
  (`{ ...CnPageRenderer }`) so Vue 2's `Vue.extend()` does not throw "Cannot add
  property _Ctor"

### Requirement: Slot composition preserves existing surfaces

The `CnAppRoot` mount SHALL compose three slots: `#menu` renders the existing
`MainMenu.vue` (preserving the active-organisation switcher in `CnAppNav`'s
`#primary-action`); `#sidebar` renders `SideBars` plus the existing
registry-driven `CnObjectSidebar` (gated on `objectSidebarState.active`);
`#footer` renders `Modals` plus `Dialogs`. The `objectSidebarState` provide and
the `mounted()` hooks `initializeAppData()` + `setupDashboardStoreWatchers()`
SHALL be preserved.

#### Scenario: Active-organisation switcher survives

- **WHEN** the shell renders the `#menu` slot
- **THEN** `MainMenu.vue` renders inside `CnAppNav` and the active-organisation
  switcher button appears in `CnAppNav`'s `#primary-action` slot

#### Scenario: Object sidebar renders via the registry

- **WHEN** `objectSidebarState.active` is true
- **THEN** the `#sidebar` slot renders `CnObjectSidebar` with `use-registry`,
  showing one tab per registered integration provider

#### Scenario: Modals and dialogs render in the footer

- **WHEN** a modal or dialog is opened
- **THEN** it renders via the `Modals` / `Dialogs` components mounted in the
  `#footer` slot

#### Scenario: Boot hooks run on mount

- **WHEN** `App.vue` mounts
- **THEN** `initializeAppData()` and `setupDashboardStoreWatchers()` run, and the
  `objectSidebarState` is provided to descendants

### Requirement: Support dialog auto-mounts once

The manual `<CnSupportDialog>` SHALL be removed from `App.vue`; `CnAppRoot` SHALL
auto-mount the support dialog from `appId="openregister"`. The derived App-Store
URL SHALL be `https://apps.nextcloud.com/apps/openregister` and the derived
feature-request URL SHALL be
`https://github.com/ConductionNL/openregister/issues/new`.

#### Scenario: Support dialog mounts exactly once

- **WHEN** the user first opens the app
- **THEN** the support dialog auto-mounts once via `CnAppRoot` (no second manual
  instance), with the OpenRegister App-Store and feature-request URLs

### Requirement: Catch-all redirect preserved

The vue-router built from the manifest SHALL append a `{ path: '*', redirect:
'/' }` catch-all so unknown paths redirect to the dashboard, matching the prior
router behaviour.

#### Scenario: Unknown path redirects to dashboard

- **WHEN** the user navigates to a path that matches no manifest page
- **THEN** the router redirects to `/` and the dashboard page renders

### Requirement: PHP backbone untouched

This change SHALL be frontend-only. The `ManifestController`, `ManifestService`,
`Application` entity, mappers, and all `lib/` services SHALL NOT be modified, and
the bundled static manifest — NOT the runtime `/api/manifest` endpoint — SHALL
drive the shell.

#### Scenario: No backend files change

- **WHEN** the change is implemented
- **THEN** no file under `lib/` is modified and the shell loads the bundled
  `src/manifest.json` rather than fetching `/api/manifest`
