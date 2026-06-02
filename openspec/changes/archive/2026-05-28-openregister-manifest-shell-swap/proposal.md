## Why

OpenRegister is the platform foundation every other Conduction app sits on, yet
it is the only repo in the fleet whose own frontend does not eat its own
dogfood: it mounts a bespoke `NcContent` + hand-rolled `vue-router` (~32 routes)
instead of the `@conduction/nextcloud-vue` `CnAppRoot` manifest shell that
decidesk, procest, pipelinq, zaakafhandelapp, softwarecatalog and opencatalogi
already run on. The prior change `openregister-adopt-app-manifest` scoped only
Tier 1–2 (manifest file + `CnPageRenderer` for schema-driven views) and
explicitly **deferred** Tier 3/4; reality has since diverged. OR already ships a
populated `manifest.json` (24 menu entries, `pages: []`) consumed by a
manifest-driven `MainMenu.vue` via `CnAppNav`, and the fleet has standardised on
the full Tier-4 `CnAppRoot` shell swap. The half-step left OR with a manifest the
renderer never dispatches against and an `App.vue` that re-rolls shell concerns
the library now owns. This change **supersedes** `openregister-adopt-app-manifest`
and completes the migration to the full shell.

## What Changes

- **Supersedes** `openspec/changes/openregister-adopt-app-manifest` (Tier 1–2,
  deferred Tier 3/4). That change's two-phase plan is abandoned in favour of a
  single Tier-4 shell swap, matching the fleet.
- Populate `src/manifest.json` `pages[]` — one entry per existing route (~32),
  reconciled against the existing 24 `menu[]` entries so no page duplicates or
  contradicts the menu. Schema-driven pairs MAY use built-in `type:"index"` /
  `type:"detail"`; the dashboard uses `type:"dashboard"`; bespoke admin / log /
  settings pages use `type:"custom"` referencing components by string name. All
  page ids unique; menu `label` values stay i18n keys. A full page-type mapping
  table for every route lives in `design.md`.
- Add a repository-specific component registry (`src/customComponents.js`, flat
  page-name → component map per the decidesk convention) mapping `type:"custom"`
  string names to OR's existing view components. Views change minimally.
- Rewrite `src/main.js`: build the `vue-router` config from `manifest.pages` via
  a `routesFromManifest` helper, clone `CnPageRenderer`
  (`RoutePageRenderer = { ...CnPageRenderer }` — Vue 2 `_Ctor` non-extensible
  trap), clone the registry maps (`{ ...customComponents }`,
  `{ ...defaultPageTypes }`), KEEP the existing `registerIcons(...)` block and
  the integration-registry bootstrap (`ensureIntegrationRegistry()` + xwiki
  descriptor), and mount `App.vue` with `manifest` + `customComponents` +
  `pageTypes` props — mirroring `decidesk/src/main.js`.
- Rewrite `src/App.vue` to render `<CnAppRoot>`:
  - `#menu` slot → keep the existing `MainMenu.vue` (preserves the active-
    organisation switcher in `CnAppNav`'s `#primary-action`).
  - `#sidebar` slot → `SideBars` + the existing `CnObjectSidebar use-registry`
    (decidesk pattern).
  - `#footer` slot → `Modals` + `Dialogs` (docudesk pattern).
  - Drop the manual `CnSupportDialog` (auto-mounted by `CnAppRoot` from
    `appId="openregister"`; derived URLs match the current manual ones).
  - Keep `initializeAppData()` + `setupDashboardStoreWatchers()` in `mounted()`
    and the `objectSidebarState` `provide()`.
  - Pass a translate closure `(key) => t('openregister', key)`.
- **MANDATORY correctness item A** — pass `:requires-apps="[]"` to `CnAppRoot`.
  Its default is `['openregister']`, which would render the "OpenRegister
  missing" empty-state INSIDE OpenRegister. OR is the foundation and must not
  guard on its own capability.
- **MANDATORY correctness item B** — the v2 manifest + `customComponents` prop
  combination trips `CnAppRoot`'s ADR-036 deprecation warning recommending the
  v2 `registry` prop. This change matches the rest of the fleet now
  (`customComponents`, accept the warning); the ADR-036 `registry` migration is
  a separate fleet-wide follow-up.
- Add `npm run check:manifest` (`validate-manifest.js`) wired into the check
  chain if not already present.
- **PHP backbone untouched** — `ManifestController` / `ManifestService` /
  `Application` entity / mappers stay as-is. Frontend-only. Bundled static
  manifest (NOT the runtime `/api/manifest` path).

## Capabilities

### New Capabilities

- `manifest-app-shell`: OpenRegister's own frontend mounts the
  `@conduction/nextcloud-vue` `CnAppRoot` shell driven by the bundled
  `src/manifest.json`. Covers: every route declared as a manifest page and
  dispatched through `CnPageRenderer`; the `#menu` / `#sidebar` / `#footer` slot
  composition; the `:requires-apps="[]"` foundation guard opt-out (highest-
  priority correctness item); the auto-mounted support dialog; the
  customComponents registry mapping `type:"custom"` page names to OR views; and
  the catch-all `*` → `/` redirect preservation.

### Modified Capabilities

*(none — this is a frontend shell composition. The PHP backbone, registers,
schemas, objects, and API contracts are unchanged; no existing spec's
requirements change.)*

## Impact

- **Modified files (frontend only)**:
  - `src/manifest.json` — populate `pages[]` (~32 entries) reconciled with the
    existing 24 `menu[]` entries.
  - `src/main.js` — `routesFromManifest` + cloned `CnPageRenderer` + cloned
    registry maps + `CnAppRoot` props; KEEP `registerIcons` + integration
    bootstrap.
  - `src/App.vue` — `<CnAppRoot>` with `#menu` / `#sidebar` / `#footer` slots,
    `:requires-apps="[]"`, translate closure; drop manual `CnSupportDialog`.
  - `src/router/index.js` — superseded by `routesFromManifest` in `main.js`;
    reduced to the `routeKeyByPath` export if still consumed, else removed.
  - `package.json` — add `check:manifest` if absent.
- **New files**:
  - `src/customComponents.js` — flat page-name → component registry for
    `type:"custom"` pages.
- **Untouched**: every `lib/` controller / mapper / service / entity; the
  `ManifestController` / `ManifestService` runtime `/api/manifest` endpoint; all
  OR views (referenced as-is from the registry); `MainMenu.vue` (re-used in the
  `#menu` slot, only its host changes).
- **Dependency**: `@conduction/nextcloud-vue ^1.0.0-beta.103` (already pinned) —
  ships `CnAppRoot`, `CnPageRenderer`, `CnAppNav`, `defaultPageTypes`,
  `useAppManifest`, `validateManifest`.
- **i18n**: nl + en — menu/page `label`/`title` stay i18n keys per ADR-024 §6 /
  ADR-007 / ADR-025.
- **References**: ADR-024 (app manifest, Tier-4 full shell), ADR-036 (v2 widget
  manifest — open design decision in `design.md`), the superseded
  `openregister-adopt-app-manifest` change, and
  `nextcloud-vue/src/components/CnAppRoot/CnAppRoot.vue` (slots/props/phases).
