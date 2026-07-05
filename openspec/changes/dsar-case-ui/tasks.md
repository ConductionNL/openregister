# Tasks — dsar-case-ui (kind: code, depends_on: dsar-policy-pack-and-seams, dsar-integration-seams)

Last of the ADR-047 Phase-2 chain (policy-pack config → integration seams code → **THIS UI**). This
change EXTENDS the existing `src/views/avg/AvgIndex.vue` (add a `Cases` tab) and the existing `avg`
pinia store (`src/store/modules/avg.js`) into a full data-subject-request case-management surface,
consuming the Phase-1 `/api/gdpr/cases/...` API + the policy pack + the two seams. NO new app/page,
NO new store, NO backend/schema/register/route/seed, NO NL provider bindings (Phase-3 pipelinq).

## 1. Store extension (`avg` module)

<<<<<<< HEAD
- [ ] 1.1 Extend `src/store/modules/avg.js` with a single `CASE_API_BASE` constant and thin case-management actions/getters — `fetchCases` (list, with status/handler/overdue params), `fetchCase`, `transitionCase`, `collectEvidence`, `applyRedaction`, `generateBundle`, `downloadBundle`, `draftDenial`/`finaliseDenial`, `verifyIdentity`, `escalateRegulator` — each a thin `/api/gdpr/cases/...` passthrough mirroring the existing `runInzage`/`runVergetelheid` style (no business logic, `@spec exclude` passthrough docblocks).
- [ ] 1.2 Add a `fetchActivePolicyPack` action + `getActivePolicyPack` getter that loads the active `dsarPolicyPack` (tenant scope, with per-case jurisdiction override) via OR object APIs and caches it in store state, so labels/grounds/tier wording resolve client-side without a per-row round-trip.

## 2. Case list (Cases tab on AvgIndex.vue)

- [ ] 2.1 Add a `Cases` entry to the existing `tabs` computed + `activeTab` switch in `AvgIndex.vue`, reusing the existing `.avgTable`/`.tableContainer`/`.badge` styling and an `NcEmptyContent` empty-state; do NOT alter the existing activities/verantwoording/dsar/compliance tabs.
- [ ] 2.2 Render the case list from `avgStore.fetchCases`, with columns/status/tier wording resolved from the cached active policy pack (no inlined jurisdiction strings), showing per-case status, handler, and deadline/escalation state.
- [ ] 2.3 Add status, handler, and overdue filter controls (any `NcSelect` carries an `inputLabel`), driving the list against the Phase-1 deadline state (`isOverdue`/`escalationTier`) within the RBAC/tenant-scoped set.

## 3. Case detail + deadline display

- [ ] 3.1 Add a per-case detail surface (opened from a list row) showing status, handler, denial fields, evidence, redactions, and audit history, with tier/status wording from the active pack.
- [ ] 3.2 Render the deadline/escalation display from the Phase-1 calculations (`daysRemaining`, `isOverdue`, `escalationTier`) against the effective deadline, conveying tier by text + icon (not colour alone, WCAG AA).

## 4. Lifecycle transition controls

- [ ] 4.1 Add transition controls that offer the declared `x-openregister-lifecycle` transitions for the case's current state (`assign`, `collectEvidence`, `draftDenial`, `finaliseDenial`, `redact`, `bundle`, `retain`) and post them via `avgStore.transitionCase` (`POST /api/gdpr/cases/{id}/transition`), reflecting the returned status — no client-side state machine.
- [ ] 4.2 Gate the `finaliseDenial` control: block/disable it while the case has no `regulatorReference` (reflecting the engine guard), allow `draftDenial` ungated, and surface the server's refusal if finalise is attempted without the reference.

## 5. Detail actions + seam triggers (dialogs, ADR-004)

