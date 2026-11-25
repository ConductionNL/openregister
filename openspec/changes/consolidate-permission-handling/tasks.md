# Tasks: consolidate-permission-handling

> One evaluator for "may this caller do this", honouring `public` and
> `authenticated` uniformly on the object AND entity planes.
>
> ORDER IS LOAD-BEARING. Task 1 pins today's behaviour on both planes BEFORE
> anything moves, because this change's dangerous failure is a silent
> LOOSENING and there is currently no test that would notice one.

## Implementation Tasks

### Task 1: Pin the CURRENT behaviour of both planes, by measurement
- **spec_ref**: `openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes`
- **files**: `tests/Unit/Service/Object/PermissionHandlerTest.php`, `tests/Unit/Db/MultiTenancyTraitTest.php`
- **acceptance_criteria**:
  - The object plane's anonymous branch is pinned as a PAIR per verb: a schema granting `public` `read` serves an anonymous caller rows; the same schema without that grant serves none. A test that asserts only the refusal passes just as well when everything is refused.
  - The entity plane's anonymous refusal is pinned for each mapper that uses `verifyRbacPermission()` — register, schema, agent, webhook, application, source, view, action, mapping, endpoint — so a consolidation that quietly widens ONE of them fails.
  - The two blanket bypasses (`PHP_SAPI === 'cli'`, `SystemOperationContext::isActive()`) are pinned as the ONLY ones. A third arriving later must break a test.
- [x] Implement (built in `tests/Unit/Db/EntityPlaneAnonymousPinTest.php` for the entity plane and `PermissionHandlerRbacTest::testAnonymousAccessFollowsThePublicGrantForEveryVerb` for the object plane; there is no ActionMapper on the trait, and ConfigurationMapper, which is on it, is pinned too)
- [x] Test

### Task 2: Extract the evaluator
- **spec_ref**: `openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes`
- **files**: `lib/Service/Object/PermissionHandler.php`, new shared evaluator, `lib/Db/MultiTenancyTrait.php`
- **acceptance_criteria**:
  - One function answers "does this authorization block grant this group this verb", and both planes call it. `MultiTenancyTrait::hasRbacPermission()` becomes delegation, not a second implementation.
  - Task 1's tests pass UNCHANGED. This step is a refactor: if a behaviour test needed editing here, the extraction changed behaviour and the diff is wrong.
  - The anonymous fail-closed write rule (#1955) lives in the shared evaluator, so it cannot be honoured on one plane and forgotten on the other — which is the class of bug this whole change exists to remove.
- [x] Implement (`lib/Service/Rbac/AuthorizationRuleEvaluator.php`; `PermissionHandler::anonymousVerdict()` and `EntityRbacTrait::groupsGrant()` delegate to it, the fail-closed write set moved into it, the object plane's own `entryNamesGroup()` / `publicGroupExplicitlyGranted()` removed)
- [x] Test (`tests/Unit/Service/Rbac/AuthorizationRuleEvaluatorTest.php`; Task 1's pins pass unchanged)

### Task 3: Honour `public` on the entity plane
- **spec_ref**: `openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-an-entity-may-declare-anonymous-access-and-it-must-be-honoured`
- **files**: `lib/Db/MultiTenancyTrait.php`, entity mappers, tests
- **acceptance_criteria**:
  - An entity whose authorization grants `public` a verb permits an anonymous caller that verb; one that does not still refuses. Asserted as a pair, per verb.
  - **No entity gains access by this change.** Register/schema/agent entities declare no authorization today, so every one of them must still refuse an anonymous caller afterwards — asserted by name, not by count. A count passes while the wrong entity opened.
  - opencatalogi's public surfaces are unaffected: `publication/page` and `publication/publication` still serve an anonymous caller the same row counts as before (7 and 3 on the reference instance). This is the outage tripwire.
- [x] Implement (`EntityRbacTrait::publicPolicyAllows()`: an anonymous caller is admitted only where the organisation's authorization names `public` for the entity type and action; absence, `*`, other groups and the enforcement flag being off still refuse; a refusal is logged once per entity type, action and cause. The opencatalogi row-count tripwire is a live check, see Task 4)
- [x] Test (`tests/Unit/Db/Tenancy/EntityPlanePublicGrantTest.php`, pair per verb and every entity type by name; the call-list pin in `EntityPlaneAnonymousPinTest` names the new branch)

### Task 4: Prove it on a live instance, both directions
- **spec_ref**: `openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-a-refusal-and-a-grant-must-both-be-observable`
- **files**: e2e / integration coverage
- **acceptance_criteria**:
  - Measured on a running instance, not only in unit doubles: an anonymous read of a `public` schema returns rows, an anonymous write to it is refused, and an anonymous register update is refused. A guard nobody has watched refuse is a guard nobody has tested.
  - The portal file-attach path works for a portal-JWT caller once the register folder is initialised, WITHOUT widening system trust — the fix belongs in folder initialisation (openregister#2515), and this task asserts the permission model did not paper over it.
  - A refusal that came from the shared evaluator says which plane and which rule it came from, so an operator can tell "no rule" from "rule said no".
- [ ] Implement (the object plane now records an anonymous refusal the way the entity plane does: `PermissionHandler::recordAnonymousRefusal()` names `plane: object`, the schema, the verb, `cause` `no-rule` or `rule-refused`, whether a block was declared and whether the anonymous write default decided it, once per schema, verb and cause per request. Measured on a sandbox Nextcloud 34 on MariaDB: anonymous read of a `public` schema 3 of 3 rows, of a closed schema 0, anonymous create 403 with one `plane: object, cause: no-rule, failClosedWrite: true` line per request, anonymous register update 401 and the register unchanged. Left open: the portal file-attach path for a portal-JWT caller, which needs a portal token issuer and the folder initialisation of openregister#2515)
- [ ] Test (`tests/Unit/Service/Object/PermissionHandlerAnonymousRefusalRecordTest.php`; `tests/e2e/ci/anonymous-access-both-planes.spec.ts`, 3 passed on that instance; the portal-JWT half is not covered)
