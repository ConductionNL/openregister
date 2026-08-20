## Context

OpenRegister's frontend mounts a bespoke shell: `src/App.vue` renders
`<NcContent app-name="openregister">` with hand-wired `MainMenu` / `Views` /
`SideBars` / `Modals` / `Dialogs` children, a manual `CnObjectSidebar`, and a
manual `CnSupportDialog`. Routing lives in `src/router/index.js` — a hand-rolled
`vue-router` with 31 real routes plus a `*` → `/` catch-all. `src/manifest.json`
already exists with a v2 `$schema`, `version: "1.0.0"`, 24 `menu[]` entries, but
`pages: []` — so the manifest drives only the navigation (`MainMenu.vue` →
`CnAppNav`) and nothing dispatches against `pages[]`.

The prior change `openregister-adopt-app-manifest` planned a two-phase Tier 1–2
adoption and explicitly deferred the Tier 3/4 shell swap. The fleet has since
moved past that: decidesk (reference Tier-4), procest, pipelinq,
zaakafhandelapp, softwarecatalog and docudesk all run the full `CnAppRoot`
shell. This change supersedes the prior one and completes OR's migration to the
full shell. The library contract is
`@conduction/nextcloud-vue@^1.0.0-beta.103`'s `CnAppRoot` (slots `#menu`,
`#sidebar`, `#footer`, `#loading`, `#dependency-missing`, `#or-missing`; props
`manifest`, `appId`, `customComponents`, `pageTypes`, `translate`, `permissions`,
`requiresApps`, `supportDialog`).

## Goals / Non-Goals

**Goals:**

- Mount OR's own frontend on `CnAppRoot`, driven by the bundled
  `src/manifest.json`.
- Declare every existing route as a manifest page (`pages[]`), reconciled against
  the existing 24 `menu[]` entries (no duplication / contradiction).
- Preserve every current behaviour: the active-organisation switcher in
  `CnAppNav`'s `#primary-action`; the registry-driven `CnObjectSidebar`; modals
  and dialogs; `initializeAppData()` + `setupDashboardStoreWatchers()`; the
  `objectSidebarState` provide; the catch-all `*` → `/` redirect.
- Opt out of the OR-availability guard (`:requires-apps="[]"`) — the single
  highest-priority correctness item.
- Let `CnAppRoot` auto-mount the support dialog and drop the manual one.

**Non-Goals:**

- No PHP backbone change. `ManifestController` / `ManifestService` /
  `Application` entity / mappers are untouched. The manifest is bundled and
  static; the runtime `/api/manifest` path is NOT used by this change.
- No view rewrites. OR's existing view components are referenced as-is from the
  customComponents registry; only minimal prop-plumbing changes if any.
- No ADR-036 `registry`-prop migration. This change uses `customComponents` and
  accepts the v2 deprecation warning, matching the fleet (see Decisions).
- No menu restructuring. The 24 menu entries stay byte-for-byte; only `pages[]`
  is added.

## Decisions

### D1 — Full Tier-4 `CnAppRoot` shell swap (supersedes Tier 1–2 plan)

The superseded change scoped Tier 1–2 only, citing the closed `type` enum and
OR's high custom-page share. Since then the fleet has proven Tier-4 with
`type:"custom"` escape hatches, and OR already ships a manifest-driven menu. A
half-migrated shell is more confusing than either extreme. **Decision:** do the
full shell swap now. *Alternative considered:* keep Tier 2 (router dispatches
schema-driven pages through `CnPageRenderer`, `App.vue` keeps `NcContent`).
Rejected — leaves OR off the fleet shell and keeps re-rolling shell concerns the
library owns.

### D2 — Build the router from the manifest in `main.js`

