# Tasks — or-delegated-identity

Implements ADR-099 (hydra `openspec/architecture/adr-099-acting-on-behalf-of-a-user.md`).

## 1. Measure before enforcing

- [ ] 1.1 Count the live blast radius and record it in this file before task 5 lands: scheduled flows whose trigger node declares no identity, flow runs with a null `triggeredBy`, and schedule registrations whose flow owner no longer resolves to an enabled user. Query via `occ` against the dev instance and against a production dump if one is available.

  Acceptance criteria:
  - The counts are written into this task as a dated line, not reported only in a PR comment
  - If scheduled flows without an identity exceed a handful, task 5.1 ships logging-only behind an app config flag and the flag flip is a separate task
  - The measurement names its source (instance, date, query) so it can be re-run

## 2. The primitive

- [ ] 2.1 `ObjectService::runAs()` switches from `IUserSession::setUser()` to `setVolatileActiveUser()`; keep restore-previous-in-`finally` and the return-value passthrough. Update the docblock: it currently explains the workaround but names the wrong method.
- [ ] 2.2 Give `runAsSystem()` a stated reachability boundary — it MUST NOT be reachable from a flow node, an agent tool, or request handling. Enforce structurally where possible (do not inject the service into those classes) rather than by comment alone.
- [ ] 2.3 Extend `ObjectServiceRunAsTest` for the session-persistence guarantee: assert the session's own recorded user is unchanged during and after a scope, and that a nested scope restores to its immediate caller rather than to null.

  Acceptance criteria:
  - A scope established during a request with a session leaves `ISession`'s `user_id` untouched
  - `runAs` still restores on a throw, and the throw propagates unchanged
  - Nesting A → B → end leaves A in force

## 3. Attribution vs authorization on the run

- [ ] 3.1 Add `runAs` to `FlowRun` (entity, mapper, migration) alongside the existing `triggeredBy`. Backfill `runAs = triggeredBy` for existing rows in the migration; no row may be left with a null `runAs`.
- [ ] 3.2 Fold in the working-tree `FlowRunAttribution`: keep caller-wins and the independent tenancy resolution; replace the `flow.owner` identity fallback with the trigger node's declared identity, failing closed when there is none. Tenancy keeps its flow-organisation fallback.
- [ ] 3.3 `FlowRunService::queue()` refuses an unattributable dispatch instead of writing a run every node will later reject. One refusal naming the cause; no run row created.
- [ ] 3.4 `ObjectReadNode` and `ObjectWriteNode` read `runAs` rather than `context['triggeredBy']` for their access decisions; their existing fail-closed refusals keep their wording but name the right field.

  Acceptance criteria:
  - `triggeredBy` is no longer read anywhere to decide access (verify by grep, and by a test that changes only `triggeredBy` and asserts no access change)
  - The migration is reversible and leaves no null `runAs`

## 4. Identity comes from the trigger

- [ ] 4.1 `TriggerScheduleNode::validate()` requires a `runAs` that resolves to an existing user, alongside the cron-expression check it already performs. Refuse the save with a message naming the missing or unresolvable user; add the field to the node's declared form.
- [ ] 4.2 `FlowTriggerIndex` carries the trigger's identity so the cron registration and the identity it fires under derive from the same node. Deregister and re-register correctly when a trigger node is edited, removed, or its flow deleted.
- [ ] 4.3 `FlowScheduleService::fire()` takes the identity from the trigger node and drops the flow-owner fallback, superseding the or#2158 comment there.

  Acceptance criteria:
  - A flow with both a manual and a schedule trigger runs as the clicking user when clicked, and as the declared user when fired
  - Editing a schedule trigger's cron expression does not orphan its registration

## 5. Re-resolve, never snapshot

- [ ] 5.1 Re-resolve the acting identity at every fire and every resume, and fail the run closed with a reason when it no longer resolves to an enabled user. Do not fall back to the flow owner, to an administrator, or to no identity.
- [ ] 5.2 When a registered schedule's identity dies, disable the schedule and notify the flow definition's owner via `x-openregister-notifications` (declarative, per ADR-031 and design.md). A schedule must never remain enabled while silently firing nothing.

  Acceptance criteria:
  - A permission revoked while a run is suspended takes effect when the run resumes
  - A disabled schedule is visibly disabled in the UI with a reason, not merely inert

## 6. Tests and verification

- [ ] 6.1 Unit tests for each spec scenario in `specs/delegated-identity/`, `specs/flow-engine/` and `specs/rbac-scopes/`, tagged with `@spec` per ADR-020.
- [ ] 6.2 E2E coverage in the local Playwright set: a scheduled flow that fires as its declared user and writes an object owned by that user; the save-time refusal of a schedule trigger with no identity; and a suspended run failing closed after its user is disabled.
- [ ] 6.3 Run `composer check:strict` and the full PHPUnit suite; fix any pre-existing PHPCS/PHPMD/Psalm/PHPStan findings encountered in the touched files rather than deferring them.

  Acceptance criteria:
  - Every ADDED requirement has at least one test referencing it
  - `openspec validate or-delegated-identity` passes
  - No `@spec exclude` is used without a reason naming why the behaviour is untestable here

## 7. Publish the contract

- [ ] 7.1 Document `runAs` / `runAsSystem` in the app's developer docs as the fleet-facing contract the five duplicate implementations will bind to, and note that retiring each duplicate is that app's own change.
- [ ] 7.2 Dutch translations for every new user-visible refusal and notification string (ADR-007/ADR-025); no template literals in translatable strings.
