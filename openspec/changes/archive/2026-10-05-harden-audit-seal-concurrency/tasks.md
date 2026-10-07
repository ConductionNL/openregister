# Tasks — harden-audit-seal-concurrency

## 1. Advisory lock around seal passes (openregister#406)

- [x] 1.1 Add exclusive advisory lock (`OCP\Lock\ILockingProvider`, well-known key `openregister/audit-seal`) taken by BOTH `sealRow()` and `sealRows()` around the whole critical section (predecessor read + hash writes) — `lib/Service/AuditHashService.php` (`acquireSealLock()` / `releaseSealLock()`, bodies extracted to `sealRowLocked()` / `sealRowsLocked()`).
- [x] 1.2 Bounded, fail-soft acquisition: 3 attempts 50ms apart; on sustained contention log a warning and leave rows unsealed (later seal pass chains them) instead of blocking the write path. Lock released in `finally`, including when the seal pass throws.
- [x] 1.3 Fix `getHashBefore()` to return the nearest PRIOR **SEALED** row's hash (filter `hash IS NULL` / `hash = ''`), mirroring `verifyChain()`'s skip-null walk — kills the permanent false break when the immediately-prior row is an unsealed fail-soft leftover.
- [x] 1.4 Tests — `tests/Unit/Service/AuditHashSealRowsTest.php`: lock acquire/release around `sealRows()` (exact key + exclusive type), lock-unavailable fail-soft for `sealRow()` (3 attempts, warning, zero DB access, no release) and `sealRows()`, release-on-throw, retry-after-transient-conflict, no lock touch for empty input; `testSealChainsFromNearestSealedPredecessorAndVerifies` proves "row N unsealed, row N+1 sealed" chains onto the nearest sealed hash (predecessor query filtered via `isNotNull('hash')` + `neq('hash', '')`) and `verifyChain()` reports `valid: true` with `skippedNullHashes: 1`.
- [x] 1.5 Existing audit-chain suites stay green: AuditHashServiceTest, AuditHashSealRowsTest, AuditHashChainConsistencyTest, AuditTrailMapperBulkInsertTest, AuditTrailMapperBulkTest (constructor wiring updated to the new `ILockingProvider` + `LoggerInterface` dependencies).

## 2. Remove dead ChunkProcessingHandler (openregister#407)

- [x] 2.1 Verify zero callers: `grep -rn ChunkProcessingHandler lib/ appinfo/` matches only the class file itself; no DI registration (autowire-only); `SaveObjects` chunks via its own internal `processObjectsChunk()`.
- [x] 2.2 Delete `lib/Service/Object/SaveObjects/ChunkProcessingHandler.php` and `tests/Unit/Service/Object/SaveObjects/ChunkProcessingHandlerTest.php`.
- [x] 2.3 Remove the ChunkProcessingHandler property, container resolution, and its five `processObjectsChunk` test methods from `tests/Service/SaveObjectHandlersIntegrationTest.php`.
- [x] 2.4 Spec references corrected — bulk chunking is `SaveObjects`' internal `processObjectsChunk()`, not a separate handler. `object-lifecycle` REQ-004, `data-import-export` prose, and the `method-decomposition` file list were edited in the canonical specs directly: their `### REQ-NNN:` heading style is not parseable by the openspec delta format (`### Requirement:`), so a MODIFIED delta cannot target them; the edits are implementation-reference corrections, not behaviour changes.

## 3. Verification

- [x] 3.1 `composer install`; phpcs/phpmd/phpstan/psalm clean on touched files (php 8.3 container).
- [x] 3.2 Full `tests/Unit/Service/AuditHash*` + `tests/Unit/Db` suites green in the php 8.3 phpunit container.
- [x] 3.3 `openspec validate harden-audit-seal-concurrency --type change --strict` passes.
