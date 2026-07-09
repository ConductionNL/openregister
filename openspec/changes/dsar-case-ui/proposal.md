---
kind: code
depends_on: [dsar-policy-pack-and-seams, dsar-integration-seams]
---

## Why

Phase-1 gave OpenRegister a stateful data-subject-request **case** (the `dataSubjectRequest`
entity, its N-state lifecycle, deadline tracking, evidence/redaction sub-collections) and a
`/api/gdpr/cases/...` case-management API (`dsar-case-subsystem` + `dsar-case-engine`). Phase-2's
head `dsar-policy-pack-and-seams` made every jurisdiction value (status/ground labels, escalation
thresholds, retention windows, letter templates) **policy-pack data**, and `dsar-integration-seams`
added the identity-verify and regulator-escalate seams the case engine calls. But OpenRegister's
existing AVG surface (`src/views/avg/AvgIndex.vue`) still exposes only the *stateless* DSAR
primitives — a single-shot inzage/vergetelheid/portabiliteit form under the `DSAR` tab. There is **no
UI to open, list, work, or close a tracked case**: no case list, no per-case detail, no lifecycle
transition controls, no deadline/escalation display, no evidence/bundle/denial actions, and no way
to trigger the two seams. The full case-management workflow that Phase-1 + the policy pack made
possible is invisible to a handler.

This change extends the existing `AvgIndex.vue` surface and the `avg` store module to a **full
data-subject-request case-management UI**, driven by the active policy pack and consuming the
Phase-1 case API and the two seams. It is the **last OR-side Phase-2 change** (policy-pack config →
integration seams code → **this UI**). Phase-3 (`pipelinq`) then binds the NL identity/regulator
providers, supplies the NL policy pack values, and deep-links into this OR case surface; no NL
provider bindings are in scope here — this is the generic OR case UI.

## What Changes

- **Add a case LIST** (a new `Cases` tab on the existing `AvgIndex.vue`, not a new app): case rows
  with columns whose headings come from the active **policy-pack labels**, filterable by status,
  handler, and overdue, and driven by the Phase-1 case list/deadline aggregations.
- **Add a case DETAIL surface**: open one case to see its status, handler, deadline
  (due/overdue/escalation-tier from the deadline-tracking calculations), denial fields, evidence,
  redactions, and audit history — with the lifecycle transition controls.
- **Add lifecycle TRANSITION controls**: buttons/actions that reflect the case-subsystem state graph
  (`assign`, `collectEvidence`, `draftDenial`, `finaliseDenial`, `redact`, `bundle`, `retain`, …),
  invoking `POST /api/gdpr/cases/{id}/transition`; the `finaliseDenial` action is gated per the
  engine's mandatory-`regulatorReference` guard (blocked until the reference is present).
- **Add evidence, redaction, and export-bundle actions**: an evidence panel (list items with
  per-item collection status; trigger a harvest), a field-level redaction action, and a
  generate-bundle action that mints the one-time secure download and offers a single download.
- **Add denial compose, identity-verify trigger, and regulator-escalate action**: a denial composer
  whose ground options come from the **policy-pack denial-grounds enum** (label + citation from the
  pack), an identity-verify trigger (via the identity seam at the `verifying` state), and a
  regulator-escalate action (via the regulator seam), each reflecting the seam's fail-closed result.
- **Resolve ALL labels/grounds/wording from the ACTIVE policy pack** for the tenant, with an optional
  **per-case jurisdiction override** (a confirmed decision): the UI reads status/ground/tier labels,
  deadline wording, and template references from the pack, never inlining jurisdiction strings.
- **Render template content from `template:` references** (leaves, ADR-022): the UI **references** a
  letter/notification template by its pack-supplied reference and renders it from the leaf; it does
  not inline template body text.
- **Extend the `avg` store** with case-management actions/getters (list, detail, transition,
  evidence, redaction, bundle, denial, verify, escalate) that thinly wrap the `/api/gdpr/cases/...`
  API — no new custom store, following the existing `avg.js` pinia pattern.

**Explicitly out of scope — Phase-3 `pipelinq` (successor, not specced here):** the NL identity
(BSN/BRP/RvIG) and NL regulator (AP-complaint) provider bindings, the NL policy-pack values, and any
NL-specific deep-link wiring into this surface. This change ships only the generic OR case UI driven
by whatever pack + seams are active.

## Capabilities

### New Capabilities
- `dsar-case-list`: the case LIST surface on `AvgIndex.vue` — a filterable (status/handler/overdue),
  policy-pack-labelled table of tracked data-subject-request cases with deadline/escalation state,
  driven by the Phase-1 case list + deadline aggregations, plus the `avg`-store list plumbing.
- `dsar-case-detail-actions`: the per-case DETAIL surface and its action set — lifecycle transition
  controls (guarded `finaliseDenial`), deadline/escalation display, the evidence panel + redaction +
  export-bundle (one-time download) actions, the denial composer (pack-grounds), and the
  identity-verify + regulator-escalate seam triggers — all resolving labels/grounds/wording from the
  active policy pack (tenant scope + optional per-case jurisdiction override) and referencing
  templates as leaves, over NL Design + WCAG AA.

### Modified Capabilities
<!-- None as delta specs. The surface this extends is the app's existing AVG view, which has no
     capability spec in openspec/specs/ (avg-verwerkingsregister lives in the app, not as a base
     spec here), and every Phase-1/Phase-2 capability this consumes (dsar-case-lifecycle,
     dsar-deadline-tracking, dsar-case-api, dsar-policy-pack, the two seams) lives in sibling
     UNARCHIVED changes — a MODIFIED delta would reference a non-existent base spec. The new UI
     behaviour is therefore expressed as ADDED requirements on the two new capabilities above; no
     existing OR requirement is altered. See DEFERRED_QUESTIONS. -->
- _none_

## Impact

- **Frontend (this change)**: extend `src/views/avg/AvgIndex.vue` (add a `Cases` tab: list + detail +
  action controls) and the `avg` store module `src/store/modules/avg.js` (case-management
  actions/getters wrapping `/api/gdpr/cases/...`); new modal(s) under `src/modals/`|`src/dialogs/` for
  the denial composer / redaction / transition confirmations (ADR-004 modal-isolation); nc-vue
  components only (`NcAppContent`, `NcButton`, `NcActions`, `NcEmptyContent`, `NcTextField`,
  `NcSelect` with `inputLabel`, `NcLoadingIcon`), reusing the existing view/table styling.
- **Consumes (unchanged)**: the Phase-1 `/api/gdpr/cases/...` endpoints (create/list/transition/
  evidence/redactions/bundle/download/deny) and the deadline calculations/aggregations; the active
  `dsarPolicyPack` object (via OR object APIs) for labels/grounds/windows/templates; the two seams
  (identity-verify, regulator-escalate) via the engine's transition/escalation call-outs.
- **APIs**: no new routes — the UI calls the existing `/api/gdpr/cases/...` API and reads the policy
  pack through OR's object APIs (RBAC + multitenancy). No backend/schema change.
- **No new schema / register / migration / seed** — this change adds no OR object type; it renders
  the existing case register and the existing policy-pack register.
- **Downstream (successor context, not specced here)**: Phase-3 pipelinq binds NL providers + the NL
  policy pack and deep-links into this surface; no app is migrated by this change.
