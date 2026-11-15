# Tasks

## 1. The filter

- [x] 1.1 `AuditTrailController::readable()` passes a numeric `register` to the
      lister's column filters (`readableFilters()`), ignoring anything else.

## 2. Verification

- [x] 2.1 PHPUnit: a register id reaches the trail query as an integer; a slug,
      a non-numeric value and no value leave the filter out
      (`tests/Unit/Controller/ReadableAuditTrailRegisterFilterTest.php`).
