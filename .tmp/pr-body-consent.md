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
- `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (full suite, run once via the lane semaphore): PLACEHOLDER_CHECK_STRICT_RESULT

## Inherited findings

None touched by this change (all new files, plus one additive method call each in `SchemaMapper.php` and `SchemasController.php`).

## Not in scope

Migrating `avg-verwerkingsregister`'s own consent schema onto this primitive is left for that spec's owner. Consent expiry/renewal reminders (`PA-new-3`) and multi-guardian conflict resolution (`PA-new-2`) are app-level concerns built on top of this primitive, not part of it.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
