# Lifecycle + Notifications: Amendments to canonical specs

## Why

PR #1353 review (2026-05-08) flagged a workflow misalignment: the
recent fix commit `4c268e0f4` added several new normative MUSTs
inside the archived change directories
`openspec/changes/archive/2026-04-29-lifecycle-annotation/` and
`.../2026-04-29-notifications-annotation/`. Archived changes are
frozen — their refinements never reach the canonical
`openspec/specs/event-driven-architecture/spec.md` or
`openspec/specs/notificatie-engine/spec.md`.

Meanwhile the implementation has already shipped on the feature
branch (`lib/Event/ObjectTransitionedEvent.php`,
`lib/Controller/TransitionController.php`,
`lib/Service/Lifecycle/TransitionEngine.php`,
`lib/Lifecycle/LifecycleGuardInterface.php`, etc.). The active spec
catalog still describes the pre-lifecycle world.

This change graduates the new MUSTs from the archive into the
canonical specs via an `## ADDED Requirements` block, so the
declarative-annotation surface that the platform has shipped is
reflected in the active capability catalog.

## What Changes

Targets `event-driven-architecture` (canonical) — add:
- `x-openregister-lifecycle` annotation contract (with the
  uniqueness constraint on `(from, to)` pairs).
- The pre-save validator listener on `ObjectUpdatingEvent` that
  reuses the existing `setErrors`/`getErrors` API to surface
  rejection reasons (NOT a new `setRejectionReason` API — that
  would be a breaking change for third-party listeners).
- The initial-state listener on `ObjectCreatingEvent`.
- The sugar `POST /api/objects/{id}/transition` endpoint with its
  full auth contract (`#[NoAdminRequired]`, no
  `#[NoCSRFRequired]`, NC's standard CSRF middleware applies on
  the state-mutating POST).
- `LifecycleGuardInterface` registration.
- `ObjectTransitionedEvent` joining the event family, with
  deterministic action resolution for direct PATCH (relying on the
  uniqueness constraint).
- The `available-actions` GET endpoint.

Targets `notificatie-engine` (canonical) — add:
- `x-openregister-notifications` annotation contract.
- Normative channel-block format (5 kinds: `nc-notification`,
  `email`, `webhook`, `talk`, `activity`) with required + optional
  fields per kind. SSRF-mitigation: `webhook` channels reference
  a pre-registered `Webhook` entity by UUID, never an inline URL.
- Normative throttle-window grammar
  (`^([1-9][0-9]*) per (second|minute|hour|day|week)$`).
- Trigger types `created`, `updated`, `transition`, `scheduled`,
  `threshold` with scenarios for each.

## Impact

- Affected specs: `event-driven-architecture`, `notificatie-engine`.
- Affected code: none (this change formalises requirements that
  the shipped implementation already satisfies, except for the
  `transition()` controller's CSRF annotation — that fix lives in
  the same PR's commit removing `@NoCSRFRequired`).
- Implementation status: implemented (controllers, listeners,
  events, guard interface all on this branch).
