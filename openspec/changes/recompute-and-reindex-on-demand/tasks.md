# Tasks: recompute-and-reindex-on-demand

## 1. On demand over a selection (REQ-RRD-001, row 4.17)

- [ ] 1.1 Extract `lib/Service/Calculation/CalculationRematerialiser.php` from `RematerialiseCalculationsCommand` with a `Selection` value (ids or filters) and `RecomputeResult`; the command calls it. Verify: `tests/Unit/Service/Calculation/CalculationRematerialiserTest.php::testASelectionTouchesOnlyItsObjects`, `testCommandAndEndpointProduceTheSameCounts`; the existing command test keeps passing.
- [ ] 1.2 `ObjectsController::recompute()` at `POST /api/objects/{register}/{schema}/recompute` (`#[NoAdminRequired]`, update check per object in the service), synchronous to 500, queued run above. Verify: `tests/Unit/Controller/RecomputeEndpointTest.php::testCountsAreReturned` (fails today: no route), `testADryRunWritesNothing`, `testAnObjectTheCallerMayNotUpdateIsFailedNotWritten`, `testAboveFiveHundredARunIsQueued`; hydra gates route-auth, no-admin-idor and route-reachability pass.
- [ ] 1.3 The bulk action on the object list. Verify: `tests/e2e/ci/recompute-selection.spec.ts` changes a calculation's expression on a fixture schema, filters, runs the action and reads the counts.
- [ ] 1.4 Through the route: a Newman request posts `ids` for three fixture objects with a stale value and asserts `touched` 3.

## 2. Parent to child (REQ-RRD-002, row 9.15)

- [ ] 2.1 `lib/Service/Calculation/CalculationDependencyMap.php`: from every schema's calculations, which relation and which properties of which target schema they read. Verify: `tests/Unit/Service/Calculation/CalculationDependencyMapTest.php::testARefReadIsMapped`, `testAnAggregateReadIsMapped`.
- [ ] 2.2 `lib/Listener/CalculationDependencyListener.php` on `ObjectUpdatedEvent`, registered in `lib/AppInfo/Application.php`, queuing `CalculationChildRecomputeJob` (`RecordedQueuedJob`) deduped per parent. Verify: `tests/Unit/Listener/CalculationDependencyListenerTest.php::testAReadPropertyChangeQueuesOneRun`, `testAnUnreadPropertyChangeQueuesNothing`, `testRunsAreDedupedPerParent`, constructing the real `ObjectUpdatedEvent` with real `ObjectEntity` objects (fails today: no listener).
- [ ] 2.3 Through the caller: `tests/Integration/ParentChangeRecomputesChildrenTest.php` updates a parent through `ObjectService::saveObject()`, runs the queued job, and reads the child's new value and a search hit on it; `tests/e2e/ci/parent-change-recomputes-children.spec.ts` renames a case type and sees the run on the operations console.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
