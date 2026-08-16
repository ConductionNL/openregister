# Tasks — widget record import

## 1. Parse and map

- [ ] 1.1 Reader for CSV/TSV, XLSX and JSON-lines producing an `iterable` of rows,
      never loading the whole file. The streaming write path is pointless if the
      parser buffers.
- [ ] 1.2 Header → schema-property mapping proposal (exact, then case- and
      separator-insensitive). Unmatched columns are reported as unmatched.
- [ ] 1.3 Confirmation step that refuses to proceed while any column is neither
      mapped nor explicitly ignored.

## 2. Dry run

- [ ] 2.1 Classify each row as would-create / would-update / would-be-unchanged /
      would-fail, using the same validation the write path uses. A dry run that
      validates differently from the real run is worse than none.
- [ ] 2.2 Report totals plus per-row reasons for the would-fail set.

## 3. Write

- [ ] 3.1 Import service calling `ObjectService::saveObjectsStreaming()`, which
      already clears the reference cache at the batch boundary.
- [ ] 3.2 Identity handling: use the nominated identifying column to update;
      with none nominated, create only, and say so at confirmation time.
- [ ] 3.3 Surface `BatchOperationStatus` — created/updated/unchanged/failed and
      the reference-cache counters — as the result payload.

## 4. Results

- [ ] 4.1 Result view driven by the status object.
- [ ] 4.2 Failed-row export in the input's column shape plus a reason column,
      round-trippable back into the importer.

## 5. Widget

- [ ] 5.1 Drop target that hands the file to the import surface and holds no
      parsing or write logic.
- [ ] 5.2 Progress reporting for long imports.

## Verification

- [ ] V1 A 5,000-row file referencing 3 targets reports 3 cache misses and the
      rest hits — proving the streaming path is actually engaged. Without this
      the import could silently be using the bulk path and nobody would notice.
- [ ] V2 A file with one invalid row imports the other 999 and reports exactly
      one failure.
- [ ] V3 A dry run over a populated register leaves the object count unchanged,
      asserted by counting before and after.
- [ ] V4 Re-importing an unchanged file with an identifying column reports
      0 created.
- [ ] V5 Export the failures of a known-bad import, correct them, re-import, and
      assert only those rows changed.
- [ ] V6 Negative control for V1: run the same fixture through the non-streaming
      path and assert the cache counters are NOT produced. "Cache hits reported"
      means nothing until it has been shown that the other path reports none.
