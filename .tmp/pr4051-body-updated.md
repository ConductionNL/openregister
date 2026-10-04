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

## Not in scope

Resolving who the guardian is or what their email address is (the calling app's job, e.g. learniq's guardian-audience data). Participant removal (Talk's own room management already covers this). What an invited participant can read once inside the room (Talk's own room ACLs, unchanged).

🤖 Generated with [Claude Code](https://claude.com/claude-code)