Mirror `decidesk/src/main.js`: a `routesFromManifest(manifest)` helper maps each
`manifest.pages[]` entry to `{ name: page.id, path: page.route, component:
RoutePageRenderer, props: page.route.includes(':') }`, then appends
`{ path: '*', redirect: '/' }`. `RoutePageRenderer = { ...CnPageRenderer }` — a
shallow clone, because Vue 2's `Vue.extend()` attaches a non-enumerable `_Ctor`
cache to the component options and the library's barrel export is a
non-extensible ESM module record (mutating it throws "Cannot add property
_Ctor"). The registry maps passed to `CnAppRoot` are cloned the same way
(`{ ...customComponents }`, `{ ...defaultPageTypes }`). The standalone
`src/router/index.js` is superseded; its `routeKeyByPath` export is preserved
inline (or re-homed) only if a consumer still imports it. *Alternative:* keep
`router/index.js` and import the manifest there. Rejected — `main.js` is where
the fleet builds the router and where the cloned `CnPageRenderer` + registry
props are assembled.

### D3 — Page-type mapping: split schema-driven pages to built-ins, keep bespoke as `type:"custom"`

`CnPageRenderer` dispatches `index`/`detail`/`dashboard` built-ins against an
OpenRegister backend via `useObjectStore`. OR's OWN data model (Register, Schema,
Source, Endpoint, Application, Entity, plus the generic Objects/Search tables) IS
that backend, so those pages can use built-in types directly. Bespoke admin /
log / settings / workspace pages (configurations, audit/search trails, webhooks,
templates, agents, chat, files, deleted, AVG, reports, my-account,
features-roadmap, integrations-harness) have no clean built-in and use
`type:"custom"` referencing OR's existing view by string name. See the full
mapping table below. *Alternative:* keep ALL pages as `type:"custom"` for a
low-risk first pass (the prior design measured a 57% custom share). Rejected as
the default because OR's schema-driven views are exactly what the built-in types
exist for and matching the fleet's index/detail usage is the whole point — but
this is recorded as a deferred question, since the built-in index/detail
renderers must resolve OR's own register/schema slugs at runtime, which carries
verification risk. A conservative fallback (all-custom) is available if browser
verification surfaces renderer/slug mismatches.

### D4 — `customComponents` flat map (decidesk convention), not docudesk's kinded `{pages:{}}`

decidesk's `customComponents.js` is a flat `{ ComponentName: Component }` map
passed directly as the `customComponents` prop — which is exactly the shape
`CnPageRenderer` resolves `page.component` against. docudesk's
`{ pages, headers, actions, sidebarTabs, cells }` shape is the ADR-036 kinded
registry intended for the `registry` prop. Since D5 keeps the `customComponents`
prop, OR uses the flat decidesk shape. *Alternative:* docudesk's kinded shape.
Rejected — that targets the `registry` prop this change is not adopting yet.

### D5 — Keep `customComponents` prop; accept the ADR-036 deprecation warning (MANDATORY item B)

`CnAppRoot._warnCustomComponentsDeprecation()` emits one `console.warn` per mount
when a v2 manifest (`$schema` contains `app-manifest-v2`) is combined with a
non-empty `customComponents` prop, recommending the v2 `registry` prop. OR's
manifest already carries the v2 `$schema`. **Decision:** match the rest of the
fleet — pass `customComponents`, accept the single warning. A fleet-wide ADR-036
`registry`-prop migration is a separate follow-up issue. *Alternative:* adopt the
v2 `registry` prop now (kinded entries with `kind:"page"`). Rejected for THIS
change — it would make OR the first fleet app on the new prop, diverging from the
reference implementations and expanding scope beyond a shell swap. (Recorded as a
deferred question.)

### D6 — `:requires-apps="[]"` (MANDATORY item A, highest priority)

`CnAppRoot`'s `requiresApps` prop defaults to `['openregister']`; on mount it
reads `getCapabilities()` and renders the "OpenRegister missing"
`NcEmptyContent` when any required app is absent. Inside OpenRegister this would
guard OR on its own capability — if the capabilities payload doesn't advertise
the `openregister` key the entire app would render the missing-app empty state.
OR is the foundation and must never guard on itself. **Decision:** pass
`:requires-apps="[]"`, which also fast-paths the capabilities check (no spinner
flash, mounts on first render). This is the single highest-priority correctness
item and gets its own top spec scenario. *Alternative:* rely on the
capabilities-failure fall-through. Rejected — it depends on a failure path, not
an explicit contract, and would still flash the capabilities-loading spinner.

### D7 — Slot composition mirrors the fleet

- `#menu` → existing `MainMenu.vue` (it already wraps `CnAppNav` and renders the
  org-switcher in `#primary-action`). Keeping it as the `#menu` override
  preserves the switcher, which the manifest's static `nav.primaryAction` cannot
  express (live store state).
- `#sidebar` → `SideBars` + the existing `CnObjectSidebar use-registry` block
  (decidesk pattern), gated on `objectSidebarState.active`.
