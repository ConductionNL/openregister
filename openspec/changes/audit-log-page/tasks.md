# Tasks: audit-log-page

## 1. Query

- [ ] 1.1 Filtered, cursor-paginated instance-wide query in
      `AuditTrailMapper` using the existing indexes.
- [ ] 1.2 RBAC join for non-admins.

## 2. API and export

- [ ] 2.1 `GET /api/audit-trails` with the six filters and full-text.
- [ ] 2.2 CSV and JSON export of the filtered result; background job past
      10,000 rows.

## 3. Surface

- [ ] 3.1 Audit leaf `index` surface with filter bar and export button.

## 4. Tests

- [ ] 4.1 Unit tests for filters, RBAC join and export contents.
- [ ] 4.2 `tests/e2e/ci/audit-log-page.spec.ts`: as admin, filter by actor
      and period, see the rows, export and check the hash columns.
