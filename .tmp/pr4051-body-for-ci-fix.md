## Scope

Adds a platform primitive letting a schema-gated Talk room accept a participant identified only by an email address, not a Nextcloud account. Closes the enabling-primitive gap `tier-b-and-sibling.md` names for learniq round-1 findings `9.3` (direct messages teacher-to-parent) and `9.15` (teacher inbox with per-group audiences): `CohortTalkMembershipHandler` syncs Talk membership from Nextcloud accounts only, and a guardian has no Nextcloud account in this fleet's model.

Ground truth checked before building: `integration-talk` (status: done) already ships `TalkProvider` (read/list) and `TalkLinkService` (Tier-2 link/create/unlink), but neither manages participants. This change adds the missing half by reusing `ParticipantService::addUsers()`, the exact call `createAndLinkRoom()` already makes for Nextcloud users, with `actorType: 'emails'` instead of inventing a second Talk API surface.

## What changed

- `lib/Db/Schema.php`: registered `x-openregister-talk-participants` in `ANNOTATION_VOCABULARY` (required: an unlisted `x-openregister-*` key is silently dropped by `setConfiguration()`, a documented failure mode against nine other entries in that file).
- `lib/Service/TalkLinkService.php`: added `inviteExternalParticipant(objectUuid, roomToken, email, ?displayName)` and `schemaAllowsExternalParticipants()`; added `SchemaMapper` as a new constructor dependency. Refuses (throws) on an unlinked room (404), a non-opted-in schema (403), a malformed email (400), or no logged-in user; degrades to `{invited: false, unavailable: true, cause}` only when Talk itself is unavailable (AD-23, matching every other Talk-adjacent primitive in this file).
- `lib/AppInfo/Application.php`: updated the `TalkLinkService` DI factory for the new dependency.

No REST controller/route in this change (see design.md "Non-Goals"): the service primitive is fully tested and consumer-ready; a route is deferred to the first real consumer to avoid shipping an endpoint this fleet's own `hydra-gate-route-reachability` concern would flag as unreachable.

## Verified

- `php -l` on all 4 touched files: clean.
- `vendor/bin/phpcs --standard=phpcs.xml` on the touched lib files: 0 errors (exit 0).
- `vendor/bin/phpstan analyse --memory-limit=1G` on the touched lib files: no errors (exit 0).
- `vendor/bin/phpunit` on `tests/Unit/Service/TalkLinkServiceTest.php` (5 new tests added, all 19 in the file pass, 45 assertions, 1 skipped by design (`@group requires-app-spreed`, unchanged from before this PR)).
- `vendor/bin/phpunit` on `tests/Unit/Controller/SchemasControllerTest.php`, `tests/Unit/Db/SchemaMapperTest.php`, `tests/Unit/Db/SchemaTest.php` (regression check on files this lane's sibling PR #4049 also touches): all pass, no regressions.
- `openspec validate guardian-participant-messaging-leaf --strict`: valid.
- `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (full suite, run once via the lane semaphore, `with-slot.sh`): exit 1 (`SOME CHECKS FAILED`) from `test:all` only (lint, check:migration-version, phpcs, phpmd, psalm, phpstan all passed). 24026 tests, 61114 assertions, 2 failures, neither in a file this PR touches: `MigrationVersionBumpCheckTest::testANonRepositoryRefusesToGiveAVerdict` (asserts an isolated, freshly-created temp dir's migration-gate verdict code, unrelated to Talk/Schema) and `FlowNodeRegistryTest::testAStepThatOverrunsItsCeilingIsStopped` (a wall-clock timing assertion, consistent with flakiness under this box's 13-lane shared-slot load). Confirmed neither file appears in `git diff --name-only origin/development HEAD`.

## Inherited findings

Two full-suite failures surfaced by `composer check:strict`, both pre-existing and unrelated to this PR's diff (see Verified above): `MigrationVersionBumpCheckTest::testANonRepositoryRefusesToGiveAVerdict` and `FlowNodeRegistryTest::testAStepThatOverrunsItsCeilingIsStopped`. Not fixed here per the inherited-debt policy.

## CI fix pass (2026-09-26)

The GitHub Actions run on this PR carried two NEW findings on top of the inherited composer-audit CVE (fleet-wide, also red on `development`):

- **PHP Quality (phpmd)**: `TalkLinkService::inviteExternalParticipant()` (CyclomaticComplexity 12>10, NPathComplexity 1152>200). Fixed by extracting three focused helpers: `assertInviteAllowed()` (schema opt-in + email shape), `resolveInviteTargets()` (manager/room/participant-service resolution, returning a degrade descriptor), `sendInvite()` (the display-name fallback + the actual `addUsers()` call). Verified with `vendor/bin/phpmd lib/Service/TalkLinkService.php text phpmd.xml` directly: clean. Not a suppression — the class already carries a pre-existing, documented `@SuppressWarnings(PHPMD.ExcessiveClassComplexity)` from before this PR (defensive Talk API compatibility across the whole class), so extracting methods here didn't need a new one.
- **PHPUnit job (coverage-guard)**: dropped 0.32% (806/1303→824/1339 stmts) — roughly half the new statements (room lookup, participant-service resolution, the actual `addUsers()` call) were structurally unreachable by the file's existing "Talk unavailable" test strategy, since `spreed` isn't installed in the unit-test environment. Fixed by adding `Manager`/`ParticipantService` stubs aliased under Talk's own class names (`OCA\Talk\Manager`, `OCA\Talk\Service\ParticipantService`), following the exact convention `TalkProviderTest` already establishes for this same file's sibling class ("these tests stub them via named classes, no upstream fork"), guarded with `class_exists(...) === false` so a real `spreed` install is never overridden — the same guard `TalkObjectSourceProviderTest::testFailsClosedWhenTalkAbsent()` already uses to self-skip if Talk is genuinely present. Seven new tests now exercise every previously-unreachable branch for real: room-not-found, participant-service-unavailable, the full success path (asserting the actual `addUsers()` call payload), the display-name-defaults-to-email fallback, `addUsers()` throwing, and both `schemaAllowsExternalParticipants()` failure branches (a throwing schema lookup, a non-array configuration). Verified the alias is safe: ran all three files across the codebase that reference these Talk class names together (`TalkLinkServiceTest`, `TalkProviderTest`, `TalkObjectSourceProviderTest`) — 45 tests, 133 assertions, all pass, with the one expected self-skip.

Re-verified after the fix: `php -l` clean · `vendor/bin/phpcs --standard=phpcs.xml` 0 errors · `vendor/bin/phpstan --memory-limit=1G` no errors · `vendor/bin/phpunit` on `TalkLinkServiceTest.php`: 26 tests, 64 assertions, all pass (1 pre-existing skip) · regression check across `TalkLinkServiceTest`/`SchemasControllerTest`/`SchemaMapperTest`/`SchemaTest`: 211 tests, 401 assertions, all pass.

## Not in scope

Resolving who the guardian is or what their email address is (the calling app's job, e.g. learniq's guardian-audience data). Participant removal (Talk's own room management already covers this). What an invited participant can read once inside the room (Talk's own room ACLs, unchanged).

🤖 Generated with [Claude Code](https://claude.com/claude-code)


