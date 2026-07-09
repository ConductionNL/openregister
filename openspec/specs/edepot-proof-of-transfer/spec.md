---
status: done
---

# e-Depot Proof of Transfer

## Purpose

Durable transfer records and immutable proof-of-transfer per object (archival-transfer-hardening): transfer lists persist as objects in a system register (status, references, exclusions, approval metadata, attempt history, result) through the audited write path, and `transfer#index`/`show` serve the persisted records instead of placeholder responses. Every confirmed object gets a write-once `edepotTransferProof` (ingest reference + transport receipt, package identity + manifest hash, per-file SHA-256, transport, timestamp) that lives in the `edepot-transfers` register so it survives destruction of the source (Archiefwet accountability outlives the data); failed/unconfirmed objects get no proof.

**OpenSpec changes**: [archival-transfer-hardening](../../changes/archive/2026-07-06-archival-transfer-hardening/) _(archived 2026-07-06)_

## Requirements

### Requirement: Transfer lists are durable records
OpenRegister SHALL persist e-Depot transfer lists as objects in a system register — status,
object references, exclusions, approval metadata, attempt history, and transfer result — so a
list survives across requests, restarts, and job executions. The transfer API (`transfer#index`,
`transfer#show`) MUST serve these persisted records (replacing the current hard-coded placeholder
responses), and every state change (created, approved, rejected, in-progress, completed,
partially failed, failed) MUST be written through the audited object write path.

#### Scenario: A created list is retrievable

@e2e exclude durable index/show — covered by PHPUnit TransferControllerTest (index/show) + TransferRecordServiceTest::testSaveAndLoadTransferList + the Newman transfer#index/show cases

- **WHEN** the transfer eligibility scan (or an archivist) creates a transfer list
- **THEN** `transfer#index` lists it and `transfer#show` returns its persisted state, including after a service restart

#### Scenario: Status history is audited

@e2e exclude audited write path — persistence rides ObjectService (audited by construction); status-machine persistence covered by TransferListServiceTest + TransferRecordServiceTest

- **WHEN** a list moves through review → approved → in-progress → completed
- **THEN** each transition is a persisted, audited write on the transfer-list object

### Requirement: Immutable proof-of-transfer record per transferred object
For every object confirmed transferred, OpenRegister SHALL create a durable proof-of-transfer
record capturing: the e-Depot ingest confirmation (reference/archivId and transport receipt as
returned by the transport), the SIP/bag identity (package identifier, output format, package
manifest hash), per-file names and SHA-256 checksums as sent, the transfer-list reference, the
transport used, and the confirmation timestamp. Proof records MUST be immutable after creation
(anchored in the audit trail), MUST remain retrievable after the source object is destroyed in a
later destruction run (Archiefwet accountability outlives the data), and MUST be linked from the
source object's retention metadata (existing `eDepotReferentie`/`transferDate` fields point at
the proof record).

#### Scenario: Proof record created on confirmation

@e2e openspec/specs/edepot-proof-of-transfer/spec.md#proof-record-created-on-confirmation

- **WHEN** the transport confirms ingest for an object
- **THEN** a proof-of-transfer record exists with the ingest reference, package identity, per-file checksums, transport, and timestamp

#### Scenario: Proof survives destruction of the source

@e2e openspec/specs/edepot-proof-of-transfer/spec.md#proof-survives-destruction-of-the-source

- **WHEN** the transferred object is later destroyed by the destruction workflow
- **THEN** the proof-of-transfer record remains retrievable with its full content

#### Scenario: Proof records cannot be altered

@e2e exclude write-once refusal — enforced by the `edepotTransferProof` schema `immutable` flag + TransferRecordService write-once guard (TransferRecordServiceTest::testProofIsWriteOnce); destruction-independence + edit-refusal shown in the @e2e flow

- **WHEN** any caller attempts to modify a proof-of-transfer record
- **THEN** the write is refused; corrections happen only as new audited annotations, never in-place edits

#### Scenario: Failed transfers produce no proof

@e2e exclude no-proof-on-failure — processResults creates a proof only in the accepted branch (covered by the EdepotTransferService confirmed-branch proof creation; failures append to attempts[] only)

- **WHEN** an object's transfer fails or remains unconfirmed
- **THEN** no proof-of-transfer record is created for it (failures live in the attempt history, not in proofs)

@e2e An archivist completes a transfer, opens the transfer list's detail view showing the per-object proof-of-transfer records with ingest reference and checksums, then runs the destruction workflow on a transferred object and confirms the proof record is still retrievable while an edit attempt on it is refused.
