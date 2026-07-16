# Tasks: or-flow-engine

## 1. Dependency

- [x] 1.1 Add `symfony/workflow: ^6.4` to composer.json
- [x] 1.2 Confirm no version churn on OR's existing `symfony/*` packages
- [x] 1.3 Confirm NC core ships no `symfony/workflow` (nothing to shadow)
- [x] 1.4 Confirm `composer audit` reports no advisories

## 2. Engine core

- [x] 2.1 `FlowDefinitionBuilder` — document → Petri-net `Definition`
- [x] 2.2 Accept both `{from,to}` and `{source,target}` edge dialects
- [x] 2.3 Reject malformed documents by name (dangling edge, duplicate id,
      missing endpoint, unknown `initial`, no nodes)
- [x] 2.4 Infer initial places as graph sources; honour explicit `initial`;
      start a fully cyclic graph on its first node
- [x] 2.5 `FlowStepDispatcher` interface — the engine/app seam
- [x] 2.6 `FlowEngine::run()` — fire enabled transitions until none remain
- [x] 2.7 Port the run lifecycle (`completed`/`stopped`/`dead_letter`/`failed`)
- [x] 2.8 Port per-step `onError` (`stop`/`continue`/`dead_letter`); unknown
      policy fails safe by stopping
- [x] 2.9 Append-only run log
- [x] 2.10 Loop ceiling, reported as a failure rather than truncating silently

## 3. Verification

- [x] 3.1 Unit tests for the builder (14)
- [x] 3.2 Unit tests for the engine (12), including parallel split + join
- [x] 3.3 Prove the join refuses to fire on one token — the claim the engine
      choice rests on
- [x] 3.4 Run on the **container's** PHP 8.4, not just the host's 8.2
      (OR declares `^8.3`; the host cannot run it)
- [x] 3.5 Confirm pre-existing flow tests still pass (42/42)
- [x] 3.6 PHPCS clean against the OR standard

## 4. Follow-ups (separate changes)

- [ ] 4.1 OR-backed `MarkingStoreInterface` persisting to a flow-run object
- [ ] 4.2 Relocate openconnector's `flow` schema + `FlowRunnerService`; resolve
      `order`-as-identity before a canvas can drive it
- [ ] 4.3 Move openbuild's `DecisionTableEvaluator` to OR as the shared
      decision-table service
- [ ] 4.4 DMN/CMMN interchange (openregister#466) — driver unconfirmed