- [ ] 5.1 Add a denial-composer dialog under `src/dialogs/avg/` (own file, ADR-004 modal isolation) whose ground `NcSelect` (with `inputLabel`) sources options from the pack's denial-grounds enum (label + citation), recording the selected ground key via the denial/transition endpoint; render the letter from the pack's `template:` reference as a leaf (ADR-022) — no inlined body text.
- [ ] 5.2 Add an evidence panel listing the case's `evidence` items (source + per-item status) with a trigger-harvest action (`POST /api/gdpr/cases/{id}/evidence`) that reflects returned per-item status, and a redaction dialog (own file) applying a field-level redaction (before/after + ground) via `POST /api/gdpr/cases/{id}/redactions`.
- [ ] 5.3 Add an export-bundle action (`POST /api/gdpr/cases/{id}/bundle`) that offers exactly one authenticated download of the returned one-time token (`YOUR_TOKEN_HERE` placeholder in any fixture, never a real token), plus identity-verify and regulator-escalate triggers that render the seam's fail-closed result faithfully (`verified`/`failed`/`needs-more`; performed/refused) — never showing a false success.

## 6. Cache-bust + verification

- [ ] 6.1 Bump `appinfo/info.xml` `<version>` to bust the immutable bundled-JS cache for the new tab (no runtime behaviour change).
- [ ] 6.2 Add a Playwright e2e workflow + a visual-regression baseline for the new Cases list + detail views (adr-008); run `openspec validate --change dsar-case-ui --strict` and the relevant Hydra gates (nc-input-labels, modal-isolation, e2e-coverage gate-19, visual-coverage gate-26, spec-coverage gate-16), fixing any pre-existing issue touched.
=======
- [x] 1.1 Extend `src/store/modules/avg.js` with a single `CASE_API_BASE` constant and thin case-management actions/getters — `fetchCases` (list, with status/handler/overdue params), `fetchCase`, `transitionCase`, `collectEvidence`, `applyRedaction`, `generateBundle`, `downloadBundle`, `draftDenial`/`finaliseDenial`, `verifyIdentity`, `escalateRegulator` — each a thin `/api/gdpr/cases/...` passthrough mirroring the existing `runInzage`/`runVergetelheid` style (no business logic, `@spec exclude` passthrough docblocks).
- [x] 1.2 Add a `fetchActivePolicyPack` action + `getActivePolicyPack` getter that loads the active `dsarPolicyPack` (tenant scope, with per-case jurisdiction override) via OR object APIs and caches it in store state, so labels/grounds/tier wording resolve client-side without a per-row round-trip.

## 2. Case list (Cases tab on AvgIndex.vue)

- [x] 2.1 Add a `Cases` entry to the existing `tabs` computed + `activeTab` switch in `AvgIndex.vue`, reusing the existing `.avgTable`/`.tableContainer`/`.badge` styling and an `NcEmptyContent` empty-state; do NOT alter the existing activities/verantwoording/dsar/compliance tabs.
- [x] 2.2 Render the case list from `avgStore.fetchCases`, with columns/status/tier wording resolved from the cached active policy pack (no inlined jurisdiction strings), showing per-case status, handler, and deadline/escalation state.
- [x] 2.3 Add status, handler, and overdue filter controls (any `NcSelect` carries an `inputLabel`), driving the list against the Phase-1 deadline state (`isOverdue`/`escalationTier`) within the RBAC/tenant-scoped set.

## 3. Case detail + deadline display

- [x] 3.1 Add a per-case detail surface (opened from a list row) showing status, handler, denial fields, evidence, redactions, and audit history, with tier/status wording from the active pack.
- [x] 3.2 Render the deadline/escalation display from the Phase-1 calculations (`daysRemaining`, `isOverdue`, `escalationTier`) against the effective deadline, conveying tier by text + icon (not colour alone, WCAG AA).

## 4. Lifecycle transition controls

- [x] 4.1 Add transition controls that offer the declared `x-openregister-lifecycle` transitions for the case's current state (`assign`, `collectEvidence`, `draftDenial`, `finaliseDenial`, `redact`, `bundle`, `retain`) and post them via `avgStore.transitionCase` (`POST /api/gdpr/cases/{id}/transition`), reflecting the returned status — no client-side state machine.
- [x] 4.2 Gate the `finaliseDenial` control: block/disable it while the case has no `regulatorReference` (reflecting the engine guard), allow `draftDenial` ungated, and surface the server's refusal if finalise is attempted without the reference.

## 5. Detail actions + seam triggers (dialogs, ADR-004)

