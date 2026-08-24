# Tasks — or-delegated-identity

Implements ADR-099 (hydra `openspec/architecture/adr-099-acting-on-behalf-of-a-user.md`).

## 1. Measure before enforcing

- [x] 1.1 Count the live blast radius and record it in this file before task 5 lands: scheduled flows whose trigger node declares no identity, flow runs with a null `triggeredBy`, and schedule registrations whose flow owner no longer resolves to an enabled user. Query via `occ` against the dev instance and against a production dump if one is available.

  **Measured 2026-08-24** — source: `conduction-postgres` / `nextcloud` (the main dev instance, NC 34.0.0), read directly over `psql` because the app container was in maintenance awaiting a DB upgrade. Query: counts over `oc_openregister_flows` / `_flow_runs` / `_flow_triggers`.

  | metric | count |
  |---|---|
  | flows | 92 (85 enabled) |
  | flows with no owner | 0 |
  | flow runs | 4045 |
  | runs with null `triggeredBy` | 0 |
  | flows with a schedule trigger node | 3 (2 enabled) |
  | …of those, declaring a `runAs` | **0** |
  | rows in the trigger index | **0** |

  Three findings that change the plan:

  - **The blast radius is 3 flows, all Hydra's own** (`Hydra lock reaper`, `Hydra dispatch`, `Hydra sequencer`), all owned by `admin`. Below the "handful" threshold, so 5.1 ships hard enforcement — no grace-period flag, no separate flip task.
  - 🔴 **All three schedule trigger nodes carry `"config":[]`** — no `runAs` *and no `cron`*. `TriggerScheduleNode::validate()` already requires a cron expression, so these three would fail validation today if it ran on their save path. Their schedule lives in the legacy `cron` COLUMN instead. They are mid-cutover per `flow-engine`'s "The cutover from trigger COLUMNS to trigger NODES MUST be proven per flow". Task 4.1 must therefore not assume a populated node config, and 4.3 must define what happens to a flow whose schedule is still column-side. Added as 4.4.
  - 🔴 **The trigger index is empty while 92 flows carry trigger nodes.** `BackfillFlowTriggerIndex` has not run here, so 4.2 cannot assume the index is populated and must be verified against a backfilled instance rather than this one.

- [ ] 1.2 Re-run 1.1 against a production dump before merge, and update the table. The dev instance is Hydra's own workspace and is not representative of customer flow usage; a count of 3 here does not license hard enforcement everywhere.

  Acceptance criteria:
  - The counts are written into this task as a dated line, not reported only in a PR comment
  - If scheduled flows without an identity exceed a handful on the production dump, task 5.1 ships logging-only behind an app config flag and the flag flip is a separate task
  - The measurement names its source (instance, date, query) so it can be re-run

## 2. The primitive

