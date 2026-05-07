# OpenRegister — Adopt App Manifest (Tier 1 → Tier 2)

## Why

OpenRegister is the platform foundation. Every other Conduction app (decidesk,
opencatalogi, docudesk, mydash, softwarecatalog, procest, pipelinq, larpingapp,
zaakafhandelapp, openconnector) sits on top of OR's data layer and is expected
to adopt the `@conduction/nextcloud-vue` app-manifest pattern per
[ADR-024](../../../../hydra/openspec/architecture/adr-024-app-manifest.md).
Today OR ships its own bespoke `src/router/index.js` (95 lines, 30 routes) with
no `manifest.json`, no `useAppManifest` wiring, and no `CnAppRoot` shell. The
foundation is the only repo in the fleet that does not eat its own dogfood.

That is a credibility problem. Decidesk has been Tier-4 since v0.3.0 (39 pages,
8 menu entries, depending on `["openregister"]`). Two reviewer false-positives
during the 2026-05-03 audit cited "OR's router is the canonical pattern" — the
canonical pattern is the manifest, and OR not adopting it actively confuses
the fleet.

This change brings OR onto the manifest. **Tier 1 first, then Tier 2.** OR has
~30 admin / settings / log views (ConfigurationsIndex, AuditTrailIndex,
SearchTrailIndex, WebhooksIndex, AgentsIndex, ChatIndex, FilesIndex, etc.)
that do not map cleanly into the closed `type` enum (`index | detail |
dashboard | custom`). Forcing the whole router into Tier 4 would create a
sprawl of `type:"custom"` entries and lock OR's bespoke pages to the registry-
component pattern before we know whether the library should grow new built-in
types. Tier 1 (`useAppManifest` only) gives the consumer app-builder hook with
zero shell change. Tier 2 (`+ CnPageRenderer`) lets the schema-driven views
(Registers, Schemas, Objects, Sources, Endpoints, Search, Settings) move to
the renderer — the views that genuinely fit `index | detail`. Admin pages
stay on the existing components and are flagged `type:"custom"`.

## What Changes

- Add **`src/manifest.json`** at the OR root, validated against the canonical
  schema URL (`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`).
  Generated from the existing 30 routes in `src/router/index.js`.
- Set `manifest.version` to `0.1.0` (initial Tier 1) — bumps to `0.2.0` once
  Tier 2 is wired through `CnPageRenderer`. Stays sub-1.0.0 until the closed
  `type` enum question is resolved (see Open Questions in design.md).
- Set `manifest.dependencies` to `[]` — OR has no upstream Conduction-app
  dependencies. Per ADR-024 §10, this is the only sensible value for the
  foundation repo. (`mydash` is the other repo with `[]`; everything else
  depends on at least `["openregister"]`.)
- Add **manifest entries** for the 8 schema-driven page pairs that fit
  `type:"index"`/`type:"detail"`: Registers, Schemas, Objects, Sources,
  Endpoints, Search (`/tables`), Applications, Entities. The Dashboard page
  becomes `type:"dashboard"`.
- Add **`type:"custom"` entries** for the admin / log / settings pages that
  don't fit the closed enum: Configurations, AuditTrail, SearchTrail,
  Webhooks, WebhookLogs, Templates, Agents, Chat, Files, Deleted,
  Organisations, AVG, Reports (index + detail), MyAccount.
- Add **`menu[]`** with the existing top-level navigation entries plus a
  `section: "settings"` block for Configurations / Logs / Settings.
- Wire `useAppManifest('openregister', bundled)` in `src/main.js` (Tier 1
  loader). The composable's silent fallback on `/api/manifest` 404 means
  no backend changes are required to ship.
- Add **`npm run check:manifest`** to `package.json` per ADR-024 §5; CI fails
  on schema errors.
- Add **`docs/manifest.md`** documenting the OR-specific page-type mapping
  (which routes are which type, why admin pages are `custom`).
- **Backend `/api/manifest` endpoint** — explicitly **deferred** to a
  follow-up change. The Tier 1-2 work doesn't need it; an admin-customisation
  use case (App Builder hide-page / reorder-menu) is the right driver. Spec
  the deferral in `tasks.md` so the gap is tracked.
- **Tier 3 (`+ CnAppNav`) and Tier 4 (`CnAppRoot` full shell)** — explicitly
  **deferred** to a successor change. OR's existing `App.vue` mounts the
  Nextcloud `NcAppNavigation` directly and shares the sidebar with admin
  workflows; the cost of unwinding that for `CnAppNav` is not justified at
  this stage.

## Capabilities

### New Capabilities

- `openregister-app-manifest`: a `src/manifest.json` describing OR's UI shape
  (menu, pages, dependencies) per the canonical schema, consumed by
  `useAppManifest('openregister', bundled)` at runtime, validated at build
  time via `npm run check:manifest`. Tier 1 + Tier 2 (`CnPageRenderer` for
  schema-driven views).

### Modified Capabilities

*(none — the manifest is purely additive at Tier 1; Tier 2 swaps the
schema-driven views' router-view dispatch from direct component imports to
`CnPageRenderer` but does not modify the views themselves.)*

## Impact

- **New files**:
  - `openregister/src/manifest.json` (~50 page entries, ~10 menu entries)
  - `openregister/docs/manifest.md` (mapping rationale)
- **Modified files**:
  - `openregister/src/main.js` — add `useAppManifest` wiring (Tier 1)
  - `openregister/src/router/index.js` — Tier 2 only: dispatch schema-driven
    routes through `CnPageRenderer` instead of direct imports
  - `openregister/package.json` — add `check:manifest` script
- **No backend changes** — `/api/manifest` is deferred.
- **No schema/register changes** — manifest is FE-only per ADR-024.
- **Dependency**: pinned floor of `@conduction/nextcloud-vue` ≥ the version
  that ships `useAppManifest`, `CnPageRenderer`, and the schema. Recorded in
  `package.json` peerDependencies.
- **Validates against**: shared schema at
  `nextcloud-vue/src/schemas/app-manifest.schema.json` (153 lines, JSON
  Schema draft 2020-12, 17 REQ-JMR-* requirements at
  `nextcloud-vue/openspec/changes/add-json-manifest-renderer/`).
- **References in the audit**:
  - `.claude/audit-2026-05-03/research/R6-manifest-json.md` — full pattern
    reference; flags OR as "Tier 1-2 first"
  - `.claude/audit-2026-05-03/00-executive-summary.md §1` — adoption-not-
    feature-work as the headline cleanup theme
  - ADR-024 §10 — `manifest.dependencies = []` is the correct value for OR

## Risks

- **Closed `type` enum vs. OR's bespoke pages.** Mitigated by accepting
  `type:"custom"` for ~20 of OR's 30 routes at this stage. If `custom`
  proportion stays >50% after Tier 2 lands, that is a signal to either grow
  the library's enum (new built-ins like `type:"logs"`, `type:"settings"`,
  `type:"chat"`) or scope OR's router differently. Tracked as Open Question 2
  in design.md.
- **Tier 2 router wiring touches every schema-driven view.** Mitigated by
  shipping Tier 1 alone first (manifest exists, but router stays direct-
  import); Tier 2 lands as a follow-up commit on the same branch once the
  manifest itself is validated. Each schema-driven route gets a regression
  test confirming it still resolves and renders.
- **Manifest drift from router.** Mitigated by `check:manifest` (build-time
  schema validation) plus a planned reviewer-side gate that diffs
  `src/manifest.json` against `src/router/index.js` route names — captured
  as a follow-up in `tasks.md` §6.
