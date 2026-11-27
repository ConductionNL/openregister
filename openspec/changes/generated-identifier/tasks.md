# Tasks: generated-identifier

## 1. Storage and declaration

- [x] 1.1 `openregister_sequences` migration with the unique key.
- [x] 1.2 Validate `x-openregister-generated` at schema save (string
      property, known placeholders, `resetOn` in the vocabulary).

## 2. Generation

- [x] 2.1 Listener on `ObjectCreatingEvent` filling an empty property under
      the lock, on both databases.
- [x] 2.2 Update guard refusing a change to a generated property.
- [x] 2.3 Import keeps a supplied value and advances the counter.

## 3. Tests

- [x] 3.1 Unit tests for the format renderer, the guard and the import
      advance; a concurrency test creating fifty objects in parallel.
- [x] 3.2 `tests/e2e/api-direct/generated-identifier.spec.ts`: create two
      objects, read consecutive identifiers, try to edit one and get 422.

## Discovery cluster 41

- [x] C41.1 A random sequence kind with a declared length and alphabet, under the existing allocation lock (D-C41-1). (`kind: random`, `length`, `alphabet` on the annotation; the counter number is shown through a keyed permutation, `RandomSequenceCode`, keyed by the instance secret and the sequence name, so uniqueness is the counter's own. Unit tests: `RandomSequenceCodeTest`, `GeneratedIdentifierRandomDeclarationTest`, `GeneratedIdentifierRandomTest`.)
- [x] C41.2 Foreign identifiers on an object, each naming its issuer, indexed and exactly resolvable (D-C41-2). (`openregister_foreign_ids`, unique on issuer and value and indexed by object, `Version1Date20261114141600`; `ForeignIdentifierMapper`; `ForeignIdentifierRegistry::record()`, `resolve()` and `forObject()`, a concurrent duplicate decided by the unique index. Unit tests: `ForeignIdentifierRegistryTest`, `ForeignIdentifierMapperGoldenTest`, `Version1Date20261114141600Test`.)
- [x] C41.3 A second generated identifier beside the first, with its own sequence and format.
- [x] C41.4 Reserved values and patterns that no sequence issues.
- [x] C41.5 A scheme change as a background job with progress, keeping each previous value as a foreign identifier of this instance (D-C41-3). (`IdentifierSchemeMigrationService::start()` stores a run in `openregister_id_scheme_runs` (`Version1Date20261114181600`) and queues `IdentifierSchemeMigrationJob`, which rewrites a batch per run: each value read back through the previous format, the same number rendered in the current one, the old value recorded under `openregister:<instanceid>` through `ForeignIdentifierRegistry`, the object saved inside `IdentityRewriteScope` (which the freeze guard now honours) and the counter advanced past it. Progress: total, processed, rewritten, skipped, failed and the last error, read at `GET /api/identifier-migrations/{run}`; started at `POST /api/schemas/{id}/identifier-migrations`, administrators only. Counter formats only: a random code carries no number to rewrite.)
- [x] C41.6 A second migration of the same sequence is refused, naming the running one (D-C41-4). (`active_sequence` holds the sequence while a run runs, under a unique index, so a concurrent start loses at the database; `IdentifierSchemeMigrationRunningException` names the run, schema, property and who started it; the API answers 409 with the running run. A finished or failed run frees the sequence.)
- [x] C41.7 Tests: random uniqueness under concurrency, the two-issuer lookup, the reserved skip, the old number still resolving, the refused second migration. (`GeneratedIdentifierRandomTest::testInterleavedWorkersOnOneCounterNeverShareACode`, `ForeignIdentifierRegistryTest::testTheSameNumberFromTwoSendersIsNotAmbiguous`, `GeneratedIdentifierReservedTest::testReservedValuesAreSkipped`, `IdentifierSchemeMigrationServiceTest` (old value resolves after the rewrite, second migration refused, concurrent start loses the unique index), `IdentifierSchemeMigrationJobTest`, `IdentifierSchemeMigrationsControllerTest`, `IdentifierSchemeRunMapperGoldenTest`, `IdentifierSchemeRunTest`, `Version1Date20261114181600Test`, `GeneratedIdentifierListenerTest::testTheSchemeMigrationMayRewriteTheIdentifier`.)
- [ ] C41.8 Hand over to the dossiq lane for the mask per case type and to the integriq lane for the ZGW and StUF correlation, with candidate ids C-case-core-20, -28, -30, -39 and C-configuration-61.
