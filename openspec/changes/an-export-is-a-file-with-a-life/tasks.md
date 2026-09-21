# Tasks: an-export-is-a-file-with-a-life

Row 10.7. Owner openregister. Depends on `export-as-its-own-right` for the
profile and the export verb.

## 1. The record

- [ ] 1.1 `ExportRun` entity and mapper: profile, actor, register, schema,
      format, row count, file id, produced at, expires at, download count,
      and a status naming whether the file still exists.
      - Read `SubjectExport` first and follow its shape where it fits. A
        second differently spelled export record is two answers to one
        question.
- [ ] 1.2 Every export path writes a run: the API, the scheduled report
      runner and the whole-dataset extract.
      - Unit tests, mutation-checked: removing the write from the scheduled
        runner reddens an assertion about the row, not a setup line

## 2. The expiry

- [ ] 2.1 A profile declares a file retention; a run carries the expiry it
      was produced under, so changing the profile later does not silently
      move an existing file's deadline.
- [ ] 2.2 A background job deletes the files of expired runs and keeps the
      rows.
      - Unit tests: a run with no expiry is never swept; a run whose file
        is already gone is not an error

## 3. The count

- [ ] 3.1 Count a download on the run when the file is served from the
      register.
      - Unit tests: the run's count and `openregister_files.downloadCount`
        move independently, and the test says why

## 4. The area

- [ ] 4.1 `GET /api/exports` with filters on register, schema, profile,
      actor and period, scoped by the export verb.
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
