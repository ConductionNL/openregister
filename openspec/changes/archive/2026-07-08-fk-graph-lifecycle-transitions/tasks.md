## 1. Validator: accept graph mode

- [x] 1.1 In `LifecycleAnnotationValidator::validate()`, branch when `x-openregister-lifecycle.graph` is present: shape-check `schema`/`parentField`/`parentFrom`/`orderField`/`finalField` (non-empty strings) and `allowedMoves` (`forward`|`adjacent`|`any`), emitting `lifecycle-graph-*` error codes.
- [x] 1.2 Relax the `field` enum/`type:string` requirement when `graph` is present (keep `field` required, non-empty); accept object-form `initial` `{ from, field }` alongside the existing literal-string form.

## 2. Engine: graph derivation

- [x] 2.1 Add a private `deriveGraphActions(ObjectEntity, array $graph, string $field)` to `TransitionEngine` that reads the parent ref, fetches ordered siblings via `ObjectService::findAll` (filter `parentField == parentFrom value`, sort asc by `orderField`, UUID tiebreak), locates the current state, and returns candidate targets per `allowedMoves` with `move-to-<uuid>` ids and display-name labels.
- [x] 2.2 Implement terminal lockout: current sibling `finalField == true` yields no candidates unless `allowedMoves` is `any`; empty parent ref or absent siblings yields `[]`.
- [x] 2.3 Wire mode selection in `availableActions()`: non-empty static `transitions` → existing path (unchanged); else non-empty `graph` → `deriveGraphActions`; else existing empty behaviour.
- [x] 2.4 Wire `transition()` to re-run the SAME derivation, accept the posted action only if it is a current candidate, mutate `data[field]` to the target UUID, save through `ObjectService::saveObject`, and dispatch `ObjectTransitionedEvent(from, to, action)`.

## 3. Auto-seed on create

- [x] 3.1 Add a lifecycle-seed step to the `SaveObject` CREATE pipeline (before schema validation/persistence, never on update): when the schema declares object-form `initial { from, field }` and the lifecycle field is absent/null/empty, resolve the parent via `object.data[initial.from]` through `ObjectService` and set the field to `parent.data[initial.field]`; never overwrite a provided value; fail-soft no-op (debug log) on missing parent/empty initial value; dispatch no `ObjectTransitionedEvent`.

## 4. Tests

- [x] 4.1 Add `tests/Unit/Service/Lifecycle/TransitionEngineGraphTest.php` covering derivation with the Omgevingsvergunning seed data: `forward`/`adjacent`/`any` candidate sets, final-state lockout (blocked for forward/adjacent, open for any), static-precedence (both declared → static only, no sibling fetch), object-with-no-parent → `[]`, apply-rejects-non-candidate, and `ObjectTransitionedEvent` firing on a valid apply.
- [x] 4.2 Extend `LifecycleAnnotationValidator` tests: valid graph + object-form `initial` passes; invalid `allowedMoves` and each missing graph key are rejected with the expected codes.
- [x] 4.3 Add PHPUnit coverage for auto-seed on create: seeds when the field is empty; does not overwrite an explicitly provided value; no-ops when the parent ref is missing, the parent cannot be loaded, or the parent's initial field is empty; no seed on update; no `ObjectTransitionedEvent` for a seed.

## 5. Docs / API surface

- [x] 5.1 Document graph mode + auto-seed in the lifecycle annotation reference and the `/api/objects/{id}/available-actions` + `/transition` OAS/description surface: the `graph` block fields, `move-to-<uuid>` action ids + labels, and static precedence.

## Acceptance criteria

- Graph-mode `/available-actions` returns FK-scoped, order-derived actions for the procest `case`/`statusType` shape; previously it returned `[]`.
- `forward` offers only the next-higher status; `adjacent` offers previous+next; `any` offers all other siblings.
- No move out of a `finalField: true` state under `forward`/`adjacent`; `any` still offers other siblings.
- A schema declaring both `transitions` and `graph` uses static `transitions` only, with zero regression to existing static-mode behaviour.
- `transition()` rejects any `move-to-<uuid>` not in the current derived candidate set and never mutates on rejection.
- `ObjectTransitionedEvent` and the `saveObject` guard/validation path fire for graph transitions exactly as for static ones.
- Create with object-form `initial` and an empty lifecycle field seeds the field from the parent's initial status; an explicitly provided value is never overwritten; missing parent/initial value no-ops fail-soft; no seed event is dispatched.
- `composer check:strict` passes; all new PHPUnit tests green.
