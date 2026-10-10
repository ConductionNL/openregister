---
kind: code
---

## Why

OpenRegister's `TransitionEngine` only supports **static** lifecycle graphs:
`x-openregister-lifecycle.transitions` is a fixed map of `action → { from: [literal
states], to: literal }`, and `/available-actions` filters those literals against the
object's current field value. Real government case processes model status as a
**relation**, not a literal: a `case.status` is a `$ref` UUID to a `statusType`
object, and the valid status set differs *per parent* (`statusType.caseType` FK →
the case's own `caseType`; ordering via `statusType.order`; terminality via
`statusType.isFinal`). Such dynamic FK-based status graphs are inexpressible today —
`/available-actions` always returns `[]`, so a read-only lifecycle field is
permanently frozen (found live on procest 2026-07-08; hydra ADR-062 rule 10 now
forbids `readOnly` on such fields until this feature ships). Procest is the reference
consumer waiting on this capability.

## What Changes

- **New `graph` mode** on `x-openregister-lifecycle`: alongside the existing static
  `transitions` map, a schema may declare a `graph` block that derives the valid
  transition set **at runtime** from sibling objects of a related schema, scoped to
  the object's own parent via a foreign key.
- `graph` fields: `schema` (sibling schema slug), `parentField` (FK on the sibling
  pointing at the parent), `parentFrom` (field on the transitioning object holding
  the parent reference), `orderField`, `finalField`, and `allowedMoves`
  (`forward` | `adjacent` | `any`).
- **Object-form `initial`**: `initial` may be `{ "from": "<parentFrom>", "field":
  "<field-on-parent>" }` to seed the starting status from the parent, in addition to
  the existing literal-string form.
- `TransitionEngine::availableActions` derives actions from ordered sibling
  `statusType` objects belonging to the object's parent; `forward` = only the
  next-higher order, `adjacent` = next + previous, `any` = every sibling. No move
  **out** of a state whose `finalField` is true unless `allowedMoves` is `any`.
  Actions get stable ids (`move-to-<targetUuid>`) with the target's display name as
  label.
- `TransitionEngine::transition` (and `TransitionController::apply`) validate the
  requested target through the **same derivation**, then mutate + save through the
  unchanged `ObjectService` path; `ObjectTransitionedEvent` fires exactly as for
  static transitions.
- **Static `transitions` takes precedence** when both `transitions` and `graph` are
  declared. Fully backwards compatible — no change to any existing static-mode
  behaviour.
- `LifecycleAnnotationValidator` accepts the new `graph` block and object-form
  `initial` (shape-check only; sibling existence is a runtime concern).

## Capabilities

### New Capabilities
<!-- none — this extends an existing capability -->

### Modified Capabilities
- `object-lifecycle`: adds a declarative **graph** transition mode that derives the
  available/target transitions at runtime from FK-scoped sibling objects, in addition
  to the existing static `transitions` map. Static mode is unchanged and takes
  precedence when both are present.

## Impact

- **Code**: `lib/Service/Lifecycle/TransitionEngine.php` (derivation logic for
  available-actions + apply-validation), `lib/Service/Lifecycle/LifecycleAnnotationValidator.php`
  (accept `graph` + object-form `initial`). `TransitionController` unchanged (same
  endpoints, richer payload). `ObjectService::findAll` used read-only to fetch
  ordered siblings.
- **APIs**: `/available-actions` response gains derived actions for graph-mode
  schemas (previously always `[]`); `move-to-<uuid>` action ids. No breaking change
  to the static-mode payload shape.
- **Consumers**: procest `case`/`statusType` registers become the reference consumer
  (register JSON change lives in the **procest** repo, not here).
- **No DB migration**, no new dependency.
