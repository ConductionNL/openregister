# Tasks: dedupe-shared-schemas

## 1. Detection & attribution

- [x] 1.1 Query building: find every schema id referenced by >1 register (registers.schemas JSON arrays).
      `SchemaAttribution::indexShared()` inverts the register->schemas map in PHP rather than in SQL,
      for the reason `RegisterMapper::getAllRegisterIdsWithSchema()` already documents: the `schemas`
      column is `json` on Postgres and text elsewhere, and SQLite has no REGEXP. Ids stored as strings
      are normalised, so `"161"` and `161` count as the same reference.
- [x] 1.2 Canonical-owner attribution: match each referencing register's app configuration
      (register.json / register.d fragments, resolved the same way SettingsService merges them)
      against the current schema entity content; classify per schema: exactly-one-match /
      no-match / multi-match.
      `RegisterConfigurationLocator` mirrors `AppHostSettingsService::resolveRegisterConfiguration()`
      (base `<appId>_register.json` + sorted `register.d/*.json` deep-merged). The glob is widened to
      every `*_register.json` because OpenRegister ships several documents rather than one monolith.
      Comparison is on the property-NAME set + `required`, not byte equality — the import path stamps
      defaults and folds `$ref`s, so byte equality would put every schema in `no-match`.
- [x] 1.3 `--keep <registerId>` override for no-match / multi-match schemas; refuse `--write`
      for unattributed shared schemas without it.
      Both forms: `--keep <schemaId>:<registerId>` (repeatable) and a bare `--keep <registerId>`
      covering everything unattributed. Per-schema outranks bare; a `--keep` naming a register that
      does not reference the schema is ignored rather than honoured.

## 2. Split & relink

- [x] 2.1 Clone path A (preferred): create the non-canonical register's schema from its OWN app
      configuration definition, reusing the ImportHandler create path so the per-register
      slug-uniqueness behaviour applies.
      `ImportHandler::importSchema(..., registerSchemaIds: [])` — the empty scope makes
      `findBySlugInIds()` short-circuit, forcing a brand new row instead of resolving back onto the
      shared one.
- [x] 2.2 Clone path B (fallback, no configuration available): copy the current entity content
      into a new schema row. `SchemaMapper::createFromArray()` on the serialised entity minus
      id/uuid/uri/timestamps, re-stamped with the register's application.
- [x] 2.3 Rewrite register.schemas linkage old id → new id, preserving order.
      `SchemaAttribution::replaceSchemaId()` — in place, and non-numeric legacy entries are copied
      verbatim rather than normalised away.

## 3. Data migration

- [x] 3.1 Move rows `table_{reg}_{oldId}` → `table_{reg}_{newId}` (create target table via the
      magic-table DDL for the restored definition; INSERT-SELECT with column mapping).
      Target created by `MagicMapper::ensureTableForRegisterSchema()`. `_id` is excluded from the
      copy: it is autoincrement, so copying the values would strand the target's sequence behind the
      highest copied id and the next insert would collide. `_uuid` — the identity relations store —
      does move.
- [x] 3.2 Report source columns without a destination; never drop silently. `--strict`
      turns unmapped columns into a refusal.
      Unmapped columns are computed at PLAN time (a transient unpersisted `Schema` is fed to the real
      `buildTableColumnsFromSchema()`), so the dry run names them and `--strict` refuses BEFORE
      anything is written — on MySQL a post-hoc refusal would strand the created table, since DDL
      there does not roll back with the transaction. The source table is renamed to `_predupe` rather
      than dropped, so an unmapped column stays recoverable. That suffix also stops the table matching
      the shard pattern `relink-schemas` reads as evidence — left as-is, the sibling command would
      re-link the register to the schema this one just split it away from.
- [x] 3.3 Update object rows' `_schema` metadata and any denormalised schema references
      (folders, uri) the codebase keeps.
      `_schema` and the schema id embedded in `_uri` are both rewritten. `_folder` is deliberately NOT
      touched: it holds a Nextcloud folder node id and object folders are created inside the REGISTER
      folder (`FolderManagementHandler::createObjectFolderInRegister()`), so folders are
      register-scoped and a schema renumber does not invalidate them.

## 4. Command surface & safety

- [x] 4.1 `occ openregister:registers:dedupe-shared-schemas` — dry-run default, `--write`,
      `--register`, `--keep`, `--strict`; output format mirroring relink-schemas.
- [x] 4.2 Must-PASS control: instance with a shared schema pair → dry-run lists it, `--write`
      splits it, app reimport after the split does NOT re-share (regression guard on the
      ImportHandler fix).
      `SchemaAttributionTest::testDetectsAndAttributesTheObservedSharedPair()` (the real 19/16 ×
      74/159/161 shape) and `DedupeSharedSchemasCommandTest::testWriteAppliesTheSplit()`.
      **Partial:** the "app reimport does not re-share" leg is covered structurally — the split goes
      through `importSchema(registerSchemaIds: [])`, whose behaviour is already locked by
      `ImportHandlerPerRegisterSlugUniquenessTest` — not by an end-to-end reimport, which needs a
      booted Nextcloud. See the PR body.
- [x] 4.3 Must-FAIL control: healthy instance → dry-run reports nothing and `--write` changes
      nothing (idempotence: second run is a no-op).
      `SchemaAttributionTest::testHealthyInstanceHasNothingToRepair()`,
      `testSecondRunAfterASplitIsANoOp()` (feeds the post-split ids back in) and
      `DedupeSharedSchemasCommandTest::testHealthyInstanceReportsNothing()`, which asserts
      `applySplit()` is never called.
- [x] 4.4 Unit tests for attribution matrix (one-match / no-match / multi-match) and column
      mapping edge cases.
      33 tests. The generated `INSERT ... SELECT` is additionally EXECUTED against a real in-memory
      SQLite database, so the statement is proven to parse and to move exactly the mapped columns
      rather than merely string-matching what the test author expected. All four decision rules were
      mutation-checked: breaking the sharing threshold, letting multi-match guess an owner, dropping
      the unmapped-column report, and disabling the `--write` refusal each fail the suite.

## 5. Docs

- [x] 5.1 Admin docs page next to relink-schemas: when drift happens, how to read the dry-run,
      the planix/pipelinq case as the worked example (openregister#2689).
      `docs/Technical/repairing-shared-schemas.md`, linked from `docs/api/schemas.md` beside the
      existing `relink-schemas` pointer.
