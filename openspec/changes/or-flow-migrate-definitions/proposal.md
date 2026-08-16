---
kind: code
depends_on:
  - or-flow-action-nodes
---

# Proposal: or-flow-migrate-definitions

## Summary

Rewrite every stored flow from the pre-inversion shape (behaviour on edges) to
the action-node shape (behaviour on nodes).

The transformation is the graph **dual**: each old edge — which carried the step
— becomes a node, and two nodes are connected wherever the old edges met at a
place. It is mechanical, provable, and preserves the step sequence exactly.

## Migration is performed by hand, not by a repair step

Decided 2026-08-04. There are **14 flows, all on one instance, all owned by one
team**. A repair step would be code written once, run once, and then carried
forever — and it would have to be correct on the first attempt against live
automation, with no opportunity to look at the result before it lands.

Migrating by hand inverts that: each flow is converted, validated against
`POST /api/flow/validate`, and inspected before the next one. The dual is still
the specification — it is what makes each conversion checkable rather than a
judgement call — but it is applied deliberately rather than executed blind.

What this trades away, stated plainly: another instance with flows on it has no
automated path and must repeat the exercise. That is acceptable while the
population is one instance and 14 flows; it would not be at fleet scale, and if
a second instance appears with its own flows, this decision should be revisited
rather than scaled by hand.

The **verification** obligations do not relax. Every migrated flow must validate,
must produce the same step sequence, and must be diffed against its original.

## Why

`or-flow-action-nodes` refuses the old shape by name rather than reinterpreting
it, deliberately: a half-migrated document under dual support would run, skip a
step, and report success. That refusal is only safe if every stored flow is
actually migrated, so the migration is the other half of that decision and must
land with it.

## What Changes

- A repair step converts every row in `oc_openregister_flows`.
- The conversion is the dual described in `design.md`, including the rule that
  a non-terminal step left as a sink is marked `exit: true` so it does not
  become a dead end under `or-flow-connectivity-and-last-run`.
- It is **idempotent**: a flow already in the new shape is left alone, detected
  by the same predicate the engine refuses on (any edge carrying a `type`).

## The hazard this migration must avoid

**It must not read flows through an organisation-scoped path.** Measured on the
dev instance: `oc_openregister_flows` holds **14** rows for `app='hermiq'`, but
`GET /api/flows?app=hermiq` returns **13** — "E2E smoke graph"
(`26723b91-…`) belongs to organisation `9db9eae6-…` while the session's
organisation is `286a9152-…`, and `FlowService` applies org scoping
unconditionally.

A migration written against the API, or against any org-scoped read, would skip
that flow. It would stay in the old shape, be refused at run time, and the
refusal would look like a bug in the engine rather than a gap in the migration.
The repair step therefore reads through the mapper with no organisation filter,
and reports a count it can be checked against.

One flow is also owned by `__system__` (`Hydra Triage`, seeded by hermiq's
`SeedHydraTriageFlow`) — another row an owner-scoped read could drop.

## Impact

- **Affected code**: `lib/Migration/` repair step, `FlowMapper` (unscoped read)
- **Affected data**: all 14 stored flows; in-flight runs (see below)
- **Affected apps**: none directly — every consumer reads through the engine

## In-flight runs do not survive

Synthetic place names change shape, and `FlowRunMarkingStore` persists raw
markings. A run suspended across the migration would resume against places that
no longer exist.

Measured now: 564 completed, 60 stopped, 15 failed, and **zero** running or
suspended — so today the cost is nil. That will not always be true, so the
repair step refuses to run while any run is in a non-terminal state, and says
which, rather than silently orphaning them.

## Capabilities

### Modified Capabilities
- `flow-storage` — the stored shape of every flow definition
