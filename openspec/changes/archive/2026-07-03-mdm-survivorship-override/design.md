## Context

ADR-045 makes OpenRegister own the MDM surface. The survivorship engine (#2),
the OR-owned `trustConfiguration` register, and the master-entity/golden-record
views (#3) plus the merge surface (#B) are already merged. The last missing
steward action is **conflict resolution**: when the linked sources of a master
object disagree on an attribute, there is no OR-native way for the steward to
pick the authoritative source. pipelinq still owns that in
`src/modals/MdmConflictResolutionModal.vue`, whose logic is essentially: group
source values per attribute, keep the ones with >1 distinct value, let the
steward choose a winner, and *optionally* write a persistent trust rule (it POSTs
to pipelinq's own `api/mdm/trust-config`).

Two observations shape this design:

1. The **persistent** outcome is already an OpenRegister capability. A trust rule
   is a `trustConfiguration` row, and OR already stores/edits those through
   generic `/api/objects` CRUD (ADR-022). No new backend is needed for it — only
   a UI that writes the row.
2. pipelinq's modal has a gap the ADR-045 loop needs closed: a **one-off**
   decision that pins *this* object's attribute without changing any trust rule.
   The current resolver (`SurvivorshipResolver::pickWinner()`, ~L264) has no such
   hook. This is the only genuinely new backend primitive #E introduces.

## Goals / Non-Goals

**Goals:**
- Give the steward an OR-native conflict-resolution modal launched from
  `GoldenRecordDetail` (#3), covering both the persistent (trust-rule) and
  one-off (per-object override) outcomes pipelinq's flow needs.
- Add a **minimal** per-object override primitive the resolver honours and the
  listener preserves, so the ADR-045 loop can close and pipelinq can delete its
  modal + `api/mdm/trust-config` endpoint (in a later `mdm-consume-or-surface`).
- Keep the backend delta thin and declarative-friendly.

**Non-Goals:**
- Deleting pipelinq's modal/endpoint — that is a downstream pipelinq change.
- A new register for overrides — overrides materialise onto the master object.
- Bulk / cross-object rule application — one object, one steward action.
- Changing the trust-tier resolution math (#2) or the merge surface (#B).

## Decisions

### D1 — Deliver BOTH outcomes; keep it ONE change (ADR-032)

pipelinq's steward flow needs both the persistent trust rule and the one-off pin,
so #E delivers both. Under ADR-032's mixed-spec anti-pattern rule, a change that
is *both* a backend primitive and a frontend view should split into a chain
UNLESS the backend delta is tiny thin-glue. Here it is:

- **Persistent outcome:** zero backend — reuses existing `/api/objects` CRUD over
  the seeded `trustConfiguration` register.
- **One-off outcome backend:** a short-circuit in `pickWinner()` (override value
  wins), the listener reading + preserving one extra field, and one thin
  controller endpoint that delegates to the resolver + object write path. That is
  thin glue, not a second engine.

The bulk of the work is the modal — one user-facing capability, one steward flow.
Splitting into `mdm-survivorship-override` (backend) + `mdm-conflict-resolution-ui`
(frontend) would fragment a single steward action across two changes and two PRs
for no gain. **Decision: single change.** The proposal reflects this honestly —
the UI is a new `mdm-conflict-resolution-ui` capability, and the resolver change
is a MODIFIED `mdm-survivorship` requirement (the resolver now honours overrides).
The split-into-a-chain option remains the fallback only if review finds the
override primitive has grown beyond thin glue.

### D2 — Per-object override: materialised field on the object, not a register (ADR-031)

The override map is stored as a materialised field on the master object itself —
`overridesField` (default `attributeOverrides`), sitting next to the already-
materialised `goldenRecord` / `attributeProvenance` fields. This mirrors the
existing survivorship materialise-on-save pattern in
`SurvivorshipRecomputeListener::materialise()` exactly and inherits RBAC, audit,
and tenant scoping from the object (ADR-022) for free.

*Declarative vs imperative (ADR-031):* the override is **declarative-ish** — it is
data on the object that the resolver *reads and honours*, not an imperative code
path in a service. The resolver already loops attributes and calls `pickWinner()`;
the override is a data-driven short-circuit ("if an override exists for this
attribute, that value wins"), consistent with ADR-031's preference for
schema/data-driven behaviour over bespoke service logic. The only imperative
surface is the thin endpoint that writes the field + triggers a recompute, which
is unavoidable glue (a steward needs an action to set an override) and is kept as
minimal as the merge endpoints.

*Alternative rejected — a separate `attributeOverride` register:* it would add a
register, a schema, a join on recompute, and cross-object lookup, for a value that
is 1:1 with its object and never queried independently. That is heavier than the
problem. Deferred as a question in case audit provenance later needs overrides to
be independently listable.

### D3 — Provenance marks manual overrides

When an override wins, its provenance entry is marked as a manual override
(actor + optional rationale) instead of a `trustTier`. This keeps
`GoldenRecordDetail`'s provenance table honest — a steward reading the golden
record sees *why* an attribute took a non-trust value. The resolver's provenance
shape (`value`, `sourceSystem`, `trustTier`, `lastUpdated`) gains an
`override: true` + `overriddenBy` / `rationale` variant for overridden attributes.

### D4 — Frontend-gate compliance

- **Modal isolation (ADR-004):** the modal lives at
  `src/modals/mdm/MdmConflictResolutionModal.vue`, imported by
  `GoldenRecordDetail`; never inline. (gate: modal-isolation.)
- **NcSelect inputLabel (ADR-004):** each conflict row's source-select carries an
  `inputLabel`; no bare manual `<label>`. (gate: nc-input-labels.)
- **loadState / no DOM reads:** the modal takes its data as props from the parent
  view (source records already loaded by the store); no `document.getElementById`
  data-attribute reads. Server-injected config, if any, comes via `loadState`.
- **Store pattern (ADR-026):** actions added to the existing `quality.js`
  Options-API module (`createObjectStore` style); no custom store base class,
  mirroring `previewMerge` / `executeMerge`.
- **gate-26 visual coverage:** the new modal is a new view component and MUST ship
  a visual-regression baseline or an e2e workflow test referencing it (or a
  reason-bearing `@visual exclude`). A gate-19 `@e2e` test drives: open modal from
  golden-record detail → resolve one conflict persistent → resolve one one-off →
  assert refreshed golden record.
- **i18n (ADR-025):** English source strings; keys are English.

### D5 — Backend-gate compliance

- **route-auth / route-reachability (ADR-029):** the new
  `SurvivorshipController::override()` carries `#[NoAdminRequired]` +
  `#[NoCSRFRequired]` and a matching `appinfo/routes.php` entry
  (`survivorship#override`, `POST /api/objects/survivorship/{id}/override`).
- **no-admin-idor:** authorisation is enforced by writing through `ObjectService`
  (RBAC/tenant scoped) — an unauthorised caller gets forbidden/not-found, same as
  `MergeController`. No object id is trusted without the write-path check.
- **SPDX (ADR-014):** EUPL-1.2 header in the docblock of every new PHP file.
- **@spec traceability (gate-16):** every changed backend + frontend method
  carries an `@spec openspec/changes/mdm-survivorship-override/...` tag.

## Seed Data

None. Trust rules reuse the already-seeded `trustConfiguration` register
(`lib/Settings/trust_configuration_register.json`, six seed rows). Per-object
overrides are created at runtime by steward action; no seed override rows ship.

## Risks / Trade-offs

- **Override map unbounded growth:** an object accumulating many overrides bloats
  its payload. Mitigated by overrides being 1:1 with attributes (bounded by the
  schema's attribute count) and clearable via the same endpoint.
- **Recompute-scope of a trust rule:** writing a `trustConfiguration` row from the
  modal recomputes only *this* master object (the modal triggers one recompute).
  Other master objects using the same tuple recompute lazily on their next save —
  they are NOT eagerly re-resolved. This is the existing materialise-on-save
  contract; surfaced as a deferred question in case stewards expect an immediate
  fleet-wide re-resolve.
- **Override vs later merge:** an override pinned before a merge could be lost or
  surprising after relink. The merge surface (#B) recomputes the survivor; the
  override lives on the surviving object's field and is preserved by the listener,
  but the interaction is worth an e2e assertion. Flagged, not blocking.
- **Single-change size:** if review judges the override primitive has outgrown
  thin glue, the D1 fallback is to split into a backend + UI chain; the spec
  boundary (two capabilities) already lines up with that split.
