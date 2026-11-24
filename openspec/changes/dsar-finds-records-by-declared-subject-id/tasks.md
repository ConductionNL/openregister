# Tasks: dsar-finds-records-by-declared-subject-id

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Reader

- [x] 1.1 `lib/Service/Gdpr/ProcessingAnnotationReader.php` from `ProcessingLogService::readAnnotation()` (D-3); `ProcessingLogService` uses it, its tests unchanged.

## 2. Discovery

- [x] 2.1 `DataSubjectRequestService::matchDeclaredFields()` and the merge in `discover()` (D-1, D-2): exact match, type filter, list fields, `_rbac` and `_multitenancy` on. Verify: `tests/Unit/Service/Gdpr/DataSubjectRequestServiceTest.php` for a declared-field-only hit, a hit from both sources, the type filter, no `ilike` on ids, and a tenant-scoped refusal.
- [x] 2.2 `erase()` and the erasure preview over the merged set (D-4), legal hold honoured. Verify: same test file.
- [ ] 2.3 Newman: an access export for a subject found only through `subjectIdFields`.

## 3. Close

- [ ] 3.1 `docs/`: declaring `subjectIdFields` so a data subject request finds your records. Tell learniq the change name (learniq #1787).
  - The `docs/` half is in place: `docs/features/dsar-declared-subject-id-fields.md` (built with 2.1). Open: telling learniq, which is that lane's handoff, not openregister code.
- [x] 3.2 `@spec` tags; `openspec validate dsar-finds-records-by-declared-subject-id --strict`.
  - 2026-11-24: every method the build added or changed carries `@spec` (the private helpers of `SubjectDeclaredFieldIndex` and `ProcessingAnnotationReader`, `ProcessingLogService::readAnnotation()`, the `SubjectRecords` constructor). `--strict` refused the one requirement as over 500 characters; it is split into three (discovery, what included an object, scoping and holds) with the same SHALLs and scenarios, plus a scenario for an object found by both sources (`testAnObjectFoundByBothSourcesCarriesBothReasons`). `openspec validate --strict`: valid.
