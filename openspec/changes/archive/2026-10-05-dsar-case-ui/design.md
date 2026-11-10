## Context

OpenRegister already ships an AVG surface: `src/views/avg/AvgIndex.vue` (839 lines) — a manifest-driven
page component registered in `src/registry.js` (`AvgIndex: page(AvgIndex)`) and mounted at `/avg` via
`src/manifest.json` (`{ "id": "avg", "route": "/avg", "component": "AvgIndex" }`, nav entry
`AVG / Verwerkingsregister`). It renders four tabs (`activities`, `verantwoording`, `dsar`,
`compliance`) driven by a local `activeTab` string and a `tabs` computed. Its data comes from the
`avg` pinia store module (`src/store/modules/avg.js`), registered as a singleton in
`src/store/store.js` (`avgStore = useAvgStore(pinia)`) and consumed with the Options-API
`avgStore.getX` getters — the codebase's canonical store pattern (`createObjectStore`-style, no
bespoke store). The existing `DSAR` tab exposes only the **stateless** primitives — a one-shot
inzage/vergetelheid/portabiliteit form calling `/api/avg/{inzage,vergetelheid,portabiliteit}`.

Phase-1 (`dsar-case-subsystem` + `dsar-case-engine`) added the stateful **case**: the
`dataSubjectRequest` entity, an N-state `x-openregister-lifecycle` (`received` → `assign` →
`collectEvidence` → `draftDenial` → `finaliseDenial` → `redact` → `bundle` → `retain` →
`fulfilled`/`refused`/`closed`), deadline calculations (`daysRemaining`, `isOverdue`,
`escalationTier`) + case-count aggregations, and a `/api/gdpr/cases/...` REST API
(`POST /cases`, `POST /cases/{id}/transition`, `POST /cases/{id}/evidence`,
`POST /cases/{id}/redactions`, `POST /cases/{id}/bundle`, `GET /cases/{id}/bundle/download?token=…`).
Phase-2's head `dsar-policy-pack-and-seams` made status/ground labels, escalation thresholds,
retention windows, and template references **policy-pack data**; `dsar-integration-seams` added the
identity-verify and regulator-escalate seams the engine calls. **Nothing in the UI surfaces any of
this.** This change is the last OR-side Phase-2 change: it extends `AvgIndex.vue` + `avg.js` into a
full case-management surface. Phase-3 pipelinq binds NL providers + values and deep-links here.

## Goals / Non-Goals

**Goals:**
- Add a `Cases` tab to the existing `AvgIndex.vue` — a filterable, policy-pack-labelled case LIST and
  a per-case DETAIL surface with lifecycle transition controls, deadline/escalation display, evidence
  panel, redaction, export-bundle (one-time download), denial composer, and the two seam triggers.
- Extend the existing `avg` store with case-management actions/getters that thinly wrap the Phase-1
  `/api/gdpr/cases/...` API and read the active policy pack via OR object APIs — no new store, no new
  backend, no new schema/route.
- Resolve every label/ground/wording from the active policy pack (tenant scope + optional per-case
  jurisdiction override, a confirmed decision); reference letter/notification templates as leaves
  (ADR-022), never inline body text.
- NL Design (CSS variables, standard nc-vue components) + WCAG AA (adr-010/004), with a
  visual-regression + e2e proof for the new views (adr-008).

**Non-Goals:**
- No NL provider bindings, NL policy-pack values, or NL deep-link wiring — Phase-3 pipelinq.
- No change to the Phase-1 case API, the policy-pack/seam contracts, or any OR schema/register/route.
- No rewrite of the existing `activities`/`verantwoording`/`dsar`/`compliance` tabs — the stateless
  DSAR form stays; the case workflow is additive.

## Decisions

### Declarative-vs-imperative (ADR-031)

This change is **frontend / transport**, not declarative-schema behaviour — the ADR-031 table below
records why every unit of behaviour is UI/transport code rather than register config. The stateful
behaviour (lifecycle, guards, deadlines, seams) already lives declaratively in the register + as the
Phase-1 engine code; this change only *renders and drives* it.

| Behaviour | Chosen path | Rationale |
|---|---|---|
| **Case list + detail rendering** | **Frontend — Vue view on `AvgIndex.vue`** | Presentation of the existing case register; a schema cannot express a filterable table + detail. Reuses the app's existing view/table markup + styling. |
| **Filter/label resolution from the policy pack** | **Frontend read of pack config** | The pack is declarative config (head change owns it); the UI *reads* status/ground/tier labels + template refs from the active pack. No new config is authored here. |
| **Lifecycle transition controls** | **Frontend — calls `POST /api/gdpr/cases/{id}/transition`** | The state graph + guards are declarative/engine (Phase-1). The UI only offers the declared transitions and posts them; `finaliseDenial` gating is *enforced* server-side, *reflected* client-side. |
| **Evidence / redaction / bundle / denial / verify / escalate actions** | **Frontend — thin `avg`-store actions over the Phase-1 API + seams** | Transport only — each is an axios call to an existing endpoint, mirroring `avg.js`'s existing `runInzage`/`runVergetelheid` passthroughs. No business logic in the UI. |
| **One-time bundle download** | **Frontend — request bundle → receive token → single authenticated download** | The token mint + burn is server-side (engine); the UI just triggers generation and offers one download, like the existing `downloadPortabiliteit` blob pattern. |
| **Store shape** | **Extend existing `avg` pinia module** | The codebase's canonical pattern (Options API + singleton store in `store.js`); a bespoke case store would violate the store-pattern rule. |