- `#footer` → `Modals` + `Dialogs` (docudesk footer pattern).
- Support dialog: drop the manual `<CnSupportDialog>` — `CnAppRoot` auto-mounts
  it from `appId="openregister"`, deriving `appStoreUrl =
  https://apps.nextcloud.com/apps/openregister` and `featureRequestUrl =
  https://github.com/ConductionNL/openregister/issues/new`, which match the
  current manual values exactly.

## Page-type mapping (all routes reconciled against the 24 menu entries)

Every route in `src/router/index.js` becomes one manifest page. `page.id` IS the
vue-router route name (`CnPageRenderer` matches `$route.name === page.id`).
Menu-reconciliation column notes which `menu[]` entry (by `route`) points at the
page; menu `route` values reference page ids/route-names, never duplicate page
definitions.

| Page id | Route | Type | Component (custom only) | Menu entry → | Note |
|---|---|---|---|---|---|
| `dashboard` | `/` | `dashboard` | — | (no menu item; default landing) | `DashboardIndex` widgetises; catch-all target |
| `registers` | `/registers` | `index` | — | Registers (main) | Schema-driven list of Register objects |
| `registerDetail` | `/registers/:id` | `detail` | — | — | Register detail (props:true) |
| `schemas` | `/schemas` | `index` | — | Schemas (main) | Schema-driven list of Schema objects |
| `schemaDetails` | `/schemas/:id` | `detail` | — | — | Schema detail (props:true) |
| `sources` | `/sources` | `index` | — | Sources (settings) | Data-source list |
| `organisation` | `/organisation` | `custom` | `OrganisationsIndex` | Organisations (settings) | Tenant-aware bespoke widgets |
| `applications` | `/applications` | `index` | — | Applications (settings) | Application list |
| `applicationDetails` | `/applications/:id` | `detail` | — | — | Application detail (props:true) |
| `objects` | `/objects` | `index` | — | — (reachable via registers) | Generic objects list with filters |
| `objectDetail` | `/objects/:register/:schema/:id` | `custom` | `ObjectsIndex` | — | Deep-link primes objectStore via param watch; reuses ObjectsIndex (NOT a clean detail) |
| `tables` | `/tables` | `index` | — | Tables / Search-views (main) | Magic-table faceted search |
| `chat` | `/chat` | `custom` | `ChatIndex` | Chat (main) | Realtime AI chat shell |
| `files` | `/files` | `custom` | `FilesIndex` | Files (main) | Files workspace, not a standard list |
| `agents` | `/agents` | `custom` | `AgentsIndex` | Agents (main) | AI agents config UI |
| `configurations` | `/configurations` | `custom` | `ConfigurationsIndex` | Configurations (settings) | Settings-shaped, no schema |
| `deleted` | `/deleted` | `custom` | `DeletedIndex` | Deleted (settings) | Recycle-bin view |
| `audit-trails` | `/audit-trails` | `custom` | `AuditTrailIndex` | Audit Trails (settings) | Log viewer (no `type:"logs"` adoption here) |
| `search-trails` | `/search-trails` | `custom` | `SearchTrailIndex` | Search Trails (settings) | Log viewer |
| `webhooks` | `/webhooks` | `custom` | `WebhooksIndex` | Webhooks (settings) | Bespoke pipeline UI |
| `webhooks-logs` | `/webhooks/logs` | `custom` | `WebhookLogsIndex` | — | Webhook log viewer |
| `endpoints` | `/endpoints` | `index` | — | Endpoints (settings) | Endpoint list |
| `entities` | `/entities` | `index` | — | Entities (settings) | Entity list |
| `entityDetails` | `/entities/:id` | `detail` | — | — | Entity detail (props:true) |
| `templates` | `/templates` | `custom` | `TemplatesIndex` | Templates (main) | Bespoke template editor |
| `myAccount` | `/mijn-account` | `custom` | `MyAccount` | — | Bespoke account/profile view |
| `avg` | `/avg` | `custom` | `AvgIndex` | AVG / Verwerkingsregister (settings) | Bespoke verwerkingsregister UI |
| `reports` | `/reports` | `custom` | `ReportsIndex` | Reports (settings) | Non-widget report layout |
| `reportView` | `/reports/:id` | `custom` | `ReportView` | — | Report detail (props:true) |
| `features-roadmap` | `/features-roadmap` | `custom` | `FeaturesRoadmapIndex` | Features & roadmap (footer) | In-product roadmap surface |
| `integrationsView` | `/integrations/:register/:schema/:objectId` | `custom` | `IntegrationsView` | — | Per-leaf screenshot harness surface (props:true) |

