# Lane log r2-openregister (learniq round 2, 2026-09-27)

Clone: /home/rubenlinde/memcap-work/lq-lanes/or-primitives. Never staged.
TMPDIR for check:strict: /tmp/claude-1000/or-lane-tmp.

## 1. store-plane-publish
- Branch: feat/store-plane-publish (cut --no-track from origin/development d611a366a7)
- Dedupe: no publish/write path in lib/AppHost or in any open/archived store change
  (store-over-federated-config, store-plane-action-auth, -declarative-sources,
  -install-auth, -install-ops). FederatedConfigService publishes signed bundles, a
  different path.
- Artifacts: proposal, delta spec (7 ADDED requirements), design (with learniq adoption),
  tasks. openspec validate: valid.
- Decision: publishGroups is a LIST (not a single group) so learniq can pass
  getAllowedGroups('course-package.share') and keep its matrix the one source of truth;
  matching mirrors ADR-023 (admin passes, everyone honoured) except an empty list refuses
  everybody, admins included.
- Apply: all code tasks done. Files: GenericStoreService (publish + shared send()),
  StoreDescriptor (publishFields/publishGroups), StoreActionAuthorizer::canPublish (IGroupManager),
  new StorePublishRules (pure rules, split out for phpmd class complexity), docs section in
  docs/Technical/building-an-app-on-apphost.md, tests (80 store tests green).
- check:strict (once): exit 1. lint failed on a stale truncated .tmp/phpstan/resultCache.php
  left in the clone by an earlier round (moved to /tmp/claude-1000/or-lane-tmp/stale-from-clone);
  phpcs 0; phpmd flagged GenericStoreService at 50/50 (isolated measure 48) -> reduced to 45;
  psalm 0; phpstan 0; phpunit 24111 tests OK but exit 1 from "No code coverage driver
  available" (box has no xdebug/pcov; --no-coverage exits 0).
- Re-verify after the phpmd fix: composer lint 0 (4393 files); full phpmd with isolated
  HOME (fresh pdepend cache) 0; phpunit full --no-coverage 0 (24113 tests); phpcs/phpstan/psalm
  on touched files 0/0/0.
- npm lint 0 (932 inherited warnings), npm format 0, npm test:l10n 0.
- Gates (--scope-to-diff): exit 1, only gate-112 newman-reach (21 inherited collections outside
  CI path); gate-68 checker crashed (no finding). gate-16/19/46/1/2/3/64 PASS.
- Commits c7ec323c98 + c94b5c12e5 pushed. PR https://github.com/ConductionNL/openregister/pull/4079
- opsx-verify: no CRITICAL, no WARNING; 1 SUGGESTION (duplicate SLUG_PATTERN in controller).
- DONE. Time: about 2h15 (mostly waiting on the full-tree runs).

## 2. demo-data-purge-by-batch
- Branch feat/demo-data-purge-by-batch (--no-track from origin/development d611a366a7).
- Dedupe: no open or archived change and no open PR covers purge of app imports
  (config-import-seed-objects is about top-level objects only).
- Decisions: job id recorded per importFromApp appId (learniq.demo, decidesk.profile.<id>),
  list not single id (re-loads), only jobs with traced creates recorded; removal is in-process
  as SystemOperationContext; HTTP rollback refuses app import jobs (409); occ purge
  --import-job keeps --force/--apply rules, missing = already gone.
- Code: AppImportJobRecorder (new), ImportHandler::importFromJsonAsJob, ConfigurationService
  importJobs/softDeleteAppImports, AuditTrailMapper count + uuids, PurgeObjectCommand
  --import-job, RegistersController 409, Application wiring. ImportService unchanged.
- Tests: 657 around touched classes green + 3 mapper tests; mutation check (finally end(),
  rollback 409) both caught. phpcs/phpmd(isolated)/phpstan/psalm on touched lib: 0/0/0/0.
- npm lint 0 (932 inherited warnings), format 0, test:l10n 0.
- Commit 3f84df459d. (Killed by an account rate limit here; resumed 09-27.)
- check:strict (once): exit 1. lint/phpcs/psalm/phpstan 0; phpmd flags only
  ConsentEnvelopeOnSaveListener (not in diff; passes with a fresh pdepend cache, the shared
  ~/.pdepend lies); test:all 24105 OK, exit 1 = no coverage driver.
- Gates: exit 2. gate-57 flagged ConfigurationService::importJobs (read named like a write):
  renamed to listImportJobs (054905abfc), gate-57 checker rerun clean. gate-112 inherited.
- Pushed 3f84df459d + 054905abfc. PR https://github.com/ConductionNL/openregister/pull/4080
- Next: full phpmd (isolated HOME) + phpunit --no-coverage reruns, then opsx-verify, then
  one CI read of both PRs.

## CI read (once) and fix pass
- PR 4079 CI: 34 pass, 2 fail. Only real red: PHPUnit job step "Guard coverage baseline"
  (changed-file coverage 89.23% -> 83.19%). Cause: phpunit.xml beStrictAboutCoverageMetadata
  = true; the new canPublish tests (@covers StoreActionAuthorizer) and StorePublishRulesTest
  (@covers StorePublishRules) also execute StoreDescriptor, so PHPUnit marks them risky and
  DISCARDS their coverage. Quality Report job just aggregates that red.
- Fix planned: change 1 add `@uses StoreDescriptor` to both test classes (CI's risky list names
  only StoreDescriptor). Change 2: drop @covers from ImportHandlerImportJobTest,
  ConfigurationServiceAppImportsTest, AuditTrailImportJobQueriesTest (they execute Configuration
  entity / SystemOperationContext / AuditTrail + AuditTrailPayloadHelper), keep it on
  AppImportJobRecorderTest (mocks only). No coverage driver on this box, so this cannot be
  proven locally; CI is the check.
- Waiting for the change 2 phpmd/phpunit rerun before switching branches.

## After the WSL restart (27 Sep)
- /tmp wiped: isolated caches and the interrupted phpmd/phpunit reruns for change 2 were lost
  (r2-c2-phpmd.log empty, no phpunit log). Clone clean, HEAD 054905abfc = origin.
- PR 4080 CI read (once): 36 pass, 8 skipping, 0 fail (44 checks, same count as 4079). The
  coverage guard PASSED there, so the planned @covers pruning on change 2 is dropped.
- Reruns for change 2 restarted (TMPDIR and HOME under /tmp/claude-1000/or-lane-tmp).
- Next: record reruns + opsx-verify in PR 4080 body; then switch to feat/store-plane-publish
  and add @uses StoreDescriptor to StoreActionAuthorizerTest + StorePublishRulesTest (the NEW
  coverage-guard red on 4079), push, edit PR body, read 4079 CI once more at the end.
- Change 2 reruns: phpunit --no-coverage 24105 tests exit 0; full phpmd fresh cache exit 0.
  Task 3.2 ticked (4d97881502, pushed). PR 4080 body updated with reruns, CI, verify verdict.
- Change 1 fix: cb198dae91 adds @uses StoreDescriptor to StoreActionAuthorizerTest and
  StorePublishRulesTest (80 store tests green --no-coverage); pushed; PR 4079 body updated.
  Cannot prove the coverage guard locally (no coverage driver). One more CI read of 4079
  is the remaining check.
