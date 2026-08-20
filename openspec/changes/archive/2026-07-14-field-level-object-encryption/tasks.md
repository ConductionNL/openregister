# Tasks — Field-level object encryption

Scope note: this change introduces a new schema property flag and its
enforcement, plus one new CLI command. It introduces no new OpenRegister
registers/schemas (no seed-data task) and no lifecycle/aggregation/
notification/widget behaviour (ADR-031 declarative-vs-imperative guidance
does not apply here).

## 1. Core encryption primitive

- [x] 1.1 `lib/Service/FieldEncryptionHandler.php`: envelope format
  (`openregister:enc:v1:`), `encryptValue()`/`decryptValue()`,
  `isEnvelope()`, `encryptProperties()`/`decryptProperties()` operating on a
  `Schema`'s `x-openregister-encrypted` properties. Reuses `OCP\Security\ICrypto`
  — no new crypto.
- [x] 1.2 `lib/Exception/FieldDecryptionException.php` — never swallowed;
  thrown by `decryptValue()` on a non-envelope value or an `ICrypto::decrypt()`
  failure.
- [x] 1.3 `lib/Db/Schema.php`: `hasEncryptedProperties()`/`getEncryptedProperties()`,
  mirroring the existing `hasWriteOnlyProperties()`/`getWriteOnlyProperties()` pattern.

## 2. Save path

- [x] 2.1 Wire `FieldEncryptionHandler` into `SaveObject` (nullable trailing
  constructor param — additive, no existing call site breaks) and call
  `encryptProperties()` as the last step of `prepareObjectData()`, after
  cascading/defaults/computed-fields.

## 3. Read path — compose with RBAC/writeOnly redaction

- [x] 3.1 Wire `FieldEncryptionHandler` into `RenderObject` (nullable trailing
  constructor param) and call `decryptProperties()` in `renderEntity()`
  strictly after the existing writeOnly/property-authorization strip block,
  before translation resolution.
- [x] 3.2 Same composition in the cheap list-render path
  `redactWriteOnlyFromRows()` (both the `ObjectEntity` and array-row
  branches), after the existing `filterReadableProperties()`/
  `stripWriteOnlyProperties()` calls. Extend `schemaNeedsReadStrip()` to also
  gate on `hasEncryptedProperties()` so an encrypted-only schema (no
  writeOnly/authz) is not skipped by the method's early-exit.
- [x] 3.3 Document (design.md) the `_rbac: false` / `SystemOperationContext`
  bypass asymmetry between the two paths as a known, deliberate scope
  boundary rather than silently leaving it inconsistent.

## 4. Search / facet exclusion

- [x] 4.1 `MagicMapper::buildTableColumnsFromSchema()`: skip a property
  flagged `x-openregister-encrypted: true` — no dedicated magic-table column.
- [x] 4.2 `lib/Exception/EncryptedFieldFilterException.php` +
  `lib/Middleware/EncryptedFieldFilterMiddleware.php` (registered in
  `Application.php`) — maps the exception to HTTP 400.
- [x] 4.3 `MagicSearchHandler::applyObjectFilters()`: throw
  `EncryptedFieldFilterException` when a filter targets a flagged property,
  before any SQL condition is built.
- [x] 4.4 Audit `MagicMapper`'s existing try/catch wrappers around the
  single-schema search/count path and the multi-schema count path so the new
  exception is explicitly rethrown rather than silently absorbed into a
  warning-and-empty-result. Document (design.md) the one fan-out path
  (`searchAcrossMultipleTables()`) not individually audited, as a known
  follow-up.
- [x] 4.5 `MagicSearchHandler::applyFullTextSearch()`: skip a flagged
  property in the `LIKE`-based scan.
- [x] 4.6 `FacetHandler::getFacetableFieldsFromSchemas()`: exclude a flagged
  property from the facetable-fields listing regardless of its own
  `facetable` setting.

## 5. Migration / rollout

