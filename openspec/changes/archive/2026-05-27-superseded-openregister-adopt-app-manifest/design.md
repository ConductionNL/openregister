# Design — OpenRegister adopts the app manifest

## Approach

Adopt `@conduction/nextcloud-vue`'s app-manifest pattern in **two phases on
one branch**:

- **Phase A — Tier 1.** Generate `src/manifest.json` from the existing
  router. Wire `useAppManifest('openregister', bundled)` in `src/main.js`.
  The router itself keeps its direct component imports. The manifest is
  validated (build-time `check:manifest`, runtime schema validation in the
  composable) but is not yet driving the dispatch.
- **Phase B — Tier 2.** Introduce `CnPageRenderer` for the schema-driven
  routes (Registers, Schemas, Objects, Sources, Endpoints, Search,
  Applications, Entities). Each of these has a clean
  `type:"index"`/`type:"detail"` mapping. Admin / log / settings routes
  remain on direct imports through `type:"custom"` + the consumer-supplied
  `customComponents` map.

Tier 3 (`CnAppNav` replacing the bespoke `NcAppNavigation` mount) and
Tier 4 (`CnAppRoot` full shell) are explicitly deferred. OR's existing
`App.vue` orchestrates loading state, OpenRegister availability checks,
sidebar provisioning, and admin-only navigation gating that do not yet have
a clean `CnAppRoot` analogue. Pushing them through Tier 4 prematurely would
force a bespoke `customRootComponents` escape hatch and lose the foundation-
sets-the-pattern intent.

## Page-type mapping

OR has 30 routes today. Mapping to the closed enum:

| Page id | Route | Type | Note |
|---|---|---|---|
| `Dashboard` | `/` | `dashboard` | Existing DashboardIndex.vue widgetises cleanly |
| `Registers` | `/registers` | `index` | Schema-driven list of Register objects |
| `RegisterDetail` | `/registers/:id` | `detail` | Schema-driven detail view |
| `Schemas` | `/schemas` | `index` | Schema-driven list of Schema objects |
| `SchemaDetails` | `/schemas/:id` | `detail` | |
| `Sources` | `/sources` | `index` | |
| `Objects` | `/objects` | `index` | Generic objects list with filters |
| `Search` | `/tables` | `index` | Magic-table faceted search |
| `Endpoints` | `/endpoints` | `index` | |
| `Applications` | `/applications` | `index` | |
| `ApplicationDetails` | `/applications/:id` | `detail` | |
| `Entities` | `/entities` | `index` | |
| `EntityDetail` | `/entities/:id` | `detail` | |
| `Organisations` | `/organisation` | `custom` | Tenant-aware bespoke widgets |
| `Configurations` | `/configurations` | `custom` | Settings-shaped, no schema |
| `AuditTrail` | `/audit-trails` | `custom` | Logs viewer; needs `type:"logs"` future built-in |
| `SearchTrail` | `/search-trails` | `custom` | Same — log viewer |
| `Webhooks` | `/webhooks` | `custom` | Bespoke pipeline UI |
| `WebhookLogs` | `/webhooks/logs` | `custom` | |
| `Templates` | `/templates` | `custom` | Bespoke template editor |
| `Agents` | `/agents` | `custom` | AI agents config UI |
| `Chat` | `/chat` | `custom` | Realtime chat shell |
| `Files` | `/files` | `custom` | Files workspace, not standard list |
| `Deleted` | `/deleted` | `custom` | Recycle-bin view |
| `AVG` | `/avg` | `custom` | Verwerkingsregister bespoke UI |
| `Reports` | `/reports` | `custom` | Could move to `dashboard` but uses non-widget layout |
| `ReportView` | `/reports/:id` | `custom` | Same |
| `MyAccount` | `/mijn-account` | `custom` | Bespoke account/profile view |

