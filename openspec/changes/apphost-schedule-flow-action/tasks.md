# Tasks — `openregister:flow-run` schedule action

## 1. Allow-list entry

- [ ] 1.1 Add `openregister:flow-run` to `ScheduleActionAllowList::MAP`, pointing at `OCA\OpenRegister\AppHost\Scheduling\Action\FlowRunAction`, keeping the value a plain string constant and the map closed.
  - `resolve('openregister:flow-run')` returns the action FQCN and `isAllowed()` is true.
  - `resolve('openconnector:synchronization')` is unchanged.
  - Any other action type still resolves to null.
- [ ] 1.2 Extend `tests/Unit/AppHost/Scheduling/ScheduleActionAllowListTest.php` to cover both entries and the closed-map behaviour.
  - A manifest-shaped FQCN string (e.g. `OCA\Evil\Payload`) resolves to null.
  - The test asserts the exact map size, so a future entry cannot be added silently.

## 2. The flow-run action

- [ ] 2.1 Create `lib/AppHost/Scheduling/Action/FlowRunAction.php` with an SPDX docblock, `declare(strict_types=1)`, a `@spec` tag pointing at this change's spec, and a `run(array $argument=[]): array` method returning a stack-trace-shaped result like `SynchronizationAction`.
  - Constructor injects `FlowResolverRegistry`, `FlowRunService`, `IUserSession`, `IUserManager` and `LoggerInterface` only.
  - The class contains no graph walking and no direct `ObjectService` use.
- [ ] 2.2 Resolve the acting user first: read the UID from `IUserSession`, re-verify it with `IUserManager::get()`, and return an ERROR result with no side effect when it is null or unresolvable.
  - No call to `queue()` happens on this path.
  - The error message names missing attribution as the reason and carries no secrets.
- [ ] 2.3 Ignore any `runAs` / `owner` key present in `$argument`; never read identity from arguments.
  - A schedule declaring `runAs` produces a run attributed to the session-resolved owner.
- [ ] 2.4 Resolve `argument['flowId']` through `FlowResolverRegistry::resolveFlow()`, returning an ERROR result with no queued run when the id is missing, blank, unowned, or resolves to a non-flow-shaped document.
  - Resolution never passes register/schema as scoping arguments (or#2161: they do not scope).
  - A slug matching an object in a different register is refused, not queued.
- [ ] 2.5 Queue exactly one run via `FlowRunService::queue()` with `trigger: 'schedule'`, the non-null `user`, and the schedule's `arguments` carried under `context['payload']`.
  - The returned run has status `queued` and a non-null `triggeredBy`.
  - The action returns an OK result naming the flow id and the run uuid.
- [ ] 2.6 Add `tests/Unit/AppHost/Scheduling/Action/FlowRunActionTest.php` covering: happy path, no acting user, deleted acting user, missing `flowId`, unresolvable `flowId`, non-flow-shaped object, and `runAs` being ignored.
  - Each failure case asserts `queue()` was never called.

## 3. Native scheduled-flow attribution

- [ ] 3.1 Fix `FlowScheduleService::fire()` to pass the flow object's owner as `user:`, verified against `IUserManager`, and to skip the flow with a logged warning when it does not resolve.
  - The last-fire marker is only written when a run was actually queued.
  - A due flow with a live owner queues a run whose `triggeredBy` is that owner.
- [ ] 3.2 Add unit coverage for both branches of 3.1 in the flow-schedule service tests.
  - Ownerless due flow: no run queued, marker unchanged, warning logged.

## 4. Quality gates

- [ ] 4.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix every finding, including any pre-existing one in the files touched.
- [ ] 4.2 Run the hydra mechanical gates (SPDX, forbidden-patterns, stub-scan, spec-coverage, spec-anchor-existence, orphaned-write-capability) and clear all failures.
  - `FlowRunAction` is reachable from the allow-list, so the orphaned-write-capability gate must pass on its real seam.
- [ ] 4.3 Run the PHP unit suite in the `nextcloud:34` container (host PHP is too old) and confirm no regression.

## 5. Live verification

- [ ] 5.1 On the dev instance, create a flow containing an `openregister.object-write` step, owned by a live user.
- [ ] 5.2 Declare a `schedules[]` entry with `action: "openregister:flow-run"` and a short `interval` on a virtual app owned by that user, then let the reconciler sweep.
  - A single OpenConnector `job` exists for the schedule with the vetted `jobClass` and the owner's `userId`.
  - A second sweep with an unchanged declaration performs no write.
- [ ] 5.3 Let the job execute and confirm through the UI that a run appears with a non-null `triggeredBy` and that the object-write actually wrote an object.
  - The written object's owner is the application owner, not a system or null user.
- [ ] 5.4 Delete or disable the schedule's owner and confirm fail-closed behaviour end to end: no ownerless run is queued and the refusal is visible in the job log.
- [ ] 5.5 Confirm the negative paths live: a non-allow-listed action is skipped and logged, and a `flowId` naming an object in another register queues nothing.
- [ ] 5.6 Confirm a natively scheduled flow (`trigger: schedule`) now runs attributed, and that an ownerless one is skipped without advancing its last-fire marker.
