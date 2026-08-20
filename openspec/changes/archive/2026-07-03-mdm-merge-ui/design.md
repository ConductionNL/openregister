# Design — mdm-merge-ui

## Context

This is ADR-045 follow-on **#C**: the steward **merge action UI** in
OpenRegister. The backend (#B) and the read-only steward views (#3) are already
merged on the base branch, so this change is **frontend-only** and consumes
existing endpoints. It generalises pipelinq's `MdmMergeWizardModal.vue` into OR
so the merge/reverse actions live where ADR-045 places them; pipelinq deletes its
copy in #D.

### Endpoints consumed (all from #B, already routed)

| Action | Route | Body / params | Returns |
|---|---|---|---|
| Preview | `POST /api/objects/merge/preview` | `{ from, into }` | `{ from, into, postMergeGoldenRecord, attributeProvenance, reversalDeadline }` |
| Execute | `POST /api/objects/merge/execute` | `{ from, into, reason }` | persisted `mergeOperation` row |
| Reverse | `POST /api/objects/merge/{id}/reverse` | path `id` | updated `mergeOperation` (`reversible:false`) |
| List merge ops | `GET /api/objects/merge-operation/mergeOperation` | paging params | envelope of `mergeOperation` rows |

`mergedBy` / `reversedBy` are attributed server-side from the session — the
client never sends them. RBAC/tenant scoping is enforced inside `MergeService`
(a caller who cannot read/write the objects gets 403/404), so the UI does no
authorization logic; it only surfaces the errors the endpoints return.

Note: OR's preview payload has **no `downstreamImpact`** field (pipelinq's
modal rendered one from its app-local sync queue). Per ADR-045, downstream
propagation is OR webhooks, not a parallel queue — so the generalised wizard
drops the downstream-impact panel and shows golden record + provenance +
reversal deadline only.

## Goals

- Launch a reversible merge from `DuplicatesIndex` for a candidate pair.
- Preview → reason → confirm → execute → refresh, all server-authoritative.
- A Merge Operations list with an in-window reverse action.
- Four thin store actions on `qualityStore`; no client-side merge logic.
- Pass all frontend gates; visual + e2e coverage for the new surface.

## Non-Goals

- **No backend changes.** No new controllers, services, routes, or events.
- **No manual per-attribute survivorship override** (conflict resolution) — see
  Decision D1 and DEFERRED_QUESTIONS. OR has no stored override primitive today.
- No changes to #3's read-only Quality / Master-entity views beyond wiring the
  merge launch into `DuplicatesIndex`.

## Decisions

### D1 — Conflict-resolution is deferred, not shimmed

pipelinq's `MdmConflictResolutionModal.vue` POSTs each per-attribute override to
`/api/mdm/trust-config` — a backend primitive that **OpenRegister does not have**.
OR's `SurvivorshipResolver` (#2) auto-resolves the golden record from declared
trust tiers; there is no stored per-object steward override that the resolver
honours. Building the modal without that primitive would either silently discard
the steward's choice or require inventing backend behaviour under a "UI-only"
change — both bad. So conflict-resolution is **out of scope** and named as a
backend-first follow-on `mdm-survivorship-override` (store overrides that
`SurvivorshipResolver` honours + its UI). #C stays the merge UI, which #B fully
supports. Recorded as a DEFERRED_QUESTION.

### D2 — Reverse action lives in a new Merge Operations view

The reverse action needs a home. Options: (a) reuse the master-entity detail
panel, (b) a new Merge Operations list. Chosen **(b)** — a merge operation is an
audit row (`merge-operation`/`mergeOperation`), not an attribute of one master
entity; a merged-away object leaves the master-entity list, so its detail panel
is the wrong place to reverse it. A dedicated list also gives stewards an audit
trail of recent merges with reversibility state at a glance. It reads through
OR's generic object read surface — no new endpoint. Recorded as a
DEFERRED_QUESTION (fork acknowledged).

### D3 — Wizard launched only from DuplicatesIndex (this change)

The wizard is launched from `DuplicatesIndex` (candidate pairs) only. Manual
merge of two arbitrarily-selected master entities from `MasterEntitiesIndex` is a
plausible extension but adds a selection UX (pick two rows, decide survivor) that
is orthogonal to the candidate-pair flow. Kept out of #C to hold scope; the
wizard component is written pair-agnostic (`{ from, into }`) so a later
`MasterEntitiesIndex` launch reuses it unchanged. Recorded as a DEFERRED_QUESTION.

