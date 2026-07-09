# Design: archival-transfer-hardening

## Verified state on HEAD (= origin/development, 6b0534094)

Every OR-AD claim from procest's `migrate-archival-to-or` re-verified against the working tree:

| Gap | What actually exists | Verdict |
|---|---|---|
| OR-AD-1 BagIt | `git grep -il bagit` → only `composer.phar` / `phpstan.phar` (binary noise). `SipPackageBuilder` uses `ZipArchive` (`:197`), writing `mets.xml` + per-object `mdto.xml` / `metadata.json` / payload files into a temp `.sip*.zip`. Per-file SHA-256 checksums are ALREADY computed in `EdepotTransferService::getObjectFiles()` metadata. | Confirmed absent; checksums reusable for manifests |
| OR-AD-2 durable retry | `EdepotTransferService`: `MAX_RETRIES = 3`, `RETRY_BACKOFF = [30, 120, 480]` (`:59–66`), executed via `sleep($backoff)` inside `sendWithRetry()` (`:199–217`) — in-flow only, worst case ~10.5 min of blocked worker, then permanent give-up. `lib/BackgroundJob/TransferExecutionJob.php` exists (QueuedJob) but is **dispatched nowhere**: the only reference outside its own file is a comment in `TransferController::create()` (`:130`), which returns a fabricated `"queued"` response without loading the list or enqueuing anything. | Confirmed; worse than filed — the async path is a stub |
| OR-AD-3 proof of transfer | Durable today: per-object retention JSON (`archiefstatus = 'overgebracht'`, `eDepotReferentie`, `transferDate` — `markObjectTransferred`, `:445–464`; failures appended to `retention.transferErrors`) + audit rows (`logTransferInitiated` / `logObjectTransferred` / `logTransferFailed` via `AuditTrailMapper::createAuditTrail`). NOT durable: the transfer list itself and its `transferResult` — `TransferListService::createTransferList()` returns an in-memory array; `TransferController::index()` returns `{results: [], total: 0}` hard-coded, `show()` a hard-coded 404; "Transfer lists are stored as register objects" exists only as comments. No ingest-confirmation payload, no checksum record, nothing an auditor can produce later. | Confirmed: no durable proof artefact; transfer lists don't persist at all |
| Reusable durability pattern | `lib/Db/WebhookLog(+Mapper)` append-only attempt rows + `lib/Cron/WebhookRetryJob.php` (300 s cadence, `next_retry_at`, retry-limit escalation, exponential backoff) + `lib/BackgroundJob/WebhookDeliveryJob.php` (QueuedJob carrying `attempt` in its argument). | The pattern OR-AD-2 reuses |
| Existing surfaces to keep | `TransferListService` status machine (in_review → approved/rejected → in_progress → completed / partially_failed / failed) + `notifyArchivists`; `TransferCheckJob` eligibility scan (archiefnominatie/archiefactiedatum/archiefstatus); `Transport/TransportInterface` with `TransportResult::isSuccess/isPartialSuccess`; e-Depot settings (`edepot_endpoint_url` etc. via `IAppConfig`). | All consumed unchanged |

## OR-AD-1 — BagIt as a serialization option, not a new builder

`SipPackageBuilder` gains `format: 'zip' | 'bagit'` (per-connection setting on the e-Depot
settings surface; default `zip`). BagIt layout per RFC 8493:

```
bag/
├── bagit.txt                  # BagIt-Version: 1.0 / Tag-File-Character-Encoding: UTF-8
├── bag-info.txt               # Bagging-Date, Payload-Oxum, Source-Organization, External-Identifier (transfer uuid)
├── manifest-sha256.txt        # every data/ file — checksums reused from getObjectFiles() metadata,
│                              #   recomputed at write time for tag-time integrity
├── tagmanifest-sha256.txt     # bagit.txt, bag-info.txt, manifest-sha256.txt
└── data/
    ├── mets.xml
    └── objects/<uuid>/{mdto.xml, metadata.json, files/…}
```

The bag is still delivered as one archive through the existing `TransportInterface` (transports
move opaque files; no transport change). Same `ZipArchive` writer, different internal layout +
manifest emission — a serializer branch inside the builder, not a sibling service (the exact
"output format option" wording of OR-AD-1). Incomplete manifests fail the build: a bag whose
manifest lies is worse than no bag.

