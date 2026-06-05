# Tasks — OpenRegister adopts the app manifest

## 1. Manifest authoring (Tier 1)

- [ ] 1.1 Create `src/manifest.json` with `$schema` set to the GitHub raw URL of `nextcloud-vue/src/schemas/app-manifest.schema.json` (matches `decidesk/src/manifest.json` line 2); set top-level `version: "0.1.0"` and `dependencies: []` per ADR-024 §10 (OR has no upstream Conduction-app dependencies).
- [ ] 1.2 Add `pages[]` — exactly 30, one per route in `src/router/index.js`. Each entry sets `id`, `route`, `type`, `title` (custom-type entries also set `component` to the existing view's registered name). Mark `Dashboard` as `type:"dashboard"` mapping the existing widget config into `pages[].config.{widgets, layout}`. All `pages[].id` MUST be unique.
- [ ] 1.3 Mark the 8 schema-driven page pairs (Registers/Schemas/Objects/Sources/Endpoints/Search/Applications/Entities) as `type:"index"` and their detail variants as `type:"detail"`, with `pages[].config.{register, schema}` referencing the underlying OR schemas by slug.
- [ ] 1.4 Mark the remaining 17 routes (Configurations, AuditTrail, SearchTrail, Webhooks, WebhookLogs, Templates, Agents, Chat, Files, Deleted, Organisations, AVG, Reports, ReportView, MyAccount) as `type:"custom"` with a `component` reference. List the per-route rationale in `docs/manifest.md`.
- [ ] 1.5 Add `menu[]` with the navigation entries from design.md — `section:"main"` for primary nav, `section:"settings"` for the configuration / log / settings group. `label` values MUST be i18n keys (`openregister.<key>`), not raw strings (ADR-024 §6).

## 2. Loader wiring (Tier 1)

- [ ] 2.1 In `src/main.js`, `import bundled from './manifest.json'` and `import { useAppManifest } from '@conduction/nextcloud-vue'`, then call `useAppManifest('openregister', bundled)` in the bootstrap. Return value can stay unused at Tier 1 — the call exists so Tier 2 can flip on.
- [ ] 2.2 Run the app, inspect devtools network tab, verify the loader silently falls back when `/api/manifest` returns 404 (no console error). Confirm no behavioural change at Tier 1 — every existing route still resolves and renders identically; the manifest is loaded but not yet driving dispatch.

## 3. Build-time validation

- [ ] 3.1 Add `"check:manifest": "node node_modules/@conduction/nextcloud-vue/bin/validate-manifest.js src/manifest.json"` to `package.json` scripts, wire it into the existing `check` / `check:strict` chain, and confirm CI's lint job runs `npm run check:manifest` (fail on schema errors). Document in `docs/manifest.md`: build fails if the manifest does not validate; never edit `manifest.json` past validation without re-running the check.

## 4. Tier 2 wiring (CnPageRenderer for schema-driven routes)

- [ ] 4.1 In `src/router/index.js`, replace the direct imports for the 8 schema-driven page pairs with a single `CnPageRenderer` lookup keyed by route name (the renderer reads `pages[].type` from the manifest and dispatches accordingly). For `type:"custom"` routes, register the existing components in a `customComponents` map and pass it to the renderer's lookup.
- [ ] 4.2 Verify the schema-driven views (RegistersIndex, RegisterDetail, SchemasIndex, SchemaDetails, SourcesIndex, ObjectsIndex, SearchIndex, EndpointsIndex, ApplicationsIndex, ApplicationDetails, EntitiesIndex, EntityDetail) still receive the same props and behave identically; confirm every custom route still resolves. Bump `manifest.version` to `"0.2.0"` once Tier 2 is wired through.

## 5. Regression tests

- [ ] 5.1 Browser test (browser-1 per project rules) — navigate to each of the 30 routes in sequence; each must render without error and match the pre-change screenshot. Capture screenshots into `.playwright-mcp/manifest-tier{1,2}-route-<id>.png`. Verify `Dashboard` widgets render through the `type:"dashboard"` path identically to the pre-change `DashboardIndex.vue`; schema-driven `index`/`detail` routes show identical column layouts + detail panes after Tier 2; `type:"custom"` routes (admin / log / settings) still render their bespoke components.
- [ ] 5.2 Verify the `404 → /` catch-all behaviour from the pre-change router still works after Tier 2 (the manifest does not declare the catch-all; it stays a router-level rule). Manual devtools inspection: `useAppManifest` return value matches the bundled JSON when `/api/manifest` returns 404.

## 6. Documentation

- [ ] 6.1 Add `docs/manifest.md` documenting: the page-type mapping table from design.md; why 17/30 routes are `type:"custom"` (page-type enum question); the Tier 1 → Tier 2 staging plan; the deferred `/api/manifest` backend endpoint rationale; the follow-up tasks tracked in §7. Update the OR `README.md` to reference `docs/manifest.md` from the architecture section.

## 7. Follow-ups — file as GitHub issues (per ADR-022 / project policy)

- [ ] 7.1 File six follow-up GH issues (one each) and link in proposal.md: (a) backend `/api/manifest` endpoint driven by an App Builder change — slug `openregister-app-manifest-backend`; (b) nextcloud-vue page-type enum extensions for `type:"logs"`, `type:"settings"`, and possibly `type:"chat"`/`type:"files"` — slug `add-app-manifest-page-types`; (c) Tier 3 (`CnAppNav`) — slug `openregister-adopt-app-manifest-tier-3`; (d) Tier 4 (`CnAppRoot`) — slug `openregister-adopt-app-manifest-tier-4` (blocked on `CnAppRoot` exposing loading / OR-availability / sidebar slots); (e) Hydra reviewer-side drift gate that diffs `src/manifest.json` against `src/router/index.js` route names — slug `hydra-gate-manifest-route-drift`; (f) `openregister-manifest-uses-register-resolver` to swap inline `getValueString(...register/schema...)` lookups for the canonical `register-resolver-service` once Phase B lands.

## 8. Sign-off checklist (per ADR-024 §9)

- [ ] 8.1 `src/manifest.json` exists and validates against the canonical schema; Tier choice is explicit (Tier 2 on this change; Tier 3 / Tier 4 deferred); regression-test suite confirms all 30 routes still resolve and render; reviewer confirms the manifest does not duplicate or contradict the canonical schema (no forked schema, no extra top-level fields); `manifest.dependencies` is `[]` (foundation repo per ADR-024 §10); `manifest.version` reflects the actual Tier (0.1.0 = Tier 1 only; 0.2.0 = Tier 2 wired through); audit references in proposal.md / design.md cite the right files (`R6-manifest-json.md`, ADR-024).