- [x] 5.1 `lib/Command/EncryptFieldCommand.php` (`openregister:encrypt-field
  --property=<name> [--register=] [--schema=] [--dry-run]`), modelled on
  `BackfillSystemOwnerCommand`: encrypts existing plaintext values of a newly
  flagged property, row-by-row, idempotently. Registered in `appinfo/info.xml`.
- [x] 5.2 Best-effort null-out of a same-named legacy dedicated magic-table
  column, if one exists from before the property was flagged (swallows only
  the "column doesn't exist" case — the expected steady-state going forward).
- [x] 5.3 A non-zero per-row failure count fails the whole command
  (non-zero exit code) even though already-succeeded rows are kept.

## 6. Tests

- [x] 6.1 `FieldEncryptionHandlerTest`: envelope round trip, idempotency,
  non-string/absent/null/empty handling, decryption-failure error marker vs
  `throwOnFailure`, the "never decrypt an absent key" composition guarantee.
- [x] 6.2 `SchemaEncryptedPropertiesTest`: flag detection mirrors the
  `writeOnly` convention (exact boolean `true`, non-array config ignored).
- [x] 6.3 `SaveObjectFieldEncryptionTest`: `prepareObjectData()` encrypts a
  flagged field, leaves an unflagged field untouched, is idempotent on resave.
- [x] 6.4 `RenderObjectFieldEncryptionTest`: authorized read decrypts;
  unauthorized (property-authorization-denied) read never sees ciphertext or
  plaintext; corrupted envelope surfaces the structured error marker, not
  silent loss; both `redactWriteOnlyFromRows()` row shapes (ObjectEntity and
  array) decrypt correctly; the documented `_rbac: false` bypass behaves as
  specified.
- [x] 6.5 `MagicSearchHandlerEncryptedFilterTest`: filtering on an encrypted
  property throws with the property name attached; an unrelated
  unknown-field filter is unaffected (still hits the pre-existing
  ignored-filter path).
- [x] 6.6 `EncryptFieldCommandTest`: `--property` required; a schema not
  flagging the property is skipped entirely; full encrypt round trip persists
  an envelope; `--dry-run` writes nothing; a second run is idempotent
  (encrypts zero on re-run).

## 7. Quality gates

- [x] 7.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) clean on
  changed files — baseline-only pre-existing violations, zero new. PHPCS 0
  errors on all changed `lib/` files; PHPMD's 6 findings are all in untouched
  methods (`cascadeObjects`, `redactWriteOnlyFromRows` signature/guard — proven
  by diff, not my hunks); PHPStan clean (2 `SchemaMapper::findAll` param entries
  added to `phpstan-baseline.neon`, mirroring the existing `BackfillSystemOwnerCommand`
  entries); Psalm clean.
- [x] 7.2 PHPUnit: 41 new tests green (69 assertions). Full touched-dir suites
  diffed against a pristine `origin/development` baseline worktree — **byte-identical
  failure set** (4 errors + 3 failures, same test names: RelationHandler x2,
  PermissionHandlerCustomScope x2, RenderObjectWriteOnlyRedaction, all pre-existing
  red-base), **zero new failures**. Db/Command dirs: 1265 tests, 0 failures.
- [x] 7.3 `openspec validate field-level-object-encryption --strict` passes.

## Acceptance criteria

- A schema property flagged `x-openregister-encrypted: true` is encrypted at
  rest and transparently decrypted for authorized reads; an unauthorized read
  never receives ciphertext or plaintext.
- Filtering on an encrypted property returns HTTP 400 with a structured body,
  never a silent empty result.
- `openregister:encrypt-field` migrates existing plaintext data idempotently,
  with a working `--dry-run`.
- Decryption failure is always visible (structured error marker + ERROR log,
  or a thrown exception) — never a silent `null`/empty substitution.
- No behaviour change for any schema that does not use the new flag.

## Quality reminders

- Do not use sed/awk/scripting to modify code files; use real edits.
- Fix pre-existing quality issues encountered along the way rather than
  leaving them.
- No PR, merge or release steps belong in this list.
