## Scope

The store plane can now write. `GenericStoreService::publish(StoreDescriptor $descriptor, array $payload)` sends one object of the descriptor's schema to the configured registry under the plane's existing rules, and `StoreActionAuthorizer::canPublish()` answers who may send it.

Learniq round 2, decision D22 ("Lesson sharing is delivered by OpenRegister's store plane"). Learniq PR 1043 (`CourseStorePublisher`) posts to the registry's objects API itself today and fails hydra gate 62 for it; this is the method that replaces that call.

## What publish does

- Refuses without building a client: a descriptor that names no `publishFields` or no `publishGroups` (`not_publishable`), a payload without a valid slug (`not_publishable`), an unconfigured store (`not_configured`), a body over 20 MiB (`too_large`).
- Sends `slug` plus only the fields the descriptor lists. `id`, `uuid` and `@self` never travel, even when listed, so a publish cannot replace an object that already lives on the registry.
- POSTs JSON to `<base>/index.php/apps/openregister/api/objects/<register>/<schema>` through the same guard chain as discovery (a shared private `send()`): SSRF guard first, `allow_redirects: false`, 10 second timeouts, token only as a Bearer header.
- Maps the answer: 3xx, 5xx and transport failures are `store_unreachable`, 429 is `rate_limited`, other 4xx are `store_rejected` (new), a 2xx that is not a JSON object or carries another slug is `store_invalid_response`, a 2xx with the sent slug is `ok`. Upstream detail is logged, never returned.
- Every existing descriptor stays read-only: both new lists default to empty.

`canPublish()`: an empty group list refuses everybody, administrators included, and logs at ERROR. Once a group is named, matching mirrors ADR-023 (administrator passes, `@authenticated` admits any signed-in user, otherwise membership). A named group that does not exist admits nobody and is logged. This is so learniq can pass `getAllowedGroups('course-package.share')` and keep its own matrix the one place an administrator changes who may publish.

The pure rules (body allowlist, slug check, status mapping, answer decode) live in the new `lib/AppHost/Store/StorePublishRules.php`, because keeping them in the service took it over phpmd's class complexity threshold.

## How learniq replaces its own HTTP call

Learniq PR 1043, branch `feat/lesson-sharing-via-store-plane`, three files:

1. `lib/Service/CourseStore/CourseStoreDescriptor.php` takes learniq's `ActionAuthService` and passes two more arguments to `new StoreDescriptor(...)`:
   ```php
   publishFields: ['kind', 'title', 'description', 'subject', 'level', 'levels', 'goals', 'goalsCovered', 'language', 'license', 'author', 'cardLine', 'version', 'lessonCount', 'sharedAt', 'package'],
   publishGroups: $this->actionAuth->getAllowedGroups(action: 'course-package.share')
   ```
2. `lib/Service/CourseStore/CourseStorePublisher.php` drops `IClientService`, `IAppConfig`, `CourseStoreUrlGuard`, `objectsUrl()`, `post()`, `MAX_BYTES` and `TIMEOUT`. Its constructor takes `GenericStoreService $storeService`, `StoreActionAuthorizer $authorizer`, `CourseStoreDescriptor $descriptor` and `CourseStoreRegistryObject $registryObject`, and its methods become:
   ```php
   public function isConfigured(): bool {
       return $this->storeService->isConfigured(descriptor: $this->descriptor->descriptor());
   }

   public function mayPublish(IUser $user): bool {
       return $this->authorizer->canPublish(descriptor: $this->descriptor->descriptor(), user: $user);
   }

   public function publish(array $package): array {
       return $this->storeService->publish(
           descriptor: $this->descriptor->descriptor(),
           payload: $this->registryObject->build(package: $package)
       );
   }
   ```
   Its outcome constants point at `GenericStoreService` (`OUTCOME_OK`, `OUTCOME_NOT_CONFIGURED`, `OUTCOME_UNREACHABLE`, `OUTCOME_REJECTED`, `OUTCOME_TOO_LARGE`). The strings are the ones learniq returns today, so its frontend does not change.
3. `lib/Controller/StoreController.php::publish()` keeps `requireAction(ACTION_PUBLISH)`, returns 403 when `mayPublish($user)` is false, and adds three rows to `PUBLISH_STATUS`: `not_publishable` => 500, `rate_limited` => 429, `store_invalid_response` => 502.

Then delete `CourseStoreUrlGuard` and its psalm stub entry, and add `publish()` to `tests/Stubs/AppHost/Service/GenericStoreService.php`. With no `IClientService` and no objects-API URL left in learniq's `lib/`, gate 62 passes. Learniq needs an OpenRegister that carries this PR.

## Spec