**Tally:** 1 dashboard + 8 index + 4 detail + 17 custom = 30 routes. The
17/30 custom share (57%) is high. That is **expected** for the foundation
repo and is the trigger for Open Question 2 below.

## Menu mapping

The existing `App.vue` / `NcAppNavigation` listing maps to one `menu` array
with two `section` values:

```jsonc
[
  { "id": "Dashboard",       "label": "openregister.dashboard",       "icon": "icon-category-dashboard", "route": "Dashboard",     "section": "main",     "order": 10 },
  { "id": "Registers",       "label": "openregister.registers",       "icon": "icon-database",           "route": "Registers",     "section": "main",     "order": 20 },
  { "id": "Schemas",         "label": "openregister.schemas",         "icon": "icon-quota",              "route": "Schemas",       "section": "main",     "order": 30 },
  { "id": "Objects",         "label": "openregister.objects",         "icon": "icon-folder",             "route": "Objects",       "section": "main",     "order": 40 },
  { "id": "Search",          "label": "openregister.search",          "icon": "icon-search",             "route": "Search",        "section": "main",     "order": 50 },
  { "id": "Sources",         "label": "openregister.sources",         "icon": "icon-link",               "route": "Sources",       "section": "main",     "order": 60 },
  { "id": "Endpoints",       "label": "openregister.endpoints",       "icon": "icon-api",                "route": "Endpoints",     "section": "main",     "order": 70 },
  { "id": "Configurations",  "label": "openregister.configurations",  "icon": "icon-settings",           "route": "Configurations","section": "settings", "order": 80 },
  { "id": "AuditTrail",      "label": "openregister.auditTrail",      "icon": "icon-history",            "route": "AuditTrail",    "section": "settings", "order": 81 },
  { "id": "Settings",        "label": "openregister.settings",        "icon": "icon-settings",           "route": "MyAccount",     "section": "settings", "order": 99 }
]
```

`label` values are i18n keys per ADR-024 §6 / ADR-007. The strings live in
`l10n/{en,nl}.js` keyed by the `appname.<key>` pattern.

## Files affected

- **New**:
  - `src/manifest.json` (~140 lines: 30 pages + 10 menu items + dependencies + version + $schema)
  - `docs/manifest.md` (~80 lines: page-type mapping and rationale)
- **Modified (Phase A)**:
  - `src/main.js` — add `import bundled from './manifest.json'` and pass to
    `useAppManifest('openregister', bundled)`. The composable's return is
    not consumed yet at Tier 1; the wiring exists so Tier 2 can flip on.
  - `package.json` — add `"check:manifest": "node node_modules/@conduction/nextcloud-vue/bin/validate-manifest.js src/manifest.json"` to `scripts`.
- **Modified (Phase B)**:
  - `src/router/index.js` — replace the schema-driven imports with
    `CnPageRenderer` lookups keyed by the manifest. Admin / custom routes
    keep their direct imports via the `customComponents` registry.
- **Untouched**: `src/App.vue`, `src/views/**`, `src/store/**`, every
  controller / mapper / service in `lib/`. Manifest adoption at Tier 1-2 is
  zero-touch on the views themselves.

## Citations and dependencies

OR's manifest is the consumer side of an already-shipped library spec. It
also references several OR specs that just merged in #1420; those are NOT
adopted by this change but are listed so the manifest can layer on top of
them later:

- **Library contract**:
  `nextcloud-vue/openspec/changes/add-json-manifest-renderer/specs/json-manifest-renderer/spec.md`
  — 17 REQ-JMR-* requirements that this manifest must satisfy.
- **Schema source**:
  `nextcloud-vue/src/schemas/app-manifest.schema.json` — pinned via `$schema`
  in `manifest.json`. Apps MUST NOT fork or duplicate this schema (ADR-024
  §1).
- **Cross-app convention**:
  `hydra/openspec/architecture/adr-024-app-manifest.md` — fleet-wide
  adoption ADR. This change is the per-app adoption ADR-024 §9 calls for.
