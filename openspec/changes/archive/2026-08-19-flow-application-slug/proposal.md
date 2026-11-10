---
kind: code
---

## Why

ADR-065 made OpenRegister the single canonical home for the fleet's flow
engine: consumers store real `Flow` rows here (`lib/Db/Flow.php`,
`lib/Db/FlowMapper.php`), scoped only by the owning Nextcloud **app**
(`app` = `hermiq`, `openconnector`, …) and queried via
`GET /apps/openregister/api/flows`. Hermiq already seeds 16 live "Hydra *"
flows this way, following the `SeedHydraTriageFlow.php` pattern whose own
docblock cites ADR-065.

A Nextcloud app is not the unit consumers actually want to select by.
OpenBuild's "virtual apps" are multiple independent products living inside
one host app (hermiq), and today there is no structural way to ask the flow
store for "exactly the flows belonging to virtual-app X" — `app=hermiq`
returns all 16 rows, real pipeline flows mixed with unrelated demo/test
flows, because the entity has nothing narrower than the host app to filter
on. OpenBuild's own connectors/automations channels already solved the same
problem for their own schema with an `applicationSlug` field
(`openbuild/lib/Service/AppRepoSerializer.php::collectAutomations()`, which
filters `register=openbuild/schema=automation/applicationSlug=$slug`); the
flow store has no equivalent.

This is change 1 of a 3-repo chain (openregister → hermiq backfill →
openbuild consumption). It adds only the field and the filter — it does not
seed any hermiq flow's `applicationSlug`, and it does not change how
OpenBuild consumes flows. Most of the ~88 live flows on the shared instance
(e.g. openconnector's `ghsync*`/`ckanoffset*`) have no virtual-app
association at all and must remain fully valid with the field empty.

## What Changes

- Add a nullable `applicationSlug` column to `openregister_flows`
  (`lib/Db/Flow.php`, new migration mirroring `Version1Date20260812100000`
  which added `comment` the same way: additive, `notnull => false`,
  `default => null`, no backfill).
- Expose `applicationSlug` as a client-editable field on create/update
  (`FlowService::applyEditableFields()`'s string allowlist) and in
  `Flow::jsonSerialize()`.
- Support `?applicationSlug=` on `GET /apps/openregister/api/flows`
  (`FlowController::index()`), mirroring the existing `?app=` filter
  end-to-end: `FlowMapper::findAllFlows()`/`countFlows()` gain an
  `?string $applicationSlug` parameter applied the same way `app` already
  is (`andWhere(eq(...))` when non-empty, otherwise no predicate — an
  unset filter returns every flow regardless of virtual-app association).
- No behavior change to `FlowEngine`/`FlowStepDispatcher`/execution: the
  field is pure identification/filtering metadata, never read by the
  engine.

## Capabilities

### New Capabilities

(none — this extends the existing flow-storage capability rather than
introducing a new one)

### Modified Capabilities

- `flow-engine`: `openregister/openspec/specs/flow-engine/spec.md` gains a
  requirement that a flow may optionally declare the virtual-app it
  belongs to, and that the flow-listing endpoint may filter by it.

## Impact

- **Code**: `lib/Db/Flow.php`, `lib/Db/FlowMapper.php`,
  `lib/Service/Flow/FlowService.php`, `lib/Controller/FlowController.php`,
  one new `lib/Migration/Version1Date<timestamp>.php`.
- **API**: `GET /apps/openregister/api/flows` gains an optional
  `applicationSlug` query parameter; `POST`/`PUT /apps/openregister/api/flows[/{id}]`
  accept an optional `applicationSlug` body field. Both are additive —
  no existing request or response shape changes.
- **Tests**: extends `tests/Unit/Db/FlowTest.php`,
  `tests/Unit/Controller/FlowControllerTest.php`, and adds a round-trip
  test alongside `tests/Unit/Service/Flow/FlowCommentRoundTripTest.php`
  (same shape: stored, serialised, partial-update-safe, explicit-null
  clears).
- **Downstream (out of scope for this change)**: hermiq backfilling
  `applicationSlug` on its seeded flows, and OpenBuild consuming the new
  filter — tracked as separate changes in their own repos.
