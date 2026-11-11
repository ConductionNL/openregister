---
kind: code
depends_on: [export-as-its-own-right]
---

# Proposal: an-export-is-a-file-with-a-life

Gap scan pack c, parity ledger row 10.7 "Export files area with expiry and
download counts". dossiq rates `no`, opencase `no`, GZAC `no`, zaaksysteem
`yes`. Owner openregister, because exports and the files they produce are
the object layer's.

## Why

An export is a copy of the register walking out of the building. Every one
of them is a data transfer, and after it is written this platform stops
knowing anything about it: where it went, who took it, how often, and
whether it is still sitting in somebody's Files folder two years later.

An administrator asked "who has an export of this register" cannot answer.
Neither can a functional administrator answering a data subject, which is
the question that turns this from tidiness into an obligation.

## What is actually there

Read against `parity/round2`.

**The pattern is already proven here, once.** `SubjectExport` is a row with
`status`, `readyAt`, `expiresAt`, `deliveredAt`, `objectCount` and a content
hash, and `OneTimeDownloadTokenStore` mints a single-use, time-boxed,
case-scoped download token, persisting only a SHA-256 of it. That is the
AVG data-subject bundle, and it is the shape this change wants everywhere
else.

**Everywhere else forgets.** `ScheduledReportService::runOne()` writes the
export into the owner's Files under `Reports/`, notifies them and records
`lastStatus` and `lastError` on the report. The file itself is then an
ordinary file. Nothing expires it, nothing counts a download, and nothing
lists the exports a register has produced.

**Two halves exist and are not joined.** `openregister_files` already
carries `downloadCount`, incremented by `FileMapper::incrementDownloadCount()`
and emitted by `FileFormattingHandler::formatFile()`. `ExportProfile`
already exists from `export-as-its-own-right`, which makes export its own
permission verb and gives an export a declared field set. What neither of
them produces is a record of a produced export file.

## What this change does

- **A produced export is a row.** An export run records its profile, its
  actor, its register and schema, its row count, its format, the file it
  produced, when it was produced and when it expires. The row is the thing
  an administrator lists; the file is what it points at.
- **An export expires, and the expiry is a deletion.** A profile declares a
  retention for the files it produces, defaulting to a short one. A run past
  its expiry has its file deleted and its row kept, because the fact that an
  export happened outlives the copy it made. A run with no expiry is a
  deliberate declaration and says so on the row.
- **Downloads are counted on the export, not only on the file.** The count
  belongs to the run, because the same file moved or copied inside Files is
  no longer the thing the register handed out, and a count that follows the
  file answers a different question.
- **An exports area lists them.** Filterable by register, schema, profile,
  actor and period, showing the row count, the format, the expiry and the
  download count, with the file reachable while it exists and named as gone
  when it is not. Scoped by the export verb: a principal sees the runs they
  made, an administrator sees all of them.
- **An expired export is named as expired.** A missing file and a file
  nobody produced look identical on a list, and only one of them means the
  retention did its job.

## What this change does not do

It does not touch the AVG bundle. `SubjectExport` has its own lifecycle, its
own single-use token and its own legal clock, and folding it into this
record would put a data subject's bundle in an administrator's list.

It does not turn a Files copy into a controlled one. Once the file is in
somebody's Files tree they may copy it, and this change counts the download
from the register rather than pretending otherwise. Saying which is which is
the point.

## ADRs

- ADR-003: an export run is an audit fact naming actor, profile and row
  count.
- ADR-022: one export record in the object layer, consumed by every leaf
  app.

## Impact

- Extends: `data-import-export` and `export-as-its-own-right` (the run
  record, the expiry and the count)
- Affected code: the export service and its writers, the scheduled report
  runner, a new mapper and entity, a background job for the expiry sweep
- Backwards compatible: an instance that sets no retention keeps its files,
  and the list shows what it has
- Size: M
