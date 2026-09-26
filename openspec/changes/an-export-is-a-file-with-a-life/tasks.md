# Tasks: an-export-is-a-file-with-a-life

Row 10.7. Owner openregister. Depends on `export-as-its-own-right` for the
profile and the export verb.

## 1. The record

- [x] 1.1 `ExportRun` entity and mapper (2026-09-22): profile, actor, register, schema,
      format, row count, file id, produced at, expires at, download count,
      and a status naming whether the file still exists.
      - Read `SubjectExport` first and follow its shape where it fits. A
        second differently spelled export record is two answers to one
        question.
- [ ] 1.2 Every export path writes a run: the API, the scheduled report
      runner and the whole-dataset extract.
      > PARTLY DONE 2026-09-22. The scheduled report runner and
      > `exportProfiles#run` both write one, and the wiring is asserted from
      > the caller in `tests/Unit/Architecture/ExportRunsHaveAProducerTest.php`,
      > mutation-checked by removing the call site. The whole-dataset extract
      > and the remaining export paths do not yet.
      - Unit tests, mutation-checked: removing the write from the scheduled
        runner reddens an assertion about the row, not a setup line

## 2. The expiry

- [ ] 2.1 A profile declares a file retention; a run carries the expiry it
      was produced under, so changing the profile later does not silently
      move an existing file's deadline.
      > HALF DONE 2026-09-22. The run carries the expiry it was produced
      > under, which is the half that protects an existing deadline. The
      > profile does not declare a retention yet, so the recorder's default
      > of seven days applies, capped at ninety.
- [x] 2.2 A background job deletes the files of expired runs and keeps the
      rows (2026-09-22, `SweepExpiredExportRunsJob`).
      - Unit tests: a run with no expiry is never swept; a run whose file
        is already gone is not an error

## 3. The count

- [ ] 3.1 Count a download on the run when the file is served from the
      register.
      > PARTLY DONE 2026-09-22. `ExportRunRecorder::countDownload()` exists
      > and the export profile run writes a count of one, because that path
      > does serve the bytes from the register. No endpoint serves a
      > scheduled report's FILE back from the register yet, so there is no
      > call site there and none was invented.
      - Unit tests: the run's count and `openregister_files.downloadCount`
        move independently, and the test says why

## 4. The area

- [ ] 4.1 `GET /api/exports` with filters on register, schema, profile,
      actor and period, scoped by the export verb.
      > PARTLY DONE 2026-09-22. The route exists with filters on register,
      > schema, profile, source and status, scoped to the caller's own runs
      > with administrators seeing all. Period is not a filter yet, and the
      > scope is not resolved through `ExportRightService`.
      - 🔴 Probe with the least privileged principal that should be
        refused, across a tenant boundary. A scope that is accidentally a
        no-op returns exactly what an administrator sees, which is
        indistinguishable from a working page until two accounts are
        compared.
- [ ] 4.2 An index surface listing the runs, naming an expired export as
      expired rather than rendering it as a broken link.
      - A missing file and a file nobody produced look the same on a list

## 5. Tests

- [ ] 5.1 Unit tests for the record, the sweep, the count and the scope.
- [ ] 5.2 `tests/e2e/ci/an-export-is-a-file-with-a-life.spec.ts`: run an
      export, see the row, download it, see the count move, expire it, see
      it named as expired.
