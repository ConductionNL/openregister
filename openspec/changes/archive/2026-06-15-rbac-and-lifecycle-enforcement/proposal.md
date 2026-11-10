# Declarative per-transition authorization for x-openregister-lifecycle

## Problem

Two procest migrations are blocked on OpenRegister (the fleet foundation):
`migrate-status-engine-to-or-lifecycle` and `migrate-role-routing-to-or-rbac`.
An audit of OR's current state shows:

- **Lifecycle runtime enforcement already exists.** `LifecycleValidationListener`
  (on `ObjectUpdatingEvent`, dispatched by `ObjectService::saveObject()` →
  `MagicMapper::updateObjectEntity()`) already REJECTS illegal `from→to`
  transitions and evaluates `requires` PHP guards, opt-in via
  `x-openregister-lifecycle`, fail-closed, with structured errors. The
  status-engine migration is therefore already buildable on OR as-is.
- **Write RBAC already exists.** `ObjectService::checkSavePermissions()` enforces
  `create`/`update` authorization (group + conditional `match` + named roles)
  on the single-object save path via `PermissionHandler`.

The genuine gap is narrow but real: a lifecycle transition can only be gated by
group/role by writing a bespoke PHP guard class (the `requires` seam). There is
no **declarative** way to say "only NC group X (or named role Y) may perform
transition Z." Procest's role-routing needs exactly this — group-based gating of
workflow-step status transitions — and would otherwise be forced to ship a guard
class per gated transition.

A secondary friction: procest's migration shapes its annotation with `property`
(not `field`) and a string `from` (not always an array); OR only accepted `field`
and required `from` to be evaluated as an array.

## Proposed Solution

Add a declarative, additive `authorization` list to `x-openregister-lifecycle`
transitions, enforced fail-closed on the standard `saveObject()` path:

1. **`transitions[*].authorization`** — a list of NC group ids and/or
   `{ "role": "<name>" }` entries. When present, the caller MUST satisfy it for
   the transition to be applied; otherwise the save is rejected with
   `lifecycle-transition-unauthorized` (403-shaped). Resolution reuses the
   existing trusted `IGroupManager` membership check, `admin` bypass, and the
   schema's `authorization.roles: { name: [groups] }` assignment for the
   `{role}` indirection. Empty list authorizes nobody; anonymous callers are
   denied (CWE-863 fail-closed).

2. **Ergonomic aliases** — accept `property` as an alias for `field`, and a
   string `from` (coerced to a one-element list), so a leaf's documented schema
   shape works verbatim.

All changes are purely additive: a transition WITHOUT an `authorization` key, and
a schema WITHOUT `x-openregister-lifecycle`, behave exactly as before. READ
filtering and existing write RBAC are untouched. The public ObjectService API
(`find`/`findAll`/`saveObject`/`createObject`/`updateObject`/`deleteObject`) is
unchanged.

## Affected Projects

- [x] Project: `openregister` — engine implementation
- Consumer: `procest` — `migrate-status-engine-to-or-lifecycle`,
  `migrate-role-routing-to-or-rbac`