- **Loader**: `nextcloud-vue/src/composables/useAppManifest.js` — silent
  fallback on `/api/manifest` 404; bundled-only path is CSP-clean.
- **Renderer**: `nextcloud-vue/src/components/CnPageRenderer/` — Phase B
  dispatches schema-driven routes through this component.
- **Existing OR specs to layer on later (NOT adopted in this change)**:
  - `openregister/openspec/specs/register-resolver-service/spec.md` — once
    the `pages[].config.{register, schema}` references resolve to slugs,
    Phase B's `CnPageRenderer` calls into the resolver instead of inline
    `getValueString(...)`. Tracked as a follow-up.
  - `openregister/openspec/specs/pluggable-integration-registry/spec.md` —
    Tier-3+ App Builder hooks (admin reorders menu via `/api/manifest`)
    will register through this. Out of scope here.
  - `openregister/openspec/specs/i18n-source-of-truth/spec.md` and
    `openregister/openspec/specs/i18n-api-language-negotiation/spec.md` —
    the manifest's `label` / `title` are i18n keys per ADR-024 §6 + ADR-007
    + ADR-025; `App.vue`'s language negotiation is the consumer side.
  - `nextcloud-vue/openspec/changes/multi-tenancy-context/` (#113) — the
    tenant badge slot in `CnAppRoot` (Tier 4) is the future home for OR's
    tenant context. Tier 1-2 doesn't expose it.

## Out of scope

- **Backend `/api/manifest` endpoint.** Deferred to a follow-up change. The
  composable silently falls back to bundled-only on 404, so absence is not
  a regression. The follow-up is driven by an App Builder use case (admin
  reorders menu, hides pages, overrides locale per tenant) — not by the
  Tier 1-2 work.
- **Tier 3 (`CnAppNav`)** — replacing the existing `NcAppNavigation` mount
  in `App.vue` is a separate change.
- **Tier 4 (`CnAppRoot`)** — same reasoning. OR's `App.vue` orchestrates
  enough bespoke loading / availability / sidebar logic that a clean Tier 4
  swap is not yet justified.
- **Schema enum extension.** If `type:"custom"` proportion stays >50% after
  Tier 2 lands, the right move is a library-side openspec change adding new
  built-in types (e.g. `type:"logs"`, `type:"settings"`). That is a
  nextcloud-vue change, not an OR change.
- **Reviewer-side drift gate.** A future Hydra gate that diffs
  `src/manifest.json` against `src/router/index.js` route names to catch
  missing manifest entries is desirable (pairs with ADR-029 route-
  reachability). Out of scope here.

## Open questions

1. **Backend `/api/manifest` ownership** — when OR ships its own endpoint,
   should it be a thin per-app passthrough or should OR centralise the
   endpoint for every Conduction app on top of it (one OR endpoint that
   returns the merged manifest for the calling appId)? Per-app is the
   ADR-024 default; OR-central is tempting because the foundation already
   owns the multi-tenancy + RBAC layer the App Builder would need. Defer to
   the App Builder change.
2. **Page-type enum extensibility** — OR's 17/30 custom share is the highest
   single signal in the fleet that the closed `type` enum is too narrow.
   Should we open a nextcloud-vue change adding `type:"logs"` (audit-trail-
   shaped), `type:"settings"` (form-page-shaped), and possibly
   `type:"chat"` / `type:"files"` (workspace-shaped) as built-ins? The cost
   is library-side; the gain is every consumer reduces its `type:"custom"`
   share. Surface this as a follow-up nextcloud-vue change once Tier 2
   lands.
3. **Manifest version semver** — start at `0.1.0`. Bump to `0.2.0` when
   Phase B (Tier 2) ships. Hold at sub-1.0.0 until the page-type-enum
   question is resolved upstream — bumping to `1.0.0` while the underlying
   schema is still expected to grow new built-in types would force a
   premature `manifest.version` major-bump cascade across consumers.