### Extend `AvgIndex.vue`, do not add a new app/view

A new `Cases` tab is added to the existing `tabs` computed and the `activeTab` switch — mirroring the
existing four tabs exactly. The list uses the existing `.avgTable`/`.tableContainer`/`.badge` styling
and `NcEmptyContent` empty-states already present; detail + action controls reuse `NcButton`,
`NcActions`/`NcActionButton`, `NcTextField`, and `NcSelect`. This keeps one AVG entry point and one
store. Alternative considered — a separate `CasesIndex.vue` page + nav entry — rejected: it
fragments the AVG surface, duplicates store wiring, and contradicts the "extend, don't rewrite"
scope.

### Modals live in their own files (ADR-004)

The denial composer, the redaction form, and the transition/escalate confirmations are non-trivial
forms; per ADR-004 modal-isolation each lives in its own `.vue` under `src/dialogs/avg/` (mirroring
the existing `src/dialogs/avg/EditActivityDialog.vue`) or `src/modals/`, imported by `AvgIndex.vue` —
never inline `<NcModal>`/`<NcDialog>` markup. Alternative — inline modals — rejected (gate:
modal-isolation).

### Policy-pack-driven labels + per-case jurisdiction override (CONFIRMED)

The UI resolves status/ground/tier labels, deadline wording, and template references from the active
`dsarPolicyPack` for the tenant. A **per-case jurisdiction override** is supported (confirmed
decision): when a case carries a jurisdiction, its pack overrides the tenant default for that case's
labels/grounds/wording. The denial composer's ground `<NcSelect>` options are the pack's
denial-grounds enum (key → label + citation). No jurisdiction string is inlined in the Vue. Template
content is rendered from the pack's `template:` reference as a **leaf** (ADR-022) — the UI references
it, it does not embed body text.

### Accessibility (adr-010 / adr-004, WCAG AA)

All colours via CSS variables (already the `AvgIndex.vue` convention); every `NcSelect` carries an
`inputLabel` (gate: nc-input-labels); status/escalation badges convey state by text + icon, not colour
alone; the case table is a real `<table>` with `<th>` headers (as the existing tables are). A
visual-regression baseline + e2e workflow cover the new views (adr-008).

## Risks / Trade-offs

- **[The Phase-1 `/api/gdpr/cases/...` route shape is "provisional" in the engine design]** → The
  `avg`-store actions target that exact shape; if the engine finalises different paths, only the
  store's URL constants change. Mitigation: centralise the case-API base in one `CASE_API_BASE`
  constant in `avg.js` (mirroring the existing `API_BASE`), and keep actions thin so a path change is
  a one-line edit. Recorded as an Open Question.
- **[Policy-pack resolution adds a second data fetch]** → The UI must load the active pack alongside
  the case list. Mitigation: fetch the pack once on tab-open and cache it in store state (like
  `complianceReport`); resolve labels client-side from the cached pack — no per-row round-trip.
- **[`finaliseDenial` is guarded server-side]** → A user could press finalise before recording a
  regulator reference. Mitigation: the UI disables/blocks the finalise action until the case carries
  a `regulatorReference` (reflecting the engine guard) and surfaces the server's refusal if bypassed —
  the guard remains authoritative server-side.
- **[Seam actions can fail closed]** → identity-verify/regulator-escalate may return
  unverified/refused (the seams' fail-closed default). Mitigation: the UI renders the seam result
  faithfully (unverified / escalation-not-performed) rather than optimistically showing success.
- **[Manifest-driven cache-bust]** → `AvgIndex.vue` is bundled; a new tab needs the standard
  info.xml `<version>` bump to bust the immutable JS cache. Mitigation: note it in tasks; no runtime
  behaviour change.

## Migration Plan

Additive, no migration: the change adds a tab + store actions + dialog(s). No schema/register/route
change, no seed data (no new OR object type — the case register and policy-pack register already
exist from Phase-1/Phase-2). Rollback = revert the frontend commit; the Phase-1 API and pack are
untouched. Any placeholders used in fixtures/e2e (subject ids, tokens) MUST be safe placeholders
(`00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`) — no realistic BSN or secret.

## Open Questions

- **Final `/api/gdpr/cases/...` path shape** — the engine design marks the routes "provisional".
  Provisional decision: target the documented shapes and isolate them in one store constant so a
  finalisation is a one-line change. To confirm against the engine change at apply time.
- **Per-case jurisdiction override source** — confirmed that an override exists; the exact case field
  carrying the jurisdiction (and how the pack is selected from it) is resolved against the
  case-entity + policy-pack specs at apply time. Provisional: read a `jurisdiction` field on the case
  and select the matching pack, falling back to the tenant default pack.
