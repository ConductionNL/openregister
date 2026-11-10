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
- [ ] Implement
- [ ] Test

### Task 2: Extract the evaluator
- **spec_ref**: `openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes`
- **files**: `lib/Service/Object/PermissionHandler.php`, new shared evaluator, `lib/Db/MultiTenancyTrait.php`
- **acceptance_criteria**:
  - One function answers "does this authorization block grant this group this verb", and both planes call it. `MultiTenancyTrait::hasRbacPermission()` becomes delegation, not a second implementation.
  - Task 1's tests pass UNCHANGED. This step is a refactor: if a behaviour test needed editing here, the extraction changed behaviour and the diff is wrong.
  - The anonymous fail-closed write rule (#1955) lives in the shared evaluator, so it cannot be honoured on one plane and forgotten on the other — which is the class of bug this whole change exists to remove.
- [ ] Implement
- [ ] Test

### Task 3: Honour `public` on the entity plane
- **spec_ref**: `openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-an-entity-may-declare-anonymous-access-and-it-must-be-honoured`
- **files**: `lib/Db/MultiTenancyTrait.php`, entity mappers, tests
- **acceptance_criteria**:
  - An entity whose authorization grants `public` a verb permits an anonymous caller that verb; one that does not still refuses. Asserted as a pair, per verb.
  - **No entity gains access by this change.** Register/schema/agent entities declare no authorization today, so every one of them must still refuse an anonymous caller afterwards — asserted by name, not by count. A count passes while the wrong entity opened.
  - opencatalogi's public surfaces are unaffected: `publication/page` and `publication/publication` still serve an anonymous caller the same row counts as before (7 and 3 on the reference instance). This is the outage tripwire.
- [ ] Implement
- [ ] Test

### Task 4: Prove it on a live instance, both directions
- **spec_ref**: `openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-a-refusal-and-a-grant-must-both-be-observable`
- **files**: e2e / integration coverage
- **acceptance_criteria**:
  - Measured on a running instance, not only in unit doubles: an anonymous read of a `public` schema returns rows, an anonymous write to it is refused, and an anonymous register update is refused. A guard nobody has watched refuse is a guard nobody has tested.
  - The portal file-attach path works for a portal-JWT caller once the register folder is initialised, WITHOUT widening system trust — the fix belongs in folder initialisation (openregister#2515), and this task asserts the permission model did not paper over it.
  - A refusal that came from the shared evaluator says which plane and which rule it came from, so an operator can tell "no rule" from "rule said no".
- [ ] Implement
- [ ] Test
