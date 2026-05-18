## 1. Database migration

- [ ] 1.1 Add a new `lib/Migration/Version<X>Date<Y>.php` that adds a nullable JSON column `bases` to `oc_openregister_entity_relations` (match the JSON column type used by other OR JSON columns, e.g. `oc_openregister_objects`). Verify idempotency (Nextcloud `addColumn` is idempotent) and smoke-test on a populated dev database so pre-existing rows read `bases` as `null`.

## 2. EntityRelation entity + mapper

- [ ] 2.1 Add `protected ?array $bases = null;` to `lib/Db/EntityRelation.php`, register it via `addType(fieldName: 'bases', type: 'json')` in the constructor, update the class-header magic-method docblocks (`getBases()`/`setBases()`), and include `bases` in `jsonSerialize()` after `anonymizedValue` (also updating the psalm/phpdoc return type).
- [ ] 2.2 Confirm `EntityRelationMapper` requires no changes (QBMapper auto-handles `addType` columns); if any manual `select(...)` lists exist, extend them to include `bases`.

## 3. Anonymise endpoint integration

- [ ] 3.1 In `FileService::anonymizeDocument(node, payload)` (or the controller-equivalent), accept an optional `bases` field per entry in `payload.entities[]`. Validate shape only at the entry point: `null` OR a string array; reject malformed shape with HTTP 400 naming the offending entity index. The mapper is intentionally content-agnostic (no UUID validation).
- [ ] 3.2 Implement persist-then-strip ordering: for each entry, locate/upsert the `EntityRelation` row, apply retry-omit semantics (3.4), set `anonymized` / `anonymizedValue` / `bases`, persist BEFORE the OpenAnonymiser HTTP call. Construct the OpenAnonymiser request body from a copy of `payload.entities[]` with `bases` removed — outgoing body MUST be byte-equivalent to the pre-change shape.
- [ ] 3.3 Wire `bases` mutations through OpenRegister's existing immutable audit-trail subsystem (the same path used for other `EntityRelation` mutations — grep `EntityRelationMapper` + audit-trail wiring). Every set/update of `bases` MUST emit an audit entry with `previousBases`, `newBases`, acting user UID (NOT display name, per ADR-005), timestamp, and row identifier. Reads MUST NOT audit. Reference ADR-022.
- [ ] 3.4 Implement retry-omit semantics distinguishing three caller intents: field **absent** → reuse persisted value, no audit entry; field present and `null` → set to `null` (explicit clear, audit-logged); field present and `[]` → set to empty array (audit-logged).
- [ ] 3.5 Confirm the anonymise endpoint's existing per-object write-access check is the **only** auth on the `bases` write path — no extra group/role check is added. Cross-reference ADR-005 + ADR-023 in the PR description so the absence is intentional.

## 4. Unit tests

- [ ] 4.1 Add `tests/unit/Db/EntityRelationTest.php` + `EntityRelationMapperTest.php` covering: `getBases`/`setBases` round-trip; `jsonSerialize` includes `bases`; null-vs-empty-array distinction preserved; insert with/without bases; update bases on existing row; non-UUID strings accepted; idempotent migration smoke.
- [ ] 4.2 Add `tests/unit/Service/FileServiceTest.php` + `FileServiceShapeValidationTest.php` covering the endpoint integration: persist precedes OpenAnonymiser call; OpenAnonymiser receives a request body without `bases`; OpenAnonymiser failure preserves persisted bases; rejects `bases: "string"` (400); rejects `bases: ["uuid", 42]` (400); accepts `bases: ["any", "strings"]`; 400 error body identifies the offending entity index.
- [ ] 4.3 Add `tests/unit/Service/FileServiceAuditTrailTest.php` + `FileServiceRetryTest.php` + `FileServiceAuthorizationTest.php` covering: first-time set emits audit entry with `previousBases: null` + `newBases: <array>`; update audits old + new; read does NOT audit; UID not display name (ADR-005); retry-omit reuses persisted bases without audit-logging the unchanged field; the three caller intents (absent / present-null / present-empty-array) distinguished correctly; HTTP 403 + no-persist when caller lacks write-access; arbitrary strings accepted with write-access.

## 5. Integration tests + cross-app regression

- [ ] 5.1 Add a Newman/Postman integration test (or extend the existing collection) for the anonymise endpoint covering: `bases`-populated payload returns 200 and direct DB query shows correct bases; pre-change-shape payload (no `bases`) still works identically.
- [ ] 5.2 Cross-app regression: smoke-test DocuDesk's existing anonymise calls (without `bases`) and confirm no break; inspect the OpenAnonymiser request body via debug logging on a single test call and confirm the body shape is unchanged from pre-change. (opencatalogi is not a known anonymise consumer, but log negative confirmation.)

## 6. Documentation + verification

- [ ] 6.1 Add a `CHANGELOG.md` entry under Added describing the new optional `bases` column on `EntityRelation` and the anonymise endpoint's persist+strip behaviour. Update the OpenAnonymiser interface contract doc (or add a one-line comment on `FileService::anonymizeDocument`) noting that `bases` is recognised but stripped before the call.
- [ ] 6.2 Run `composer check:strict` (PHPUnit + Psalm/PHPStan + PHPCS) and `openspec validate entity-relation-grondslagen` — all clean. Final manual smoke against a live stack: anonymise call with `bases` populated, confirm EntityRelation row has bases populated and OpenAnonymiser was NOT sent `bases`.
