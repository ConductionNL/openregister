# Design — retrofit-2026-05-25-bw-svc-file

Reverse-spec retrofit. No code changes; this records the triage decisions behind
the REQ-006 … REQ-010 deltas and the exclusions.

## Decision 1 — Extend `file-actions`, do not mint a new capability

The batch is the deferred `Service/File/` slice from
`retrofit-2026-05-24-file-actions`'s `## DROP — future-pass:next`. It is the same
capability surface (per-object files), so this change adds REQ-006 … REQ-010 to
`file-actions` rather than creating `files-render-extension` (UI rendering) or a
new capability. The capability hint mentioned `file-actions` / files-render — the
shipped behavior here is server-side file actions, so `file-actions` is correct.

## Decision 2 — Five REQs grouped by handler responsibility

The handlers already follow single-responsibility, so the REQs map cleanly:

- REQ-006 = CreateFileHandler (create/upsert)
- REQ-007 = ReadFileHandler + FileFormattingHandler (read + projection)
- REQ-008 = UpdateFileHandler + DocumentProcessingHandler (mutate)
- REQ-009 = FilePublishingHandler + FileSharingHandler + FileBatchHandler + FileOwnershipHandler (publish/share/batch)
- REQ-010 = FileValidationHandler (security)

Folder management (FolderManagementHandler) and tagging (TaggingHandler) already
have homes in REQ-004 / REQ-005 from the prior pass, so those batch methods are
annotated to the existing REQs instead of inflating the new-REQ count.

## Decision 3 — `FileCrudHandler` is excluded wholesale

All eight `FileCrudHandler` methods are Phase-1B placeholders that throw
`"... pending Phase 2 extraction"`. They have zero observable behavior beyond the
throw, so each is `@spec exclude`d with that reason. They are NOT dropped silently —
the exclusion reason names the Phase-2 follow-up so a future pass re-triages them
once the real logic is extracted from `FileService`.

## Decision 4 — `FileAuditHandler::logDownload` excluded as a no-op

`logDownload()` builds a `$data` array and emits a single info log line — the
actual `AuditTrail` insert is commented out in the shipped code. With no persisted
effect there is no contract to spec. (Its sibling `logFileAction` / `logBulkDownload`
DO persist and are already annotated to `audit-trail-immutable` from a prior pass.)

## Decision 5 — Observed quirks captured, not fixed

Heuristic base64 detection, all-digit-filename-as-id, `object:`-tag preservation,
FileMapper-direct share writes, the broad executable blocklist, and the asymmetric
file-vs-folder share failure contracts are all captured in the REQ text as observed
behavior. Tightening any of them is a future functional change, out of scope for a
reverse-spec pass.
