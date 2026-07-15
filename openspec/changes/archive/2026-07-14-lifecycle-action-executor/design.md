# Design — lifecycle-action-executor

## Context

`x-openregister-lifecycle` already has three of its four moving parts wired:
`TransitionEngine` (named-action transitions), `LifecycleValidationListener`
(transition legality on the save path), `LifecycleGuardRegistry` +
`LifecycleGuardInterface` (`requires` guards). The fourth — running a
transition's declared `actions[]` — was never built, and the save path never
fired `ObjectTransitionedEvent` for list-form edits. This change adds the action
executor along the **same seams** the other three use, so it is a fourth engine
in an established family, not a parallel mechanism.

## What we mirror (cited)

| New artifact | Mirrors | What is copied |
| --- | --- | --- |
| `LifecycleActionInterface` | `Lifecycle\LifecycleGuardInterface` | Public app-implemented contract, DI-resolved, one method taking the object payload + action name. Guard = authorise (read-only); action = run side effect. |
| `LifecycleActionRegistry` | `Service\Lifecycle\LifecycleGuardRegistry` | OR container → server container fallback, per-request `$cache`, `IServerContainer` injection (not `\OC::$server`, banned in `lib/`), and the **fail-loud missing-id `RuntimeException`**. |
| `LifecycleActionListener` | `Listener\ApprovalChainGateListener` (parse + match) and `Listener\CalculationOnSaveListener` (mutate before persistence) | Schema-parse off `Schema::getConfiguration()`, `matchTransition()` re-derived from old/new lifecycle value exactly as `LifecycleValidationListener::findTransitionByTarget()`, and `$object->setObject($data)` pre-persistence mutation. |
| Registration in `Application.php` | The `LifecycleValidationListener` → `ApprovalChainGateListener` block | One `registerEventListener(ObjectUpdatingEvent::class, …)` line, ordered after the validation + approval-gate listeners so actions only run for a legal, non-blocked transition. |
| Fail-loud anti-phantom guard | approval-chains #396 (`ApprovalChainGateListener` fail-closed on unprovisioned chain; `LifecycleGuardRegistry` throw on missing tag) | A declared action whose handler cannot be resolved throws, aborting the save, rather than silently no-oping. |

## Key decisions

**Hook the save path, not the `/transition` endpoint.** The executor runs from a
`ObjectUpdatingEvent` listener. Every mutation — `TransitionEngine::transition()`
(which itself calls `saveObject()`), a list-form `PATCH`, an import — goes through
`MagicMapper::updateObjectEntity()`, which dispatches `ObjectUpdatingEvent`. So the
executor runs **regardless of transition form**. This is the #427 fix: the bypass
was that list-form edits never reached `TransitionEngine`; they always reach the
save path. We deliberately do **not** add a second `ObjectTransitionedEvent`
dispatch for list-form edits — that would double-dispatch for the `TransitionEngine`
path and risk regressing its existing listeners; the executor running on the save
path already covers both forms.

**Self-mutation is pre-persistence.** `MagicMapper::updateObjectEntity()` merges a
listener's `getModifiedData()` into the entity *and* re-serialises the entity
after the event, so mutating `$newObject->setObject()` inside the listener is
persisted in the same save (verified against `CalculationOnSaveListener`, which
uses the identical approach). `SetFieldsAction` therefore needs no second save and
cannot recurse (the lifecycle field itself is unchanged by the action).

**Ordering.** Registered after `LifecycleValidationListener` and
`ApprovalChainGateListener`. Both reject/block via `stopPropagation()`; the action
listener checks `isPropagationStopped()` first and returns, so a rejected or
approval-pending transition never runs its actions.

**Conditions.** Actions may carry a `condition`. We support the two forms
register.d actually declares — `@self.<field> <op> '<literal>'` and
`@previous.<field> <op> '<literal>'`, operators `==`/`!=`. An **unparseable**
condition throws (fail loud) rather than silently skipping the action — a fuller
expression grammar is a follow-up, and until then a condition we cannot evaluate
must surface, not swallow the action.

**Built-in scope.** Ships `SetFieldsAction` for `set-fields` + `set-field` (the 2
most-declared names, 16 of ~60 fleet declarations). Domain actions
(`materialise-gl-transaction` ×13, `audit-trail-append`, `emit-event`, …) are
inherently app-specific and become app-registered handlers the registry resolves
by DI id — with the fail-loud guard ensuring a declared-but-unregistered action
can no longer masquerade as done. Follow-up: generic built-ins for
`audit-trail-append` and the `emit-*` event family.

## Seed Data

None. This change adds no registers, schemas, objects, or migrations. It executes
`actions[]` blocks that already exist in fleet register.d schemas; the built-in
handler and the executor are stateless services. The existing
`x-openregister-lifecycle` vocabulary entry in `Schema::ANNOTATION_VOCABULARY`
already preserves the nested `actions` block through the save round-trip, so no
schema or config seed is required.

## ADR-031 (declarative object notifications / annotation engines)

ADR-031 establishes that object-level behaviour declared in schema annotations is
materialised by an OpenRegister engine, not reimplemented imperatively per leaf
app. The fleet's action declarations already carry
`"trigger": "x-openregister-lifecycle-action"` and describe themselves as
"declarative materialisation per ADR-031" — they were authored against an engine
that did not exist. This change supplies that engine along the ADR-031 pattern:
one save-time listener reads the annotation, resolves handlers through a registry,
and runs them; leaf apps contribute domain handlers by DI registration rather than
by wiring around OR with `ObjectUpdatedEvent` (as the shillinq lease fix had to).
The fail-loud registry enforces ADR-031's contract that a declared capability must
be real — a missing handler is a hard error, not a quiet gap.
