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

## CI fix pass (2026-09-26)

The GitHub Actions run on this PR carried two NEW findings on top of the inherited composer-audit CVE (fleet-wide, also red on `development`):

- **gate-16 spec-coverage**: `ConsentAnnotationValidator::validate()` was missing an `@spec` tag. Fixed by adding `@spec openspec/changes/consent-evidence-envelope/specs/consent-evidence-envelope/spec.md` to its docblock.
- **PHP Quality (phpmd)**: `ConsentEnvelopeOnSaveListener` — `evaluate()` (CyclomaticComplexity 12>10, NPathComplexity 361>200) and `evaluateProperty()` (CyclomaticComplexity 12>10, NPathComplexity 576>200, 2 ElseExpression, 2 CountInLoopExpression). Fixed by extracting the pure, Nextcloud-independent logic (array normalisation, append-only enforcement, evidence fill) into a new `ConsentEnvelopeEvaluator` service with no event/session coupling — directly unit testable, and small enough that no method or class-level phpmd threshold is close to tripping. The listener itself now only wires the event, resolves the two NC-coupled inputs (acting identity via `IUserSession`, IP/user-agent via `IRequest`), and delegates. Not a suppression: verified with `vendor/bin/phpmd <file> text phpmd.xml` directly, clean on both files after the extraction (the first extraction pass hit a new `ExcessiveClassComplexity` finding on the listener at 51/50 — resolved properly by moving the logic to its own class rather than by adjusting the threshold or suppressing).

Behaviour is unchanged: the existing `ConsentEnvelopeOnSaveListenerTest` suite (16 tests) passes without any modification, proving the refactor is behaviour-preserving. Added `ConsentEnvelopeEvaluatorTest` (8 tests, 27 assertions) covering every branch of the new class directly (grant, withdrawal, caller-forged-evidence-is-overwritten, edit-refused, shorten-refused, append-allowed, non-array inputs).

Re-verified after the fix: `php -l` clean · `vendor/bin/phpcs --standard=phpcs.xml` 0 errors · `vendor/bin/phpstan --memory-limit=1G` no errors · `vendor/bin/phpunit` on the full consent test suite (`ConsentAnnotationValidatorTest`, `ConsentEnvelopeOnSaveListenerTest`, `ConsentEnvelopeEvaluatorTest`): 24 tests, 56 assertions, all pass · gate-16 checker run standalone (`check_spec_coverage.py`): 0 findings.

## Not in scope

Migrating `avg-verwerkingsregister`'s own consent schema onto this primitive is left for that spec's owner. Consent expiry/renewal reminders (`PA-new-3`) and multi-guardian conflict resolution (`PA-new-2`) are app-level concerns built on top of this primitive, not part of it.

🤖 Generated with [Claude Code](https://claude.com/claude-code)


