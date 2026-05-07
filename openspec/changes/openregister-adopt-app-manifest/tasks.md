# Tasks — OpenRegister adopts the app manifest

## 1. Manifest authoring (Tier 1)

- [ ] 1.1 Create `src/manifest.json` with `$schema` set to the GitHub raw URL of `nextcloud-vue/src/schemas/app-manifest.schema.json` (matches `decidesk/src/manifest.json` line 2).
- [ ] 1.2 Set top-level `version` to `"0.1.0"`.
- [ ] 1.3 Set `dependencies` to `[]` per ADR-024 §10. OR has no upstream Conduction-app dependencies.
- [ ] 1.4 Add `pages[]` entries — exactly 30, one per route in `src/router/index.js`. Each entry MUST set `id`, `route`, `type`, and `title`. Custom-type entries MUST also set `component` to the existing view's registered name.
- [ ] 1.5 Mark `Dashboard` as `type:"dashboard"`. Map the existing widget config into `pages[].config.{widgets, layout}`.
- [ ] 1.6 Mark the 8 schema-driven page pairs (Registers/Schemas/Objects/Sources/Endpoints/Search/Applications/Entities) as `type:"index"` and their detail variants as `type:"detail"`. Set `pages[].config.{register, schema}` referencing the underlying OR schemas by slug.
- [ ] 1.7 Mark the remaining 17 routes (Configurations, AuditTrail, SearchTrail, Webhooks, WebhookLogs, Templates, Agents, Chat, Files, Deleted, Organisations, AVG, Reports, ReportView, MyAccount) as `type:"custom"` with a `component` reference. Add a comment in `docs/manifest.md` listing the rationale per route.
- [ ] 1.8 Add `menu[]` with the navigation entries from design.md. Use `section:"main"` for primary nav, `section:"settings"` for the configuration / log / settings group. `label` values MUST be i18n keys (`openregister.<key>`), not raw strings (ADR-024 §6).
- [ ] 1.9 Verify all `pages[].id` values are unique. The library's validator catches this but a manual scan is part of authoring.

## 2. Loader wiring (Tier 1)

- [ ] 2.1 Add `import bundled from './manifest.json'` to `src/main.js`.
- [ ] 2.2 Add `import { useAppManifest } from '@conduction/nextcloud-vue'` and call `useAppManifest('openregister', bundled)` in the bootstrap. The return value can stay unused at Tier 1 — the call exists so Tier 2 can flip on.
- [ ] 2.3 Run the app, inspect devtools network tab, verify the loader silently falls back when `/api/manifest` returns 404 (no console error).
- [ ] 2.4 Confirm no behavioural change at Tier 1 — every existing route still resolves and renders identically. The manifest is loaded but not yet driving dispatch.

## 3. Build-time validation

- [ ] 3.1 Add `"check:manifest": "node node_modules/@conduction/nextcloud-vue/bin/validate-manifest.js src/manifest.json"` to `package.json` `scripts`.
- [ ] 3.2 Wire `check:manifest` into the existing `check` / `check:strict` script chain.
- [ ] 3.3 CI workflow update — confirm the lint job runs `npm run check:manifest`. Fail the job on schema errors.
- [ ] 3.4 Document the contract in `docs/manifest.md`: build fails if the manifest does not validate against the canonical schema; never edit `manifest.json` past validation without re-running the check.

## 4. Tier 2 wiring (CnPageRenderer for schema-driven routes)

- [ ] 4.1 In `src/router/index.js`, replace the direct imports for the 8 schema-driven page pairs with a single `CnPageRenderer` lookup keyed by route name. The renderer reads `pages[].type` from the manifest and dispatches accordingly.
- [ ] 4.2 Verify the schema-driven views (RegistersIndex, RegisterDetail, SchemasIndex, SchemaDetails, SourcesIndex, ObjectsIndex, SearchIndex, EndpointsIndex, ApplicationsIndex, ApplicationDetails, EntitiesIndex, EntityDetail) still receive the same props and behave identically. The renderer dispatches by `type`; the underlying view is unchanged.
- [ ] 4.3 For `type:"custom"` routes, register the existing components in a `customComponents` map and pass it to the renderer's lookup. Confirm every custom route still resolves.
- [ ] 4.4 Bump `manifest.version` to `"0.2.0"` once Tier 2 is wired through.

