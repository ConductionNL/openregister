---
kind: code
depends_on: []
---

## Why

ADR-045 moved the MDM *surface* into OpenRegister: quality dashboards (#A), the
duplicate/merge surface (#B), and the master-entity + golden-record views (#3),
all driven by the survivorship engine (#2) and the OR-owned `trustConfiguration`
register. The one steward action still missing is **conflict resolution**: when
linked sources disagree on an attribute, the steward has no OR-native way to say
"this source wins". pipelinq still owns that flow in its
`MdmConflictResolutionModal.vue` — the last app-local MDM view blocking the
ADR-045 loop from closing. This change (#E) gives OR the conflict-resolution
capability so pipelinq can delete that modal and its `api/mdm/trust-config`
endpoint.

The steward needs two resolution outcomes, and pipelinq's modal already implies
both: a **persistent** decision (make this source authoritative going forward —
a `trustConfiguration` row, which OR *already* stores via generic object CRUD)
and a **one-off** decision (pin *this* master object's attribute regardless of
trust — which OR cannot yet honour). #E delivers both cleanly.

## What Changes

- **New:** a conflict-resolution modal (`src/modals/mdm/MdmConflictResolutionModal.vue`),
  launched from `GoldenRecordDetail` (base #3). It surfaces attributes whose
  linked sources disagree, lets the steward pick the authoritative source/value
  per attribute, and for each choice either (a) writes a `trustConfiguration`
  row via the existing generic `/api/objects` CRUD (persistent rule), or (b)
  sets a one-off per-object override — then recomputes and refreshes.
- **New (minimal backend primitive):** a per-object **attribute override** that
  `SurvivorshipResolver` honours — an override value for an attribute
  short-circuits `pickWinner()` and always wins, with a provenance entry marked
  as a manual override. Stored as a materialised `overridesField` map on the
  object (default `attributeOverrides`), the same materialise-on-save pattern as
  `goldenRecord` / `attributeProvenance`. The `SurvivorshipRecomputeListener`
  **preserves** the override map across recomputes.
- **New endpoint:** `POST /api/objects/survivorship/{id}/override` — sets/clears
  one attribute override on a master object and triggers a recompute. Thin
  controller delegating to the resolver + object write path (RBAC/tenant scoped
  through `ObjectService`, same posture as `MergeController`).
- **New store actions** in `src/store/modules/quality.js`: `setAttributeOverride`
  and `persistTrustRule` (thin `generateUrl` + axios wrappers).
- **i18n:** English source strings for the modal (ADR-025).
- **Coverage:** jest for the store actions, PHPUnit for the resolver override
  path + controller, gate-26 visual/e2e for the new modal.

No breaking changes. The override map is additive and absent-by-default; schemas
with no `x-openregister-survivorship` annotation are unaffected.

## Capabilities

### New Capabilities
- `mdm-conflict-resolution-ui`: the steward-facing conflict-resolution modal +
  its store actions — surfaces disagreeing sources per attribute, captures the
  authoritative choice, and dispatches either a persistent trust rule (existing
  CRUD) or a one-off per-object override (new endpoint), then recomputes.

### Modified Capabilities
- `mdm-survivorship`: the survivorship resolver + recompute listener now honour
  a per-object attribute-override map — an override wins over the tier-based
  `pickWinner()`, the winning provenance entry is marked a manual override, and
  the listener preserves the override map across recomputes. A new
  `/api/objects/survivorship/{id}/override` endpoint sets/clears an override.

## Impact

- **Backend:** `lib/Service/Survivorship/SurvivorshipResolver.php` (honour
  override map), `lib/Listener/SurvivorshipRecomputeListener.php` (preserve
  override map, thread it into the resolver), a new
  `lib/Controller/SurvivorshipController.php`, `appinfo/routes.php`.
- **Frontend:** `src/modals/mdm/MdmConflictResolutionModal.vue` (new),
  `src/views/quality/GoldenRecordDetail.vue` (launch button),
  `src/store/modules/quality.js` (two actions).
- **Config/storage:** none new — trust rules reuse the seeded `trustConfiguration`
  register; per-object overrides materialise onto the master object itself.
- **Downstream:** pipelinq drops `src/modals/MdmConflictResolutionModal.vue` and
  its `api/mdm/trust-config` controller in a follow-on `mdm-consume-or-surface`
  change (ADR-045 anti-pattern retired). Not in scope here.
