---
kind: code
depends_on: [file-publication-window]
---

# Proposal: published-file-digest

## Summary

A published file is provably the file that was published: its SHA-256 is recorded when it becomes public, returned with it, checked on every public read, and verifiable by anyone who may read it.

- Rows: 11.7 and 11.8 (not statutory).
- Wave 2, size M.
- Dependencies: `openregister/file-publication-window` (open, 10 of 12 tasks, no issue yet) decides the moment a file becomes public; `openregister/redaction-release-safeguards` (https://github.com/ConductionNL/openregister/issues/4392) runs its check first.
- No Ruben decision bears on it.
- Build rules: openspec/woo-build-rules.md

## Why

Two rows ask that a published file be provably the file that was published. Our column (the round 1 baseline):

| row | capability | ours today |
|---|---|---|
| 11.7 | A published file stays byte identical, and that is provable | no: nothing records a digest of the published file. OpenRegister seals its audit trail, not the file |
| 11.8 | A hash or seal covers the published file | no: as 11.7. The sha256 hits in opencatalogi (`PortalAssertionVerifier`, `DirectoryService`) hash nothing published |

opencatalogi's `woo-redaction-pipeline` (merged 2026-10-05) records a SHA-256 of each redacted file it writes, for that path only. This change generalises it: every file OpenRegister makes public gets its digest recorded, sealed into the audit chain, shown, and checked when it is read.

## What changes

- When a file becomes public (through `FilePublishingHandler::publishFile()`, or when its publication window from `file-publication-window` opens), OpenRegister streams it once, records `sha256`, `size` and the moment in a new `openregister_file_digests` row and in the file's metadata as `published.sha256`, and writes an audit row with action `file.published` carrying the file id, the object, the digest and the size. The row goes through `AuditTrailMapper::insertAuditTrails()`, so the existing hash chain seals the digest.
- The file API (`files#index`, `files#show`) and every public file listing return `published.sha256` and `published.sealedBy` (the audit row's uuid and hash). A public download sends `Repr-Digest: sha-256=:<base64>:`.
- A public read checks the file against its record: when the file's etag or size differs from the recorded one, the digest is recomputed; a mismatch refuses the public download with 409, writes an audit row `file.digest-mismatch` and raises an operations alert. A changed file is never served as the published one.
- A nightly `PublishedDigestVerifyJob` recomputes the digest of every public file and reports mismatches on the operations console.
- `GET /api/files/{fileId}/digest/verify` lets anyone who may read the file have it recomputed and compared, returning the record, the current digest, the verdict and the audit row with its chain position, so a third party can prove byte identity.
- Unpublishing ends the record's validity; publishing again records a new digest and a new row.

## What does not change

- The audit chain's own sealing.
- opencatalogi's digest of redacted output; it can now read OpenRegister's instead.

## Dependencies and absent apps

- Waits on `file-publication-window` (open, 10 of 12): the window decides the moment a file becomes public. Until it lands, `publishFile()` is the only moment, and the hook for the window opening is added with it.
- `redaction-release-safeguards` (wave 1) refuses to publish an unverified redacted copy; this change runs after that check.
- Consumers: opencatalogi and portaliq show the digest on the public page through their own changes (`opencatalogi/published-file-carries-its-facts`). They require OpenRegister.

## Wave and decision

Wave 2, size M. No decision bears on it. Closes 11.7 and 11.8.