**Tally:** 1 dashboard + 8 index (`registers`, `schemas`, `sources`,
`applications`, `objects`, `tables`, `endpoints`, `entities`) + 4 detail
(`registerDetail`, `schemaDetails`, `applicationDetails`, `entityDetails`) + 18
custom = **31 pages**, plus the `*` → `/` catch-all redirect (router-level, not a
page). The `Documentation` menu entry is an external `href` (no page). The
existing menu's 24 entries all point at page ids in this table; no menu entry is
orphaned and no page contradicts a menu route.

Pages carrying a `:` path parameter (`registerDetail`, `schemaDetails`,
`applicationDetails`, `entityDetails`, `objectDetail`, `reportView`,
`integrationsView`) get `props: true` from `routesFromManifest` automatically
(the helper checks `page.route.includes(':')`).

## Risks / Trade-offs

- **[Built-in index/detail renderers may not resolve OR's own register/schema
  slugs the way the bespoke views did]** → mitigated by browser-1 verification of
  every schema-driven route after the swap; a conservative all-`custom` fallback
  (D3 alternative) is documented and available if a built-in page renders empty
  or wrong.
- **[`:requires-apps` regression — losing the opt-out would brick OR inside
  itself]** → mitigated by making `:requires-apps="[]"` an explicit top spec
  scenario AND an explicit task; verified by confirming OR mounts the shell (not
  the missing-app empty state).
- **[ADR-036 deprecation warning noise]** → accepted; one warning per mount,
  matching the fleet. Tracked for a fleet-wide `registry`-prop follow-up.
- **[`_Ctor` non-extensible trap on `CnPageRenderer` / registry maps]** →
  mitigated by the shallow-clone pattern (`{ ...CnPageRenderer }`,
  `{ ...defaultPageTypes }`, `{ ...customComponents }`) per ADR-024 build-time
  prerequisite §4.
- **[`objectDetail` reuses `ObjectsIndex` rather than a true detail component]** →
  kept as `type:"custom"` referencing `ObjectsIndex` so its param-watch deep-link
  behaviour is preserved verbatim; not forced into `type:"detail"`.
- **[Support dialog double-mount]** → mitigated by dropping the manual
  `<CnSupportDialog>`; verify it auto-mounts exactly once.

## Migration Plan

1. Populate `manifest.json` `pages[]` per the table; leave `menu[]` untouched.
2. Add `src/customComponents.js` (flat map of the 18 custom page components).
3. Rewrite `src/main.js`: `routesFromManifest` + cloned `RoutePageRenderer` +
   cloned registry maps; keep `registerIcons` + integration bootstrap; mount
   `App.vue` with `manifest` / `customComponents` / `pageTypes` props.
4. Rewrite `src/App.vue` to `<CnAppRoot>` with `:requires-apps="[]"`, translate
   closure, and `#menu` / `#sidebar` / `#footer` slots; keep `mounted()` hooks
   and the `objectSidebarState` provide; drop the manual `CnSupportDialog`.
5. Reduce/retire `src/router/index.js` (preserve `routeKeyByPath` only if
   consumed).
6. Add `npm run check:manifest` to `package.json` if absent; wire into the check
   chain.
7. Browser-1 verification walk of all ~31 routes.

**Rollback:** revert the frontend commits; the PHP backbone never changed, so no
data or API migration is involved.

## Open Questions

1. **Schema-driven built-ins vs all-custom (D3).** Provisional: split out the 8
   index + 4 detail schema-driven pages to built-ins; keep bespoke pages custom.
   Fallback to all-custom if browser verification fails. Resolve during apply.
2. **ADR-036 `customComponents` vs `registry` prop (D5).** Provisional: keep
   `customComponents`, accept the warning, file a fleet-wide follow-up. Resolve
   fleet-wide, not in this change.
3. **`routeKeyByPath` consumers.** Provisional: grep for imports during apply; if
   none, drop it with `router/index.js`; if any, re-home the constant.
