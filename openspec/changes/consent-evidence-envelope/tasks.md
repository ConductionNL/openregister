## 1. Grammar validation

- [ ] 1.1 Add `lib/Service/Consent/ConsentDeclarationException.php` (mirrors `CalculationDeclarationException`'s shape: carries per-error `{code, message}` rows, mapped to HTTP 422).
- [ ] 1.2 Add `lib/Service/Consent/ConsentAnnotationValidator.php` (mirrors `CalculationAnnotationValidator`'s shape) validating `x-openregister-consent: {purpose: string, subjectProperty?: string}` on a `type: array` property; verify with `tests/Unit/Service/Consent/ConsentAnnotationValidatorTest.php` covering: valid declaration passes, non-array property rejected, missing `purpose` rejected.
- [ ] 1.3 Wire the validator into `lib/Db/SchemaMapper.php` alongside the existing `NotificationAnnotationValidator`/`CalculationAnnotationValidator` calls, throwing `ConsentDeclarationException` on error; add the matching `catch (ConsentDeclarationException $e)` → HTTP 422 block in `lib/Controller/SchemasController.php` next to the existing `CalculationDeclarationException` catches; verify with a focused case in `tests/Unit/Db/SchemaMapperTest.php` or `tests/Unit/Controller/SchemasControllerTest.php`.

## 2. Evidence capture and append-only enforcement

- [ ] 2.1 Add `lib/Listener/ConsentEnvelopeOnSaveListener.php` implementing `IEventListener` for `ObjectCreatingEvent`/`ObjectUpdatingEvent` (mirrors `UniqueConstraintListener`'s `handle()` dispatch shape); for each `x-openregister-consent` property on the schema, resolve the previously persisted array (empty on create, `getOldObject()`'s value on update) and the incoming array.
- [ ] 2.2 Implement append-only diffing: any existing index whose incoming value differs from the persisted value calls `$event->setErrors([...])` + `$event->stopPropagation()` (the existing refusal idiom, becomes `HookStoppedException` → HTTP 422 via `MagicMapper`/`ObjectsController`, no new exception class); a shorter incoming array (dropped entries) is refused the same way; verify with unit tests covering edit-refused, shorten-refused, and append-allowed scenarios.
- [ ] 2.3 Implement evidence fill for each newly appended entry, written back via `$object->setObject([...])` (mutates the live entity directly, same idiom `CalculationOnSaveListener` uses): `by` (acting user id, or the resolved `subjectProperty` identity), `timestamp` (server clock RFC3339), `ip` (`IRequest::getRemoteAddress()`, null when unavailable), `userAgent` (`IRequest::getHeader('User-Agent')`, null when unavailable), `contentHash` (`hash('sha256', purpose . decision . evidenceOf)`); caller-supplied values for these five keys MUST be discarded and replaced; verify with a unit test asserting a caller-supplied forged `timestamp`/`ip` is overwritten.
- [ ] 2.4 Implement `withdrawnAt` fill: only set (equal to the entry's own `timestamp`) when the appended entry's `decision` is `withdrawn`; otherwise `null`; verify with a unit test.
- [ ] 2.5 Register `ConsentEnvelopeOnSaveListener` for `ObjectCreatingEvent` and `ObjectUpdatingEvent` in `lib/AppInfo/Application.php`, alongside `CalculationOnSaveListener`'s registration; verify by asserting the listener fires in an integration-style unit test that creates then updates an object with a consent-shaped property.

## 3. Documentation and spec sync

- [ ] 3.1 Confirm `openspec validate consent-evidence-envelope --strict` passes with zero errors.
- [ ] 3.2 Run the diff-scoped gates (`php -l`, phpcs, phpstan, phpunit --filter) on every touched file and record exit codes in the PR body; report any inherited (pre-existing, non-touched-line) finding in one sentence rather than fixing it.
