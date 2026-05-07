## 1. Database migration

- [ ] 1.1 Add a new migration class under `lib/Migration/` (next-available version, e.g. `Version<X>Date<Y>.php`) that adds a nullable JSON column `bases` to `oc_openregister_entity_relations`. Use the platform's existing JSON column type (JSON on MySQL/MariaDB, JSON / JSONB on PostgreSQL — match what other JSON columns in OpenRegister use today, e.g. on `oc_openregister_objects`). Default value: NULL.
- [ ] 1.2 Verify the migration is idempotent: running it twice on the same database is a no-op (Nextcloud's `addColumn` primitive is idempotent — confirm via dry-run or unit test).
- [ ] 1.3 Smoke-test the migration on a populated dev database: confirm existing `EntityRelation` rows are read correctly post-migration and `bases` reads as `null` for all of them.

## 2. EntityRelation entity + mapper

- [ ] 2.1 Add `protected ?array $bases = null;` to `lib/Db/EntityRelation.php`.
- [ ] 2.2 Add `addType(fieldName: 'bases', type: 'json');` in `EntityRelation::__construct()`.
- [ ] 2.3 Add the `getBases(): ?array` and `setBases(?array $bases): void` magic-method docblocks to the class header (mirroring the existing pattern for other fields). The getters/setters are auto-provided by Nextcloud's `Entity` base class once `addType` is registered.
- [ ] 2.4 Update `jsonSerialize()` to include `bases` in the returned array. Order it after `anonymizedValue` for consistency.
- [ ] 2.5 Update the psalm/phpdoc return type on `jsonSerialize` to include `bases`.
- [ ] 2.6 Confirm `EntityRelationMapper` (`lib/Db/EntityRelationMapper.php`) does not need changes — Nextcloud's `QBMapper` automatically handles columns registered via `addType`. If the mapper has any column-list handling that's NOT auto-derived (e.g. manual `select(...)` lists), update those to include `bases`.

## 3. Anonymise endpoint integration

- [ ] 3.1 In `FileService::anonymizeDocument(node, payload)` (or the equivalent service method that the controller routes call), accept an optional `bases` field on each entry in `payload.entities[]`. Validate at the entry point: the field, when present, MUST be either `null` or an array of strings. Reject malformed input with a 400-level error.
- [ ] 3.2 Implement the persist-then-strip order. For each entry in `payload.entities[]`:
  - locate or upsert the `EntityRelation` row for `(entityId, fileId/objectId/chunkId)`,
  - set `anonymized`, `anonymizedValue`, `bases` on the row,
  - persist the row.
  Persistence MUST happen before the OpenAnonymiser HTTP call.
- [ ] 3.3 Construct the OpenAnonymiser request body from a copy of `payload.entities[]` with `bases` removed from every entry. Confirm by inspection that the request body to OpenAnonymiser is byte-equivalent to the pre-change shape (modulo unrelated JSON encoder ordering).
- [ ] 3.4 Confirm the mapper does NOT issue any cross-register lookup to validate the supplied UUIDs. The persistence layer accepts any string array.

## 4. Unit tests

- [ ] 4.1 Add `tests/unit/Db/EntityRelationTest.php` covering: `getBases`/`setBases` round-trip; `jsonSerialize` includes `bases`; null vs empty-array distinction is preserved.
- [ ] 4.2 Add `tests/unit/Db/EntityRelationMapperTest.php` (or extend an existing suite) covering: insert with bases; insert without bases (defaults to null); update bases on existing row; non-UUID strings are accepted; idempotent migration smoke test.
- [ ] 4.3 Add `tests/unit/Service/FileServiceTest.php` cases for the anonymise-endpoint integration: persist precedes OpenAnonymiser call; OpenAnonymiser receives a request body without `bases`; OpenAnonymiser failure preserves the persisted bases on the row.

## 5. Integration tests

- [ ] 5.1 Add a Newman/Postman integration test (or extend the existing collection) for the anonymise endpoint with a `bases`-populated payload. Verify: 200 response; EntityRelation row queried directly returns the correct bases.
- [ ] 5.2 Add an integration test for the no-bases path: pre-change-shape payload still works, returns identical behaviour.

## 6. Cross-app regression check

- [ ] 6.1 Manually run the opencatalogi anonymise flow (if any — opencatalogi is not a known anonymise consumer, but per OR project rules: regression-test it). Confirm no break.
- [ ] 6.2 Smoke-test against DocuDesk's existing anonymise calls (without `bases`). Confirm no break.
- [ ] 6.3 Inspect the OpenAnonymiser request body via debug logging on a single test call. Confirm the body shape is unchanged from pre-change.

## 7. Documentation

- [ ] 7.1 Add an entry to `CHANGELOG.md` under Added describing the new optional `bases` column on `EntityRelation` and the anonymise endpoint's persist+strip behaviour.
- [ ] 7.2 Update the OpenAnonymiser interface contract documentation (if one exists in `docs/`) to note that `bases` is recognised but stripped before the call. If no such doc exists, add a one-line comment on `FileService::anonymizeDocument` referencing the strip behaviour.

## 8. Quality and verification

- [ ] 8.1 Run the full unit test suite — clean.
- [ ] 8.2 Run static analysis (Psalm / PHPStan at the project's configured strictness) — clean.
- [ ] 8.3 Run code style (PHPCS at project config) — clean.
- [ ] 8.4 Manual smoke against a live stack: anonymise call with bases populated, confirm EntityRelation row has bases populated, confirm OpenAnonymiser was not sent the bases.
- [ ] 8.5 Run `openspec validate entity-relation-grondslagen` — clean.
