---
kind: code
depends_on: []
---

## Why

procest's `migrate-archival-to-or` change (ADR-051 §4 migrate-or-exception mandate) retires its
five app-local archival services onto OR's archival/e-Depot stack and filed exactly three OR-side
delta requirements it could not consume (procest proposal §OR-side delta requirements, OR-AD-1..3).
All three re-verified on OR HEAD (= `origin/development`, 6b0534094):

1. **OR-AD-1 — BagIt output.** `git grep -i bagit` over the repo matches only vendored phar
   binaries — OR has **no** BagIt (RFC 8493) serialization. `Edepot/SipPackageBuilder` produces a
   plain `ZipArchive` SIP (`mets.xml` + per-object `mdto.xml`/`metadata.json`/payload files).
   e-Depots that require BagIt bags cannot be served; procest's retired `BagItBundlerService` must
   not be rebuilt app-local.
2. **OR-AD-2 — durable long-horizon retry.** `EdepotTransferService::sendWithRetry` retries
   **in-flow only**: `MAX_RETRIES = 3`, `RETRY_BACKOFF = [30, 120, 480]` seconds executed via
   `sleep()` inside the running process (`EdepotTransferService.php:59–66, 199–217`) — worst case
   it blocks a worker ~10.5 minutes and then gives up forever. There is no cross-request retry
   with long-horizon backoff or escalation. Worse: `TransferExecutionJob` (the QueuedJob that
   should carry executions) is **never enqueued anywhere** — `TransferController::create()` is a
   stub that returns a fake `"queued"` response without loading the list or dispatching the job.
3. **OR-AD-3 — durable proof-of-transfer.** What exists today: per-object retention JSON fields
   written by `markObjectTransferred` (`archiefstatus = overgebracht`, `eDepotReferentie`,
   `transferDate`), append-only audit rows (`logTransferInitiated` / `logObjectTransferred` /
   `logTransferFailed`), and an in-memory `transferResult` array on the transfer list. But
   **transfer lists themselves are never persisted**: `TransferListService::createTransferList`
   returns an array; `TransferController::index()` returns a hard-coded empty placeholder and
   `show()` a hard-coded 404 ("Transfer lists are stored as register objects" exists only as a
   comment). There is no durable ingest-confirmation/checksum artefact an archivist or auditor can
   produce years later (Archiefwet accountability), and procest's retired `archiefBewijs` records
   have no OR home to migrate into.

These are generic e-Depot pipeline properties, not procest domain — per ADR-022 they harden OR's
existing archival services rather than spawn new subsystems.

## What Changes

- **BagIt 1.0 output option (OR-AD-1)** — `SipPackageBuilder` gains an output-format option
  (`zip` default, `bagit`): the same SIP content laid out as an RFC 8493 bag (`bagit.txt`,
  `bag-info.txt`, `manifest-sha256.txt`, `tagmanifest-sha256.txt`, payload under `data/`),
  selected per e-Depot connection in the existing e-Depot settings. No new builder service.
- **Durable transfer execution + long-horizon retry (OR-AD-2)** — transfer execution moves fully
  onto the queued-job path: `TransferController::create()` really loads the approved list and
  dispatches `TransferExecutionJob`; on transport failure the attempt is recorded append-only and
  the next attempt is **rescheduled** with long-horizon exponential backoff (1 m → 8 h cap,
  jittered) instead of `sleep()`-blocking the worker; after N exhausted attempts the transfer
  escalates to the archivists via the existing `TransferListService::notifyArchivists` path and
  stops. Mirrors OR's shipped `WebhookLog` + `WebhookRetryJob` durability pattern — reused, not
  reinvented.
- **Durable transfer + proof-of-transfer records (OR-AD-3)** — transfer lists and their outcomes
  become durable OR objects in a system register (making the controller's placeholder comment
  true): the transfer-list record (status, approvals, exclusions, attempts) and, per transferred
  object, a **proof-of-transfer record** capturing the e-Depot ingest confirmation (reference /
  archivId, transport receipt), the SIP identity (package id, format, `manifest-sha256`), and
  per-file checksums (already computed by `getObjectFiles`). Proof records are immutable
  (audit-trail-anchored), survive object destruction after handover, and give procest's
  `archiefBewijs` migration a target. `TransferController::index()/show()` serve the real records.

## Capabilities

### New Capabilities
- `edepot-bagit-output`: RFC 8493 BagIt serialization as a SIP output-format option.
- `edepot-durable-retry`: queued execution, append-only attempt records, long-horizon backoff,
  archivist escalation.
- `edepot-proof-of-transfer`: durable transfer-list objects + immutable per-object
  proof-of-transfer records served over the transfer API.

### Modified Capabilities
<!-- None in openspec/specs: the existing edepot-transfer main spec's requirements stay valid;
     these three capabilities harden the same pipeline additively (new format option, new
     durability semantics, new record types). -->

## Impact

- **Touched code**: `lib/Service/Edepot/SipPackageBuilder.php` (format option),
  `lib/Service/Edepot/EdepotTransferService.php` (retry extraction — in-flow `sleep()` chain
  replaced by rescheduling), `lib/BackgroundJob/TransferExecutionJob.php` (attempt-aware),
  `lib/Controller/TransferController.php` (stubs → real persistence + dispatch),
  `lib/Service/Edepot/TransferListService.php` (persist via `ObjectService`).
- **New code**: transfer-attempt rescheduling (new `TransferRetryJob` or attempt-arg re-enqueue —
  design decides), the system register/schema pair for transfer lists + proof records
  (`lib/Settings/` register fragment), proof-record assembly in the result-processing path.
- **Consumes (unchanged)**: `Transport/TransportInterface` (+ Sftp/RestApi/OpenConnector) and
  `TransportResult`, `MdtoXmlGenerator`, `AuditTrailMapper`, `TransferListService` statuses +
  `notifyArchivists`, `TransferCheckJob` eligibility scan, e-Depot settings surface, the
  `WebhookLog`/`WebhookRetryJob` durability pattern.
- **APIs**: `transfer#index/show/create` become real (same routes); proof records readable
  through the standard object API in the system register.
- **Fixes shipped stubs**: `TransferController` placeholder responses and the never-dispatched
  `TransferExecutionJob` are pre-existing defects closed by this change (stub-scan posture).
- **Downstream**: procest `migrate-archival-to-or` consumes all three deltas (its DC03 task);
  its `archiefBewijs` export/migration lands against the proof-record schema.