## Frontend gate compliance

- **modal-isolation** — the merge wizard is `src/modals/mdm/MdmMergeWizardModal.vue`,
  a self-contained `NcDialog`-based component; `DuplicatesIndex.vue` imports and
  renders it. No inline `NcModal`/`NcDialog` markup in any parent.
- **nc-input-labels** — the merge-reason `NcSelect` (and any other `NcSelect`)
  carries an `inputLabel` prop; no bare `<label>` + `NcSelect` pairing.
- **initial-state** — no server data is read from the DOM. The register/schema
  selection comes from `qualityStore` (already populated by #3); merge data comes
  from the endpoints via `@nextcloud/axios`. No `document.getElementById(...).dataset`.
- **visual-coverage (gate-26)** — `MergeOperationsIndex.vue` is a NEW `src/views/`
  page; it gets a visual-regression baseline under `tests/e2e/visual/` and an e2e
  workflow test. The wizard modal is covered by the same e2e flow (open from a
  pair, preview, confirm). Any surface that genuinely cannot be baselined carries
  a reason-bearing `@visual exclude` / `@e2e exclude`.
- **dashboard-antipattern** — N/A; no dashboard pages added. Both new surfaces are
  plain views/modals, not `type:"dashboard"` pages, and neither renders
  `<CnDashboardPage>`.
- **i18n** — all user strings are English source via `t('openregister', ...)`;
  no Dutch as an i18n key.

## Store shape (`src/store/modules/quality.js`)

Add to the existing `qualityStore` (pinia `defineStore` + `@nextcloud/axios` +
`generateUrl`, `API_BASE = /apps/openregister/api`), matching the module's
existing action style (try/catch → `this.error`):

- `previewMerge(from, into)` → `POST ${API_BASE}/objects/merge/preview`
- `executeMerge(from, into, reason)` → `POST ${API_BASE}/objects/merge/execute`
- `fetchMergeOperations(params)` → `GET ${API_BASE}/objects/merge-operation/mergeOperation`
- `reverseMerge(id)` → `POST ${API_BASE}/objects/merge/${id}/reverse`

State additions: `mergeOperations`, `mergeOperationsTotal` (+ paging fields
mirroring the existing duplicates/masterEntities envelope handling). No custom
store base — reuse the module's Options-API pattern.

## Components

- `src/modals/mdm/MdmMergeWizardModal.vue` — props `{ from, into }`; on
  `mounted`/open calls `previewMerge`, renders golden record + provenance +
  reversal deadline + reason `NcSelect` (with `inputLabel`), emits `close` /
  `merged`. Confirm disabled until preview loaded and reason chosen.
- `src/views/quality/MergeOperationsIndex.vue` — uses `RegisterSchemaSelector`
  where relevant, lists merge operations from `fetchMergeOperations`, shows
  "Reverse" only when the row is reversible (endpoint returns `reversible` +
  `reversalDeadline`), calls `reverseMerge`, refreshes on success.
- Modified `src/views/quality/DuplicatesIndex.vue` — a per-row "Merge" action
  that opens `MdmMergeWizardModal` for the pair (`objectB` → `from`,
  `objectA` → `into`, or a survivor toggle if trivial), and reloads on `merged`.

## Registration

- `src/registry.js` — import + register `MergeOperationsIndex` as a `page(...)`.
- `src/manifest.json` — add a `MergeOperations` page (route `/mergeOperations`,
  component `MergeOperationsIndex`) and a nav item under the existing
  `DataQualityGroup`. The wizard modal is not a page — it is imported by a view,
  so it needs no manifest entry.

## Seed Data

None. This is a frontend change. Verify against the pipelinq register (id 16) /
masterEntity schema (id 1207) — or any survivorship-enabled schema declaring
`x-openregister-survivorship` and the merge annotation — which already carries
candidate pairs and merge-config so `preview`/`execute`/`reverse` return real
payloads. No fixtures are added by this change.

## Risks

- **Preview shape drift.** If #B's preview payload changes, the wizard's golden
  record / provenance rendering breaks. Mitigated by reading only the documented
  fields (`postMergeGoldenRecord`, `attributeProvenance`, `reversalDeadline`) and
  degrading gracefully on missing keys.
- **Reversibility gating client-side.** The UI hides "Reverse" past the window,
  but the endpoint is the authority (returns 404/error if the window closed) — the
  UI never assumes success; it reflects the server response.