## 5. Regression tests

- [ ] 5.1 Browser test — navigate to each of the 30 routes in sequence. Each must render without error and match the pre-change screenshot. Use `browser-1` (per project rules) for sequential navigation; capture screenshots into `.playwright-mcp/manifest-tier{1,2}-route-<id>.png`.
- [ ] 5.2 Verify `Dashboard` widgets render through the `type:"dashboard"` path identically to the pre-change DashboardIndex.vue render.
- [ ] 5.3 Verify the schema-driven `index`/`detail` routes show identical column layouts and detail panes after Phase B.
- [ ] 5.4 Verify `type:"custom"` routes (admin / log / settings) still render their bespoke components.
- [ ] 5.5 Verify the `404 → /` catch-all behaviour from the pre-change router still works after Tier 2 (the manifest does not declare the catch-all; it stays a router-level rule).
- [ ] 5.6 Verify `useAppManifest` return value matches the bundled JSON when `/api/manifest` returns 404 (manual devtools inspection).

## 6. Documentation

- [ ] 6.1 Add `docs/manifest.md` documenting:
  - The page-type mapping table from design.md
  - Why 17/30 routes are `type:"custom"` (page-type enum question)
  - The Tier 1 → Tier 2 staging plan
  - The deferred `/api/manifest` backend endpoint rationale
  - The follow-up tasks tracked below in §7
- [ ] 6.2 Update the OR `README.md` to reference `docs/manifest.md` from the architecture section.

## 7. Follow-ups (out of this change)

- [ ] 7.1 **Backend `/api/manifest` endpoint** — drive from an App Builder change (admin reorders menu / hides pages / overrides locale per tenant). Tracked as `openregister-app-manifest-backend` (not yet created).
- [ ] 7.2 **Page-type enum extensions** — open a nextcloud-vue change proposing `type:"logs"`, `type:"settings"`, and possibly `type:"chat"` / `type:"files"` as built-ins. Tracked as `add-app-manifest-page-types` in nextcloud-vue (not yet created).
- [ ] 7.3 **Tier 3 (`CnAppNav`)** — replace the bespoke `NcAppNavigation` mount in `App.vue` with `CnAppNav` reading `manifest.menu`. Tracked as `openregister-adopt-app-manifest-tier-3` (not yet created).
- [ ] 7.4 **Tier 4 (`CnAppRoot`)** — full shell. Blocked on `CnAppRoot` exposing the loading / OR-availability / sidebar slots OR currently implements bespoke. Tracked as `openregister-adopt-app-manifest-tier-4` (not yet created).
- [ ] 7.5 **Reviewer-side drift gate** — Hydra mechanical gate that diffs `src/manifest.json` against `src/router/index.js` route names. Pairs with ADR-029 route-reachability gate. Tracked as `hydra-gate-manifest-route-drift` (not yet created).
- [ ] 7.6 **Use `register-resolver-service` for `pages[].config.{register, schema}`** — once Phase B lands, replace any inline `getValueString(...register/schema...)` lookups in the renderer-adjacent code with the canonical resolver service. Tracked as `openregister-manifest-uses-register-resolver`.

## 8. Sign-off checklist (per ADR-024 §9)

- [ ] 8.1 `src/manifest.json` exists and validates against the canonical schema.
- [ ] 8.2 Tier choice is explicit (Tier 2 on this change; Tier 3 / Tier 4 deferred).
- [ ] 8.3 Regression test suite confirms all 30 routes still resolve and render.
- [ ] 8.4 Reviewer confirms the manifest does not duplicate or contradict the canonical schema (no forked schema, no extra top-level fields).
- [ ] 8.5 `manifest.dependencies` is `[]` (foundation repo per ADR-024 §10).
- [ ] 8.6 `manifest.version` reflects the actual Tier (0.1.0 = Tier 1 only; 0.2.0 = Tier 2 wired through).
- [ ] 8.7 Audit references in proposal.md / design.md cite the right files (`R6-manifest-json.md`, ADR-024).
