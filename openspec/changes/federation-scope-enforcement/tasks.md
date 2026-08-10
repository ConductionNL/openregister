# Tasks: federation-scope-enforcement

## Phase 1 — measure

- [x] Re-derive the Hydra gates against the FULL tree (not the diff the
      enablement PR was scoped to), with `ajv` resolvable as CI has it, under an
      isolated `/tmp` so a parallel run on the same host cannot cross-contaminate
      the fixed-path gate logs.
- [x] Triage every gate-6 / 7 / 8 / 9 finding by reading its call path, and
      record for each whether it is a real exposure or a false positive.

## Phase 2 — close the real ones

- [x] `FlowController::state()` resolves through `FlowService::find()` before
      reading `FlowStateMapper`. (REQ-FSE-002)
- [x] `FederatedConfigController::bundle()` enforces `canPublish()`. (REQ-FSE-003)
- [x] `GenericObjectShareableConfigType::serialise()` reads with RBAC and
      multitenancy ON. (REQ-FSE-003)
- [x] `FlowShareableConfigType::serialise()` reads through `FlowService`, not the
      unscoped mapper. (REQ-FSE-003)
- [x] `FederationController` enforces the share's object scope on
      `object()` / `updateObject()` / `deleteObject()`. (REQ-FSE-001)
- [x] `SaveObjects` refuses a row whose named schema could not be resolved
      instead of passing it through unchecked; the never-read
      `resolveSafeguardRegister()` is deleted rather than fixed. (REQ-FSE-004)

## Phase 3 — prove it

- [x] 16 tests across 5 files, each verified to FAIL with `lib/` reverted and
      pass with it restored. Every refusal test is paired with a positive control
      so it cannot be satisfied by a controller that refuses everything.
- [x] Full unit suite green against the untouched baseline (same pre-existing
      failure count, +16 tests).
- [x] phpcs / phpmd / psalm / phpstan clean on every changed file. No
      suppression, baseline entry or weakened assertion added anywhere.
