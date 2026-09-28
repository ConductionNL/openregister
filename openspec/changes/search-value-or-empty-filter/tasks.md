# Tasks: search-value-or-empty-filter

## 1. Operator

- [ ] 1.1 `inOrEmpty` in `COMPARISON_OPERATORS` and in both condition builders, scalar and array columns, bound values. Verify: `tests/Unit/Db/MagicMapper/MagicSearchHandlerInOrEmptyTest.php` on PostgreSQL and MariaDB with rows holding X, Y, null, missing, empty string and empty array.
- [ ] 1.2 Metadata columns accept the operator. Verify: the same test on `@self.organisation`.
- [ ] 1.3 Counts and facets agree with the list. Verify: the same test compares list length, count and one facet bucket.

## 2. Proof and docs

- [ ] 2.1 Newman: `GET /api/objects/{register}/{schema}?setting_inOrEmpty[]=<world>` returns the world's rows and the unscoped rows, with a matching total.
- [ ] 2.2 Document the operator in `docs/` in the filter table, and in `zoeken-filteren`.

Acceptance:
- Existing operators return exactly what they returned before.
