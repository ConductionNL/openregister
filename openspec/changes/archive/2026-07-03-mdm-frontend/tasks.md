# Tasks: mdm-frontend

## 1. Store + manifest + navigation

- [x] 1.1 Add `src/store/modules/quality.js` (`useQualityStore`, Options-API `defineStore` + `@nextcloud/axios` + `generateUrl`, mirroring `reports.js`): hold selected register/schema; read actions `fetchQualityStats`, `fetchLowQualityObjects`, `fetchDuplicates`, `fetchMasterEntities`, `fetchGoldenRecord`, `fetchWebhookHealth`; register it in `src/store/store.js`.
- [x] 1.2 Add four `type:"custom"` entries to `src/manifest.json` `pages[]` (Data Quality, Duplicate Candidates, Master entities, Queue/sync-health) and one "Data quality" group with four `children[]` in `menu[]`.
- [x] 1.3 Import + register the group's `vue-material-design-icons` nav icons in `src/main.js`.

## 2. Shared selector + Data Quality dashboard

- [x] 2.1 Add `src/views/quality/RegisterSchemaSelector.vue` — two `NcSelect`s (each with `inputLabel`), schema disabled until register chosen, emitting/committing the `(register, schema)` pair to the store.
- [x] 2.2 Add `src/views/quality/QualityIndex.vue` — KPI cards (average + good/fair/poor), 10-bucket histogram via the nc-vue chart primitive with a bucket-table fallback, from `quality#stats`.
- [x] 2.3 Add the lowest-quality objects table (id / qualityScore / qualityStatus) with `limit`/`offset` paging from `quality#index`, plus an empty state on `total === 0`.

## 3. Duplicate Candidates + Master entities

- [x] 3.1 Add `src/views/quality/DuplicatesIndex.vue` — read-only paginated table (objectA / objectB / score / matchedOn) from `duplicate#index`; no merge/write control; empty state.
- [x] 3.2 Add `src/views/quality/MasterEntitiesIndex.vue` — object list (via `useObjectStore`) with qualityScore/qualityStatus columns, quality sort/filter.
- [x] 3.3 Add `src/views/quality/GoldenRecordDetail.vue` — non-modal panel rendering the object's `attributeProvenance` map defensively, with a "no provenance" fallback.

## 4. Queue / sync-health + i18n

- [x] 4.1 Add `src/views/quality/QueueHealthIndex.vue` — list webhooks (`webhooks#index`), per-webhook delivered/failed/pendingRetries (`webhooks#logStats`), recent failures (`webhooks#allLogs?success=false`), empty state; read-only.
- [x] 4.2 Wrap every new user-facing string (labels, headings, table headers, empty states) in the translation function with English source strings; add keys to `src/l10n` source. (Ran `node tests/l10n/check-l10n.js --write`, adding 53 new English-source keys to `l10n/en.json`; `check-l10n.js` reports "OK — every used translation key is present".)

## 5. Coverage + verification

- [x] 5.1 Add a `@visual`-tagged visual-regression spec for each of the four views + the golden-record detail (no `@visual exclude`). (`tests/e2e/visual/mdm-frontend.visual.spec.ts`; hydra gate-26 visual-coverage: PASS.)
- [x] 5.2 Add Playwright e2e specs driving each view through the UI (select register `16` / schema `1207` or discover the first scored schema; assert KPIs, duplicate rows, provenance, webhook counts); reference every ADDED spec Scenario. (`tests/e2e/spec-coverage/mdm-frontend.spec.ts`; hydra gate-19 e2e-coverage: PASS.) NOTE: not executed against a live Nextcloud instance in this pass (no browser/live env in this worktree) — the parent should browser-verify against a running dev container.
- [x] 5.3 Run `openspec validate mdm-frontend --strict` and the hydra frontend gates (modal-isolation, nc-input-labels, initial-state, admin-router, dashboard-antipattern, visual-coverage, e2e-coverage) and fix any failure. (All 28 hydra gates green; `openspec validate mdm-frontend --strict` → "Change 'mdm-frontend' is valid".)

## Acceptance criteria

- All four MDM views mount without console errors and appear under the "Data quality" nav group; no existing page/menu/route removed or renamed.
- Data Quality view shows average + good/fair/poor KPIs, the 10-bucket histogram, and a paginated lowest-quality table; empty state on an unscored schema.
- Duplicate Candidates view is read-only with objectA/objectB/score/matchedOn and pagination; no merge/write control present.
- Master-entity list shows qualityScore/qualityStatus; the golden-record detail panel (non-modal) renders `attributeProvenance`, degrading gracefully when absent.
- Queue/sync-health shows per-webhook delivered/failed/pendingRetries + recent failures from existing webhook APIs; empty state when none; no new backend endpoint added.
- Register/schema selection persists across the four views; no data request before both are selected.

## Quality checklist

- Every `NcSelect` carries an `inputLabel`; no manual `<label>` paired with a select.
- No inline `<NcModal>`/`<NcDialog>` in a view — any dialog lives in `src/modals/`; the golden-record detail is a panel.
- No server data read from DOM data-attributes; bootstrap values via `loadState` only.
- No `CnDashboardPage`-in-`CnDashboardPage` nesting; the quality view is a bespoke custom page, not a nested dashboard.
- Store follows the `reports.js` read-only pattern (Options API, no custom base class); all actions are GET-only.
- i18n keys use English source strings; every new string is translatable.
- Each new view has a `@visual` visual-regression spec and Playwright e2e coverage; e2e drives the UI, not direct API calls.
- No new schemas/registers/seed data; any placeholder UUID is the nil UUID `00000000-0000-0000-0000-000000000000`.
- `openspec validate mdm-frontend --strict` passes.
