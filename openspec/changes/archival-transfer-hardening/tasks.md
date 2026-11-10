# Tasks — archival-transfer-hardening (kind: code, depends_on: —)

Closes procest `migrate-archival-to-or` deltas OR-AD-1/2/3 on OR's existing Edepot stack. Verify
the stub findings against HEAD before building (`TransferController::create()` never dispatches
`TransferExecutionJob`; transfer lists are never persisted) — do not assume the async path works.

## 1. Durable transfer records (OR-AD-3 storage first — retry and proofs build on it)

- [ ] 1.1 Add the system register fragment in `lib/Settings/` with the `edepotTransfer` schema (uuid, status, objectReferences, exclusions, approvalMetadata, append-only `attempts[]`, transferResult, timestamps) and the write-once `edepotTransferProof` schema (ingest reference/archivId, raw transport receipt, package id + format + package manifest SHA-256, per-file `{name, sha256}`, transfer reference, transport, confirmedAt), installed like the existing system registers.
- [ ] 1.2 Persist transfer lists through `ObjectService` from `TransferListService` (create/approve/reject/exclude/status transitions all audited writes); keep the existing status constants and `notifyArchivists` behaviour.
- [ ] 1.3 Replace the `TransferController::index()`/`show()` placeholder responses with reads of the persisted records (same routes, real data).

## 2. Durable long-horizon retry (OR-AD-2)

- [ ] 2.1 Make `TransferController::create()` real: load the persisted list, verify `approved` (client error otherwise, no enqueue), enqueue `TransferExecutionJob` with `{transferListId, attempt: 1}` (`WebhookDeliveryJob` argument convention), persist the dispatch on the record.
- [ ] 2.2 Remove the in-flow `sleep()` retry chain from `EdepotTransferService::sendWithRetry` (`RETRY_BACKOFF [30,120,480]`, `MAX_RETRIES 3`): one job run = one transport attempt per outstanding package; failure appends an attempt record (attempt no., timestamp, transport, per-package outcome, error) to the transfer object and re-enqueues with `attempt + 1` after `min(60 * 2^(attempt-1), 28800)` s ± 10 % jitter via the background-job scheduler.
- [ ] 2.3 Enforce the attempt cap (`edepot_transfer_max_attempts` app-config, default 10): on exhaustion set status `failed`, escalate via `TransferListService::notifyArchivists`, stop automatic retries; a fresh manual `create` restarts deliberately.
- [ ] 2.4 Make retries partial-success-aware: packages whose objects are already confirmed (`markObjectTransferred` ran) are excluded from rebuild/resend; only unconfirmed packages retry (no double ingest).

## 3. BagIt output option (OR-AD-1)

- [ ] 3.1 Add the per-connection output-format setting (`zip` default | `bagit`) to the e-Depot settings surface and thread it into `SipPackageBuilder`.
- [ ] 3.2 Implement the RFC 8493 serializer branch in `SipPackageBuilder`: `bagit.txt` (1.0/UTF-8), `bag-info.txt` (Bagging-Date, Payload-Oxum, Source-Organization, External-Identifier = transfer uuid), complete `manifest-sha256.txt` (checksums recomputed at write time; reuse `getObjectFiles()` metadata for cross-checking), `tagmanifest-sha256.txt`, SIP content under `data/`; an unchecksummable payload file fails the build — never ship an incomplete manifest.
- [ ] 3.3 Record the chosen format + package manifest hash on the transfer attempt/result so proofs (task 4) can reference the package identity; transports stay unchanged (opaque file).

## 4. Proof-of-transfer records (OR-AD-3 proofs)

- [ ] 4.1 In the result-processing path (where `markObjectTransferred` runs), create one `edepotTransferProof` per confirmed object with the full field set from task 1.1; link the proof UUID from the object's `retention` metadata alongside the existing `eDepotReferentie`/`transferDate` (additive).
- [ ] 4.2 Enforce write-once semantics on proof records (schema-level immutability + service-level refusal; corrections only as new audited annotations) and verify a proof survives `DestructionExecutionJob` destroying its source object.
- [ ] 4.3 Create no proof for failed/unconfirmed objects — failures live only in the attempt history.

## 5. Tests

- [ ] 5.1 PHPUnit (CI way: php:8.3-cli + OCP stubs): BagIt layout + manifest completeness (incl. the fail-on-unchecksummable case) and zip-default unchanged; backoff formula incl. cap + jitter bounds; attempt-append + re-enqueue on failure and cap-exhaustion escalation; partial-success exclusion; transfer-list persistence round-trip across the status machine; proof creation on confirm / absence on failure; proof immutability refusal.
- [ ] 5.2 Newman for `transfer#index/show/create` (real records, non-approved refusal); Playwright e2e for the three @e2e flows (BagIt vs zip connection, retry-history + escalation journey with an unreachable endpoint, proof visible + surviving destruction + edit refused).
