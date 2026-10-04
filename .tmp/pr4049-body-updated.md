## Scope

Adds `x-openregister-consent`, a declarative, append-only, evidentiary consent shape any schema can attach to an array property, filled by the platform on write. Closes learniq round-1 finding `PA-new-7` (legally evidenced consent: account id, timestamp, IP, hash, evidenced under BW 3:15a) as a fleet primitive rather than a per-app build.

Ground truth checked before building: `avg-verwerkingsregister` (status: implemented) already models a consent-as-legal-basis record for Art 6/7 processing, but it is a bespoke schema tied to `verwerkingsactiviteit` with no IP, user agent or content hash, and it is not a reusable dialect. This change adds the lower-level, generic primitive without touching that spec's requirements; a future change can migrate it onto this primitive if its owner chooses to.

## What changed

- `lib/Service/Consent/ConsentAnnotationValidator.php` + `ConsentDeclarationException.php`: schema-save validation for `x-openregister-consent: {purpose, subjectProperty?}`, mirroring `CalculationAnnotationValidator`/`CalculationDeclarationException`. Wired into `SchemaMapper::cleanObject()` and `SchemasController`'s three existing declaration-exception catch blocks.
- `lib/Listener/ConsentEnvelopeOnSaveListener.php`: subscribes to `ObjectCreatingEvent`/`ObjectUpdatingEvent` (registered in `Application.php` alongside `CalculationOnSaveListener`). Fills `by`, `timestamp`, `ip`, `userAgent`, `contentHash`, `withdrawnAt` on every newly appended array entry; refuses (via `setErrors()`+`stopPropagation()`, the same idiom `UniqueConstraintListener` uses) any write that mutates or shortens an already-persisted entry.

## Verified

- `php -l` on all 6 touched/added PHP files: clean.
- `vendor/bin/phpcs --standard=phpcs.xml` on the touched lib files: 0 errors (exit 0).
- `vendor/bin/phpstan analyse --memory-limit=1G` on the touched lib files: no errors (exit 0).
- `vendor/bin/phpunit` on the two new test files (`ConsentAnnotationValidatorTest`, `ConsentEnvelopeOnSaveListenerTest`): 16 tests, 29 assertions, all pass.
- `openspec validate consent-evidence-envelope --strict`: valid.
- `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (full suite, run once via the lane semaphore, `with-slot.sh`): exit 1 (`SOME CHECKS FAILED`) from `test:all` only (lint, check:migration-version, phpcs, phpmd, psalm, phpstan all passed). 24037 tests, 61133 assertions, 1 failure, not in a file this PR touches: `MigrationVersionBumpCheckTest::testANonRepositoryRefusesToGiveAVerdict` (asserts an isolated, freshly-created temp dir's migration-gate verdict code, unrelated to consent/Schema). The same failure reproduced identically on the sibling PR #4051's run, confirming it is fleet-wide pre-existing debt, not caused by either PR.

## Inherited findings

One full-suite failure surfaced by `composer check:strict`, pre-existing and unrelated to this PR's diff (see Verified above): `MigrationVersionBumpCheckTest::testANonRepositoryRefusesToGiveAVerdict`. Reproduced identically on sibling PR #4051, confirming it is fleet-wide, not caused by this change. Not fixed here per the inherited-debt policy.

## Not in scope

Migrating `avg-verwerkingsregister`'s own consent schema onto this primitive is left for that spec's owner. Consent expiry/renewal reminders (`PA-new-3`) and multi-guardian conflict resolution (`PA-new-2`) are app-level concerns built on top of this primitive, not part of it.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