`openspec/changes/store-plane-publish/` (proposal, design, tasks, delta spec). The delta adds seven requirements to `apphost-store-plane` as ADDED, not MODIFIED: `store-over-federated-config` already modifies the descriptor requirement, and two open deltas rewriting one requirement conflict on archive (design D1). `openspec validate store-plane-publish`: valid. Main spec status set to in-progress.

## Verification

Each command with its exit code, run in the lane clone on the committed tree (`c7ec323c98`):

| Check | Exit | Notes |
|---|---|---|
| `vendor/bin/phpunit --filter 'GenericStoreServiceTest\|StoreActionAuthorizerTest\|GenericStoreControllerTest\|StorePublishRulesTest' --no-coverage` | 0 | 80 tests, 219 assertions. Fake client, no network. |
| Mutation check: identity stripping and slug verification disabled by hand | tests red | `testPublishNeverSendsAnIdentityKey` and `testPublishRejectsAMismatchedSlug` both failed, file restored. |
| `composer check:strict` (once, `TMPDIR` outside the checkout, `COMPOSER_PROCESS_TIMEOUT=0`) | 1 | See the three reds below. phpcs 0, psalm 0 (full tree), phpstan 0 (full tree). |
| red 1: `lint` | 1 then 0 | Failed on a truncated `.tmp/phpstan/resultCache.php` an earlier lane round left in this clone's untracked `.tmp/`. Moved it out of the checkout; `composer lint` rerun: exit 0 over 4,393 files. |
| red 2: `phpmd` | 2 then 0 | Reported `GenericStoreService` at complexity 50 against a threshold of 50 (it measured 48 in isolation). I moved the pure rules into `StorePublishRules` (now 45). Full `phpmd lib` rerun with a private pdepend cache (`HOME` isolated, because `~/.pdepend` is shared across clones): exit 0. |
| red 3: `test:all` | 1 | 24,111 tests OK; the exit 1 is PHPUnit's "No code coverage driver available" (this box has no xdebug or pcov: a single filtered class exits 1 with coverage configured and 0 without). Full suite rerun with `--no-coverage`: 24,113 tests, exit 0. |
| `vendor/bin/phpcs`, `phpstan analyse`, `psalm` on the four touched lib files after the phpmd fix | 0, 0, 0 | |
| `npm run lint` | 0 | 0 errors, 932 inherited warnings. |
| `npm run format` | 0 | |
| `npm run test:l10n` | 0 | |
| `run-hydra-gates.sh --scope-to-diff --base origin/development` | 1 | Only gate-112 newman-reach failed (inherited, see below). gate-16 spec-coverage, gate-19 e2e-coverage, gate-46 spec-anchor-existence, gate-1 SPDX, gate-2 forbidden-patterns, gate-3 stub-scan, gate-64 PASS. gate-62 store-plane is not applicable here (no manifest touched); it is the gate learniq clears by adopting this. gate-68 did not run (its checker exited without a count). |
| `openspec validate store-plane-publish` | 0 | valid |

## CI fix pass

The first CI run was 34 pass, 2 fail. The one real red was the PHPUnit job's "Guard coverage baseline" step: coverage of the changed files went from 89.23% to 83.19%. `phpunit.xml` sets `beStrictAboutCoverageMetadata`, and the `canPublish` cases (`@covers StoreActionAuthorizer`) and `StorePublishRulesTest` (`@covers StorePublishRules`) also execute `StoreDescriptor`, so PHPUnit marked them risky and dropped their coverage. `cb198dae91` declares `@uses StoreDescriptor` on both test classes, as `GenericStoreControllerTest` already does for the same reason. The "Quality Report" red only aggregates that job. This box has no coverage driver, so the fix is proven by the next CI run, not locally; the 80 store tests still pass (`--no-coverage`, exit 0).

## opsx-verify (headless)

| Dimension | Result |
|---|---|
| Completeness | 11/11 tasks, 7/7 requirements implemented |
| Correctness | 7/7 requirements mapped to code; all 21 tests the spec cites exist and pass |
| Coherence | Design D1 to D5 followed (ADDED not MODIFIED, identity stripping, outcome table, shared `send()`, ADR-023 matching) |
| API and browser tests | Skipped: backend client with no OpenRegister UI; covered by PHPUnit with a fake client |

No CRITICAL and no WARNING. One SUGGESTION, left for a follow-up: `GenericStoreController::SLUG_PATTERN` duplicates `StorePublishRules::SLUG_PATTERN`; the controller can point at the public one.

## Inherited findings

gate-112 newman-reach names 21 Postman collections under `tests/newman`, `tests/postman` and `tests/federation` that CI never runs; this PR touches none of them. The full-suite PHP warning (`ReplyThreadResolver.php:235`, array to string) is in a file this PR does not touch.

Base: `development` (not stacked).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