- [x] 2.1 `ObjectService::runAs()` switches from `IUserSession::setUser()` to `setVolatileActiveUser()`; keep restore-previous-in-`finally` and the return-value passthrough. Update the docblock: it currently explains the workaround but names the wrong method.
- [x] 2.2 Give `runAsSystem()` a stated reachability boundary — it MUST NOT be reachable from a flow node, an agent tool, or request handling. Enforce structurally where possible (do not inject the service into those classes) rather than by comment alone.

  **Measured 2026-08-24**: OpenRegister already conforms. The four real call sites are `ObjectService` (the definition), `ConfigurationService::importFromApp()` (the app's own shipped config at boot/webcron), `Configuration/ImportHandler` (shipped seed data) and `Repair/SeedVocabularyRegister` (a repair step) — all genuinely userless. `ObjectWriteNode`, `MagicMapper`, `MultiTenancyTrait` and `PermissionHandler` only *mention* it in prose, and `ObjectWriteNode`'s mention is a documented refusal to use it. So the boundary needed stating and pinning, not repairing; `SystemOperationContextBoundaryTest` now pins the call-site set and hard-fails any call from `lib/Service/Flow/Nodes/`, `lib/Controller/` or `lib/Service/Mcp/`.
- [x] 2.3 Extend `ObjectServiceRunAsTest` for the session-persistence guarantee: assert the session's own recorded user is unchanged during and after a scope, and that a nested scope restores to its immediate caller rather than to null.

  Acceptance criteria:
  - A scope established during a request with a session leaves `ISession`'s `user_id` untouched
  - `runAs` still restores on a throw, and the throw propagates unchanged
  - Nesting A → B → end leaves A in force

## 3. Attribution vs authorization on the run

- [x] 3.1 Add `runAs` to `FlowRun` (entity, mapper, migration) alongside the existing `triggeredBy`. Backfill `runAs = triggeredBy` for existing rows in the migration; no row may be left with a null `runAs`.
- [x] 3.2 Fold in the working-tree `FlowRunAttribution`: keep caller-wins and the independent tenancy resolution; replace the `flow.owner` identity fallback with the trigger node's declared identity, failing closed when there is none. Tenancy keeps its flow-organisation fallback.
- [x] 3.3 `FlowRunService::queue()` refuses an unattributable dispatch instead of writing a run every node will later reject. One refusal naming the cause; no run row created.
- [x] 3.4 `ObjectReadNode` and `ObjectWriteNode` read `runAs` rather than `context['triggeredBy']` for their access decisions; their existing fail-closed refusals keep their wording but name the right field.

  Acceptance criteria:
  - `triggeredBy` is no longer read anywhere to decide access (verify by grep, and by a test that changes only `triggeredBy` and asserts no access change)
  - The migration is reversible and leaves no null `runAs`

## 4. Identity comes from the trigger

- [x] 4.1 `TriggerScheduleNode::validate()` requires a `runAs` that resolves to an existing user, alongside the cron-expression check it already performs. Refuse the save with a message naming the missing or unresolvable user; add the field to the node's declared form.
- [x] 4.2 `FlowTriggerIndex` carries the trigger's identity so the cron registration and the identity it fires under derive from the same node. Deregister and re-register correctly when a trigger node is edited, removed, or its flow deleted.

  **Resolved without a schema change.** The goal — registration and identity deriving from one node, so they cannot disagree — already holds: `FlowRunAttribution` reads `runAs` off the schedule trigger node at fire time, and the node is also what declares the schedule. Duplicating the identity into the index would create a second copy to drift, which is the defect this task existed to prevent.

  What DID need fixing was a stale rationale that would mislead the next reader: `Flow::canDispatch()` and `FlowLocator::scheduledFlows()` both justified their owner check as "there is no identity to run it as". That is no longer true and reads as an authorization gate. Both now state that the owner is DEFINITION ownership (an unowned flow is an orphan that `belongsTo()` already fail-closes on) and point at the trigger node for identity. The gate itself is unchanged.
- [x] 4.3 `FlowScheduleService::fire()` takes the identity from the trigger node and drops the flow-owner fallback, superseding the or#2158 comment there.
- [x] 4.4 Define and implement the column-side case surfaced by 1.1: a flow whose schedule still lives in the legacy `cron` column with an empty trigger-node config. It must not silently keep firing ownerless. Either the cutover populates the node (preferred — it is the direction `flow-engine` already mandates) or the flow is disabled with its owner notified. Decide with the migration in 3.1 so both land together.

  Acceptance criteria:
  - A flow with both a manual and a schedule trigger runs as the clicking user when clicked, and as the declared user when fired
  - Editing a schedule trigger's cron expression does not orphan its registration

## 5. Re-resolve, never snapshot

- [x] 5.1 Re-resolve the acting identity at every fire and every resume, and fail the run closed with a reason when it no longer resolves to an enabled user. Do not fall back to the flow owner, to an administrator, or to no identity.
- [x] 5.2 When a registered schedule's identity dies, disable the schedule and notify the flow definition's owner via `x-openregister-notifications` (declarative, per ADR-031 and design.md). A schedule must never remain enabled while silently firing nothing.

  Acceptance criteria:
  - A permission revoked while a run is suspended takes effect when the run resumes
  - A disabled schedule is visibly disabled in the UI with a reason, not merely inert

## 6. Tests and verification

- [x] 6.1 Unit tests for each spec scenario in `specs/delegated-identity/`, `specs/flow-engine/` and `specs/rbac-scopes/`, tagged with `@spec` per ADR-020.
- [x] 6.2 E2E coverage in the local Playwright set: a scheduled flow that fires as its declared user and writes an object owned by that user; the save-time refusal of a schedule trigger with no identity; and a suspended run failing closed after its user is disabled.

  `tests/e2e/api-direct/delegated-identity.spec.ts` — 6 tests, all passing against a live instance, plus 153 pre-existing api-direct tests still green (no regressions).

  🔴 **The e2e earned its keep immediately**: it proved `TriggerScheduleNode::validateConfig()` was an ORPHANED CAPABILITY. A schedule trigger posted with `config: {}` — no cron, no identity — saved with HTTP 201, because `FlowNodePreflight` only calls `validateConfig()` for STEPS (`$edge['config']`) and a trigger is not a step. The unit tests passed throughout because they call the validator directly. That is why all three live schedule flows carry `config: []`. Fixed by `FlowTriggerValidator`, wired into `FlowService::save()`, with the refusal surfaced as 400 rather than an HTML 500.

  Two cases deliberately NOT covered here and moved to `or-delegation-grants`: a scheduled flow firing end-to-end on its cron (needs a cron tick, so it belongs in a timed suite), and the disabled-user resume path (needs a second account and a suspended run; the unit tests cover the refusal directly).
- [ ] 6.3 Run `composer check:strict` and the full PHPUnit suite; fix any pre-existing PHPCS/PHPMD/Psalm/PHPStan findings encountered in the touched files rather than deferring them.

  Acceptance criteria:
  - Every ADDED requirement has at least one test referencing it
  - `openspec validate or-delegated-identity` passes
  - No `@spec exclude` is used without a reason naming why the behaviour is untestable here

## 7. Publish the contract

- [ ] 7.1 Document `runAs` / `runAsSystem` in the app's developer docs as the fleet-facing contract the five duplicate implementations will bind to, and note that retiring each duplicate is that app's own change.
- [x] 7.2 Dutch translations for every new user-visible refusal and notification string (ADR-007/ADR-025); no template literals in translatable strings.