## OR-AD-2 — durable retry via the webhook pattern

Execution moves fully onto `TransferExecutionJob` (finally dispatched for real):

- `TransferController::create()` loads the persisted list (OR-AD-3), verifies `approved`,
  enqueues `TransferExecutionJob` with `{transferListId, attempt: 1}` — the `WebhookDeliveryJob`
  argument convention.
- `sendWithRetry()`'s `sleep()` chain is removed. One job run = one transport attempt per
  outstanding package. Failure ⇒ append an attempt record to the transfer-list object's
  `attempts[]` (append-only, per the `WebhookLog` semantics but stored on the durable transfer
  object rather than a new table — the transfer list is already the aggregate root and archivists
  read it) and re-enqueue with `attempt + 1` after `min(60 * 2^(attempt-1), 28800)` seconds
  (1 m → 8 h cap, ±10 % jitter to avoid thundering herds), using the background-job scheduler —
  never an in-process wait.
- Attempt cap (`edepot_transfer_max_attempts`, default 10 ≈ 2 days of coverage) exhausted ⇒
  status `failed`, `notifyArchivists`, stop. Manual re-initiation (a fresh `create`) restarts the
  cycle deliberately — no zombie auto-retry.
- Partial success: packages whose objects are confirmed (`markObjectTransferred` already ran) are
  excluded from retry rebuilds — the attempt record tracks per-package outcome so a retry
  rebuilds/re-sends only unconfirmed packages (idempotent toward the e-Depot; no double ingest).

Why on the transfer object and not a new Db table: the webhook stack needed its own table because
webhooks have no durable aggregate; transfers now do (OR-AD-3), and putting attempts on the same
audited object keeps one archivist-visible story. The *pattern* (append-only attempts, scheduled
next try, limit + escalation) is copied; the *storage* rides the record we're introducing anyway.

## OR-AD-3 — system register: transfer lists + proof records

A system register fragment in `lib/Settings/` (same install mechanics as the DSAR register)
carrying two schemas:

- **`edepotTransfer`** — uuid, status, objectReferences, exclusions, approvalMetadata,
  `attempts[]` (append-only), transferResult, timestamps. `TransferListService` persists through
  `ObjectService` (RBAC: archivist-facing; the register is org-scoped like the rest of OR's
  system registers), which makes the placeholder comments in `TransferController` true and gives
  status transitions the audited write path for free.
- **`edepotTransferProof`** — one per confirmed object: ingest reference/archivId + raw transport
  receipt (from `TransportResult`), package identity (package id, format zip|bagit, package
  manifest SHA-256), per-file `{name, sha256}` as sent, transfer reference, transport name,
  confirmedAt. Created in the result-processing path right where `markObjectTransferred` runs;
  the object's existing `retention.eDepotReferentie` gains the proof-record UUID alongside the
  raw reference (additive — existing readers keep working).

Immutability: proof records are write-once — enforced the same way OR's immutable audit posture
works (schema-level immutability + service-level refusal; corrections are new audited
annotations). Destruction independence: proof records live in the system register, not in the
source object's register, so `DestructionExecutionJob` destroying the transferred object never
cascades to the proof (the Archiefwet point of the record). procest's `archiefBewijs` migration
(its change, not this one) maps 1:1 onto `edepotTransferProof`.

## ADR-032 sizing note

One `kind: code` change, three capabilities: all three deltas harden the same
`Edepot/` service chain and share the durable transfer record (retry attempts live on it, proofs
reference it, BagIt identity is recorded in it). Splitting would sequence three PRs through the
same files with hard ordering (proof register before retry storage before controller wiring)
for no reviewer-scope gain. The register fragment is incidental config around a code centre of
mass (ADR-032 `code` definition).

## Out of scope

- Wiring a *real* e-Depot test endpoint (procest's `external-integrations-test-environments`
  concern; transports stay pluggable behind `TransportInterface`).
- procest-side data migration of legacy `archiefBewijs` objects (procest's
  `migrate-archival-to-or` owns it; this change only provides the target schema).
- Changing eligibility/nomination logic (`TransferCheckJob`) or the destruction workflow.
- OCR/format validation of payload files before bagging (a future ingest-quality gate).
