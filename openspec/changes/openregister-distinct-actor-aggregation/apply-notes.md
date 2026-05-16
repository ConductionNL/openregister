# Apply Notes

## Column name: `user` not `user_id`

The spec's SQL uses `user_id` conceptually, but the `openregister_audit_trails` DB table uses `user` (VARCHAR 255). This was confirmed via:

- `lib/Db/AuditTrail.php` property `protected ?string $user = null`
- `lib/Migration/Version1Date20241020231700.php` column definition: `$table->addColumn('user', Types::STRING, ...)`
- The existing `findByActor()` method also uses the `user` column

The implementation uses `user` throughout, consistent with all existing methods.

## Schema ID column: string binding required

Like `getStatisticsGroupedBySchema()`, the `schema` column is VARCHAR 255 even though entity getters return `int`. Schema IDs are cast to string via `array_map('strval', $schemaIds)` and bound with `PARAM_STR_ARRAY`. Using `PARAM_INT_ARRAY` would produce a type mismatch on the `VARCHAR` column.

## Unit test approach: mock IQueryBuilder chain

OR's unit tests do not require a live DB connection. The 6 DB-path tests (REQ-ORDA-001, REQ-ORDA-004, REQ-ORDA-005, REQ-ORDA-006) mock the `IQueryBuilder` chain and feed back a pre-set `distinct_actors` scalar. This follows the pattern in `VectorSearchHandlerTest` and `FileMapperGetFileIdsForObjectsTest`. The 2 input-validation tests (REQ-ORDA-002, REQ-ORDA-003) assert `$this->db->expects($this->never())->method('getQueryBuilder')` — no DB mock needed.

## 9 tests vs 6 spec scenarios

The spec defines 6 scenarios (REQ-ORDA-001..006). Implementation produces 9 test methods because:
- REQ-ORDA-003 splits into 2 (zero hours + negative hours)
- REQ-ORDA-006 splits into 2 (old row excluded + boundary row included)
- REQ-ORDA-001 gets an explicit return-type-is-int test in addition to the happy-path test

## composer check:strict status

- **PHPCS**: Pass (0 errors on both modified files)
- **PHPMD**: Pre-existing warnings on `createAuditTrailEntry` (lines 1570/1577) — `\Symfony\Component\Uid\Uuid` used without import — these predate this change and are not introduced by `getDistinctActorCount`
- **Psalm**: No errors on `lib/Db/AuditTrailMapper.php` (5 info items, all pre-existing)
- **PHPStan**: No errors on `lib/Db/AuditTrailMapper.php` in the new method; 14 pre-existing errors in other methods
- Full `composer check:strict` timed out at 300s on Psalm and PHPStan over the full codebase (pre-existing infrastructure constraint)

## Pre-existing issues not introduced by this change

The PHPMD `MissingImport` warnings for `AuditTrailMapper` (lines 1570/1577 in the committed file) are in the `createAuditTrailEntry` method, which existed before this spec. Not expanded by this change.
