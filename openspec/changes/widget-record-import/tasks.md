# Tasks — widget record import

## 1. Parse and map

- [x] 1.1 Reader for CSV/TSV, XLSX and JSON-lines producing an `iterable` of rows,
      never loading the whole file. The streaming write path is pointless if the
      parser buffers.
- [x] 1.2 Header → schema-property mapping proposal (exact, then case- and
      separator-insensitive). Unmatched columns are reported as unmatched.
- [x] 1.3 Confirmation step that refuses to proceed while any column is neither
      mapped nor explicitly ignored.
  - Built (13 Nov) as services under `lib/Service/Import/RecordImport/`:
    `RecordFileReader` (CSV with comma/semicolon sniffing, TSV, JSON lines one line
    at a time; XLSX in chunks of 500 rows through a read filter), `ColumnMapper`
    (proposal, confirmation, `ConfirmedMapping`). No route or UI yet.

## 2. Dry run

- [x] 2.1 Classify each row as would-create / would-update / would-be-unchanged /
      would-fail, using the same validation the write path uses. A dry run that
      validates differently from the real run is worse than none.
- [x] 2.2 Report totals plus per-row reasons for the would-fail set.
  - Built (14 Nov): a dry-run mode on the streaming save (`StreamingSave::$dryRun`).
    Each row goes through the same `saveObject()` with persisting off; while it
    runs, the save still refuses a register or schema the organisation only
    consumes, refuses a schema served from an external source (that write is
    delegated and cannot be previewed), writes no cascaded or inversely related
    object, and publishes the verdict the write would reach: `created`, or for an
    update `unchanged` only when the prepared data is identical to the stored data.
    `RecordImportService::dryRun()` and `POST /api/record-import/{register}/{schema}/dry-run`
    (administrators only) answer the same report as the import with `dryRun: true`,
    no created identifiers, and the would-fail rows as `failedRows` CSV. What a dry
    run cannot see: a failure only the database raises at insert time.

## 3. Write

- [x] 3.1 Import service calling `ObjectService::saveObjectsStreaming()`, which
      already clears the reference cache at the batch boundary.
- [x] 3.2 Identity handling: use the nominated identifying column to update;
      with none nominated, create only, and say so at confirmation time.
- [x] 3.3 Surface `BatchOperationStatus` — created/updated/unchanged/failed and
      the reference-cache counters — as the result payload.
  - Built (13 Nov): `RecordImportService::import()`. An identifier value matching
    one object updates it; matching more than one refuses that row with its line;
    failures carry the file line. No route yet: the route that exposes it must be
    administrator-only, like `BulkController`'s `stream`.
  - Route (14 Nov): `RecordImportController`, administrators only (no
    `NoAdminRequired`, request token kept). `POST /api/record-import/{register}/{schema}/mapping`
    takes the uploaded `file` and answers the proposed mapping with its unmatched
    columns, writing nothing; `POST /api/record-import/{register}/{schema}` takes the
    file, `decisions` (column => property, or null to ignore) and an optional
    `identifier`, answers 422 while a column is undecided, and otherwise returns the
    import status plus, when rows failed, `failedRows` (the 4.2 export as CSV).

## 4. Results

- [x] 4.1 Result view driven by the status object.
  - Built (14 Nov): `RecordImportDialog` shows the dry-run or import result from the
    status payload: created, updated, unchanged and failed counts, the reference-cache
    hits and misses and the duration, the first 50 failures with their lines, and a
    "Download failed rows" button that saves the 4.2 export as `<file>-failed.csv`.
- [x] 4.2 Failed-row export in the input's column shape plus a reason column,
      round-trippable back into the importer.
  - Built (14 Nov): `FailedRowExport::export()` re-reads the imported file and keeps
    the lines that failed, as CSV in the file's own columns plus `reason`
    (`import_reason` when the file already has a `reason` column); two failures on
    one line share the row, and a failure with no line is counted as `unplaced`.
    Re-importing it lists the reason column as unmatched, to be ignored.

## 5. Widget

- [x] 5.1 Drop target that hands the file to the import surface and holds no
      parsing or write logic.
  - Built (14 Nov): `RecordFileDropTarget` around the objects list. A dropped CSV,
    TSV, XLSX or JSON-lines file (by extension) goes to `openRecordImport()` with the
    list's register and schema; any other file is left alone. The dialog then runs
    every step through `services/recordImport.js` (mapping, dry run, import), keeps
    Dry run and Import disabled while a column is undecided, and states create-only
    when no identifying property is chosen.
- [x] 5.2 Progress reporting for long imports.
  - Built (14 Nov): the dialog shows the upload's progress and then that the
    server is processing the rows.
  - Built (15 Nov): row progress while the import or dry run runs. The dialog sends
    a fresh `progress` token with the request; the import reports the rows it has
    read every 50 rows and at the end (`ImportProgress`, in the distributed cache,
    keyed by the user and the token, expiring after an hour), and the dialog polls
    `GET /api/record-import/progress/{token}` (administrators only, a user reads only
    their own token) each second to show "processed N rows so far". The import
    itself stays one synchronous request; without a cache backend no progress is
    reported and the dialog keeps the processing message.

## Verification

- [x] V1 A 5,000-row file referencing 3 targets reports 3 cache misses and the
      rest hits — proving the streaming path is actually engaged. Without this
      the import could silently be using the bulk path and nobody would notice.
  - 2026-11-25: measured on a sandbox Nextcloud 34 on MariaDB with `reference_validation_admin_bypass=false`: 5,000 rows over 3 targets, 5,000 created, 3 misses, 4,997 hits. `tests/e2e/ci/widget-record-import.spec.ts` V1. The import routes are administrator-only and the bypass (default on) skips reference validation for an administrator, so with the default the counters read 0 misses; the spec skips V1 and V6 there, naming why.
- [x] V2 A file with one invalid row imports the other 999 and reports exactly
      one failure.
  - 2026-11-25: 1,000 rows with one over-long `naam`: 999 created, 1 failed, the count grew by 999, and `failedRows` held that one line with its reason (same spec).
- [x] V3 A dry run over a populated register leaves the object count unchanged,
      asserted by counting before and after.
  - 2026-11-25: a 200-row dry run over a register of 5,999 rows: 5,999 before and after (same spec).
- [x] V4 Re-importing an unchanged file with an identifying column reports
      0 created.
  - 2026-11-25: the V1 file again with identifier `code`: 0 created, 5,000 unchanged, count unchanged (same spec, on 300 rows).
- [x] V5 Export the failures of a known-bad import, correct them, re-import, and
      assert only those rows changed.
  - 2026-11-25: the exported failed row, corrected and re-imported with the `reason` column ignored: 1 created, 0 updated, 0 failed; the count reached exactly the 1,000 rows of the file (same spec).
- [x] V6 Negative control for V1: run the same fixture through the non-streaming
      path and assert the cache counters are NOT produced. "Cache hits reported"
      means nothing until it has been shown that the other path reports none.
  - 2026-11-25: the same row shape through `POST /api/bulk/{register}/{schema}/save` (300 rows): no `referenceCacheHits` or `referenceCacheMisses` in the answer (same spec).
