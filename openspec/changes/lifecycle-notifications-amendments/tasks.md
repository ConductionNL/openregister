# Tasks — Lifecycle + Notifications canonical-spec amendments

This change is **spec-only**. It graduates the new MUSTs that PR
#1353's fix commit added inside archived change folders into the
active `openspec/specs/` catalog. No code changes (the
implementation has already shipped on the feature branch).

## Phase 1 — Canonical event-driven-architecture amendments

- [ ] 1.1 Verify the canonical `openspec/specs/event-driven-architecture/spec.md` does not yet reference `x-openregister-lifecycle`, `ObjectTransitionedEvent`, the transition endpoint, or the lifecycle guard interface.
- [ ] 1.2 Apply the `## ADDED Requirements` block at the bottom of `specs/event-driven-architecture/spec.md` (this change directory) — see `specs/event-driven-architecture/spec.md`.
- [ ] 1.3 On graduation, merge those `## ADDED Requirements` blocks into `openspec/specs/event-driven-architecture/spec.md` under appropriate sections (event catalog, listener registration, controller annotations).

## Phase 2 — Canonical notificatie-engine amendments

- [ ] 2.1 Verify the canonical `openspec/specs/notificatie-engine/spec.md` does not yet pin a normative channel-block format, throttle-window grammar, or `x-openregister-notifications` annotation contract.
- [ ] 2.2 Apply the `## ADDED Requirements` block at the bottom of `specs/notificatie-engine/spec.md` (this change directory) — see `specs/notificatie-engine/spec.md`.
- [ ] 2.3 On graduation, merge those `## ADDED Requirements` blocks into `openspec/specs/notificatie-engine/spec.md`.

## Phase 3 — Reconciliation

- [ ] 3.1 After graduation, drop the `lifecycle-annotation` and `notifications-annotation` archived directories' duplicated normative MUSTs (the canonical spec is the source of truth — archives stay as the design record).
- [ ] 3.2 Update `openspec/platform-capabilities.md` to flip the lifecycle and notifications-annotation rows from "proposed" to "implemented" once Phase 1 + 2 have graduated.
