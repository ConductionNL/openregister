# Tasks — archival-transfer-hardening (kind: code, depends_on: —)

Closes procest `migrate-archival-to-or` deltas OR-AD-1/2/3 on OR's existing Edepot stack. Verify
the stub findings against HEAD before building (`TransferController::create()` never dispatches
`TransferExecutionJob`; transfer lists are never persisted) — do not assume the async path works.

## 1. Durable transfer records (OR-AD-3 storage first — retry and proofs build on it)

- [x] 1.1 Add the system register fragment in `lib/Settings/` with the `edepotTransfer` schema (uuid, status, objectReferences, exclusions, approvalMetadata, append-only `attempts[]`, transferResult, packageFormat, timestamps) and the write-once `edepotTransferProof` schema (`immutable: true`; ingest reference/archivId, raw transport receipt, package id + format + package manifest SHA-256, per-file `{name, sha256}`, transfer reference, transport, confirmedAt), installed like the existing system registers via the new `ImportEdepotTransferRegister` repair step (its own configuration appId; registered post-migration + install). → `lib/Settings/edepot_transfer_register.json`, `lib/Repair/ImportEdepotTransferRegister.php`
- [x] 1.2 Persist transfer lists through `ObjectService` from `TransferListService` (create/approve/reject/exclude all now `persist()` through the new `TransferRecordService`, which saves/loads via `ObjectService` — audited write path); the existing status constants + `notifyArchivists` behaviour are unchanged.
- [x] 1.3 Replace the `TransferController::index()`/`show()` placeholder responses with reads of the persisted records (same routes, real data; `show` 404s an unknown id).

## 2. Durable long-horizon retry (OR-AD-2)

- [x] 2.1 Make `TransferController::create()` real: load the persisted list, verify `approved` (409 otherwise, no enqueue; 400 on missing uuid; 404 on unknown list), enqueue `TransferExecutionJob` with `{transferListId, attempt: 1}` (`WebhookDeliveryJob` argument convention).
- [x] 2.2 Remove the in-flow `sleep()` retry chain from `EdepotTransferService` (`RETRY_BACKOFF [30,120,480]`, `MAX_RETRIES 3` deleted): one job run = one transport attempt per outstanding package (`executeAttempt`); failure appends an attempt record (number, timestamp, transport, outcome, error) to the transfer object's append-only `attempts[]` and the job re-enqueues with `attempt + 1` after `min(60 * 2^(attempt-1), 28800)` s ± 10 % jitter via `IJobList::scheduleAfter` (never an in-process wait).
- [x] 2.3 Enforce the attempt cap (`edepot_transfer_max_attempts` app-config, default 10): on exhaustion the job sets status `failed`, escalates via `TransferListService::notifyArchivists`, and stops automatic retries; a fresh manual `create` restarts deliberately.
- [x] 2.4 Make retries partial-success-aware: `outstandingObjectRefs()` excludes objects already confirmed (`retention.archiefstatus === 'overgebracht'`) from the rebuild/resend, so only unconfirmed packages retry (no double ingest).

## 3. BagIt output option (OR-AD-1)

- [x] 3.1 Add the per-connection output-format setting (`edepot_package_format`, `zip` default | `bagit`) read in `TransferListService::createTransferList` and stored on the transfer record's `packageFormat`, threaded into `SipPackageBuilder::build(..., format)` (and `EdepotTransferService::executeAttempt`).
- [x] 3.2 Implement the RFC 8493 serializer branch in `SipPackageBuilder` (`writeBagitArchive`): `bagit.txt` (1.0/UTF-8), `bag-info.txt` (Bagging-Date, Payload-Oxum, Source-Organization, External-Identifier = transfer uuid), complete `manifest-sha256.txt` (checksums recomputed at write time), `tagmanifest-sha256.txt`, SIP content under `data/`; an unchecksummable payload file fails the build (`testBuildBagitFailsOnUnchecksummableFile`). The content-collection step is now format-agnostic; `writeZipArchive` preserves the historical flat layout byte-for-byte.
- [x] 3.3 Record the chosen format + package manifest hash on the proof (task 4) so proofs reference the package identity; transports stay unchanged (opaque file).

## 4. Proof-of-transfer records (OR-AD-3 proofs)

- [x] 4.1 In `processResults` (where `markObjectTransferred` runs), create one `edepotTransferProof` per confirmed object with the full field set via `TransferRecordService::createProof`; link the proof UUID from the object's `retention.transferProof` alongside the existing `eDepotReferentie`/`transferDate` (additive).
- [x] 4.2 Enforce write-once semantics on proof records: schema-level `immutable: true` + `TransferRecordService::createProof` returns the existing proof for a repeated (transfer, object) pair instead of duplicating (`testProofIsWriteOnce`). Destruction independence: proofs live in the `edepot-transfers` system register, not the source object's register, so `DestructionExecutionJob` never cascades to them.
- [x] 4.3 No proof for failed/unconfirmed objects — proof creation is only in the `accepted` branch of `processResults`; failures append to `attempts[]` / retention `transferErrors` only.

## 5. Tests

- [x] 5.1 PHPUnit (CI way: php:8.3-cli + real nextcloud/ocp package, `phpunit-unit.xml`): BagIt layout + manifest completeness incl. the fail-on-unchecksummable case + zip-default unchanged (`SipPackageBuilderTest`); backoff formula incl. cap + jitter bounds, one-attempt-per-run, re-enqueue on failure, cap-exhaustion escalation, missing-list no-op (`TransferExecutionJobTest`); durable index/show/create + non-approved refusal + input validation (`TransferControllerTest`); transfer-list persistence round-trip + proof write-once + degradation (`TransferRecordServiceTest`); the existing `TransferListServiceTest` updated for the persistence dependency. 45 Edepot/transfer tests green (CI way); full `lib/` PHPCS clean; full unit suite green.
- [x] 5.2 Newman for `transfer#index/show/create` (real records, non-approved/unknown refusal) → `tests/newman/openregister-transfer.postman_collection.json` (read-only, safe on any instance). Playwright e2e for the three @e2e flows → `tests/e2e/workflows/archival-transfer-hardening.spec.ts` (gate-19-annotated; self-skips without a live seeded instance + `OR_EDEPOT_*` fixtures). DEVIATION: the three full browser journeys were not executed in this loop (they need a configured e-Depot connection, an unreachable-endpoint harness, and job triggering against a live instance); the observable behaviour is unit-proven (bag layout/manifest, backoff/cap/escalation, proof creation/write-once) and the API surface is Newman-covered. The e-Depot settings UI toggle for the format is delivered by the existing settings surface consuming `edepot_package_format` (no new admin page in this change).