- [x] 5.1 Add a denial-composer dialog under `src/dialogs/avg/` (own file, ADR-004 modal isolation) whose ground `NcSelect` (with `inputLabel`) sources options from the pack's denial-grounds enum (label + citation), recording the selected ground key via the denial/transition endpoint; render the letter from the pack's `template:` reference as a leaf (ADR-022) — no inlined body text.
- [x] 5.2 Add an evidence panel listing the case's `evidence` items (source + per-item status) with a trigger-harvest action (`POST /api/gdpr/cases/{id}/evidence`) that reflects returned per-item status, and a redaction dialog (own file) applying a field-level redaction (before/after + ground) via `POST /api/gdpr/cases/{id}/redactions`.
- [x] 5.3 Add an export-bundle action (`POST /api/gdpr/cases/{id}/bundle`) that offers exactly one authenticated download of the returned one-time token (`YOUR_TOKEN_HERE` placeholder in any fixture, never a real token), plus identity-verify and regulator-escalate triggers that render the seam's fail-closed result faithfully (`verified`/`failed`/`needs-more`; performed/refused) — never showing a false success.

## 6. Cache-bust + verification

- [x] 6.1 Bump `appinfo/info.xml` `<version>` to bust the immutable bundled-JS cache for the new tab (no runtime behaviour change).
- [x] 6.2 Add a Playwright e2e workflow + a visual-regression baseline for the new Cases list + detail views (adr-008); run `openspec validate --change dsar-case-ui --strict` and the relevant Hydra gates (nc-input-labels, modal-isolation, e2e-coverage gate-19, visual-coverage gate-26, spec-coverage gate-16), fixing any pre-existing issue touched.
>>>>>>> origin/development

## Acceptance Criteria

- A `Cases` tab is added to the existing `AvgIndex.vue` (existing tabs unchanged) showing a filterable (status/handler/overdue) case list whose column/status/tier wording resolves from the active policy pack, driven by `/api/gdpr/cases` via the extended `avg` store — no new app, page, or store.
- A per-case detail surface shows status/handler/denial/evidence/redactions/history and the deadline/escalation state from the Phase-1 calculations, tier conveyed by text + icon (not colour alone).
- Lifecycle transition controls reflect the declared state graph and post transitions to the case API; `finaliseDenial` is gated on a `regulatorReference` (draftDenial ungated), with the server guard authoritative.
- The denial composer sources grounds (label + citation) from the pack; the evidence panel lists items with status and triggers a harvest; a field-level redaction is applied; the export bundle offers exactly one one-time download; identity-verify and regulator-escalate render the fail-closed seam result faithfully.
- Letter/notification templates are rendered from the pack's `template:` reference as leaves (ADR-022), not inlined; no jurisdiction-specific label/ground string is hard-coded in the Vue.
- No backend/schema/register/route/seed change and no NL provider bindings are added by this change.

## Quality Checklist

- Frontend/transport only — the ADR-031 table in design.md justifies why every unit is UI/transport (the lifecycle/guards/deadlines/seams stay declarative + Phase-1 engine); no business logic moves into the Vue.
- Extends the existing `AvgIndex.vue` + `avg` pinia module (Options API + singleton store) rather than adding a bespoke store/app (store-pattern rule, ADR-004); component/composable bugs are fixed in `@conduction/nextcloud-vue`, not worked around locally.
- NL Design + WCAG AA: CSS variables only (no hardcoded colours), every `NcSelect` carries an `inputLabel`, status/tier conveyed by text + icon not colour alone, real `<table>`/`<th>` structure (adr-010/adr-004).
- Every modal/dialog lives in its own file under `src/dialogs/avg/`|`src/modals/` (ADR-004 modal-isolation); no inline `<NcModal>`/`<NcDialog>` in `AvgIndex.vue`.
- A Playwright e2e workflow AND a visual-regression baseline cover the new Cases list + detail views (adr-008, gate-19 e2e-coverage + gate-26 visual-coverage); behavioural spec scenarios carry `@e2e`.
- Any fixtures/e2e use safe placeholders only (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`); no realistic BSN, subject id, token, or secret (gitleaks).
