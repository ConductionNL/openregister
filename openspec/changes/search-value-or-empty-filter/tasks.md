# Tasks: search-value-or-empty-filter

## 1. Operator

- [x] 1.1 `inOrEmpty` in `COMPARISON_OPERATORS` and in both condition builders, scalar and array columns, bound values. Verify: `tests/Unit/Db/MagicMapper/MagicSearchHandlerInOrEmptyTest.php` on PostgreSQL and MariaDB with rows holding X, Y, null, missing, empty string and empty array.
- [x] 1.2 Metadata columns accept the operator. Verify: the same test on `@self.organisation`.
- [x] 1.3 Counts and facets agree with the list. Verify: the same test compares list length, count and one facet bucket.

## 2. Proof and docs

- [x] 2.1 Newman: `GET /api/objects/{register}/{schema}?setting_inOrEmpty[]=<world>` returns the world's rows and the unscoped rows, with a matching total.
  > 2026-11-25: `tests/integration/openregister-in-or-empty-filter.postman_collection.json` seeds 12 characters in
  > Aldoria, 5 in Norheim and 3 with no setting (missing, null, empty string), then: the suffix and the bracket form
  > each answer a page of 10 with no Norheim row and total 15, page 2 holds the other 5, a plain `in` filter (the
  > control) answers 12, and on a list property `settings_inOrEmpty[]` answers the Aldoria item and the `[]` item, not
  > the Norheim one. Newman against Nextcloud 34 on PostgreSQL 16 (main 55f2aa3e): 34 requests, 43 assertions, 0 failed.
- [x] 2.2 Document the operator in `docs/` in the filter table, and in `zoeken-filteren`.
  > The `docs/` half is in `docs/Features/search.md` (operator table and its own section) and `docs/api/objects.md`.
  > The `zoeken-filteren` half is this change's delta spec, which lands in the main spec when the change is archived.

Acceptance:
- Existing operators return exactly what they returned before.

## Status, 2026-11-13

**Built: the operator on every filter path (1.1, 1.2, 1.3) and its docs (2.2,
the `docs/` half).**

- `inOrEmpty` is read in `NullFilter::conditionsFor()`, the one place every
  filter path (QueryBuilder object and metadata, union object and metadata,
  nested paths) already reads `orNull`, so the paths cannot disagree. The values
  go to the path's own builder as a plain list (IN on a scalar, any-of
  containment on an array), ORed with "no value": `col IS NULL OR
  CAST(col AS TEXT|CHAR) IN ('', '[]')`. The text cast makes the empty test hold
  for a text and a JSON column on both platforms; the platform is asked only
  when the operator is present. Other operators on the property still AND.
- Registered in `MagicSearchHandler::COMPARISON_OPERATORS` and
  `NestedPathFilter::OPERATORS`; the suffix form `setting_inOrEmpty[]=` is
  parsed by the generic `_` split.
- Counts and facets reuse the same builders (design D-2). The test runs the
  union condition against a real SQLite table and checks the list and its
  COUNT(*) agree.

**Verified, and how:** `MagicSearchHandlerInOrEmptyTest` (9) asserts the SQL on
each path and runs real rows (X, Y, NULL, '', '[]') through SQLite. Not run on
PostgreSQL or MariaDB here; the e2e (2.1) is that proof.

**Found on the way, not changed:** the QueryBuilder path for an ARRAY property
does not read an operator bag (`settings[in][]=x` indexes `$values[0]` of the
bag and filters on `[null]`). `inOrEmpty` avoids it by handing the builder a
plain list, which that path does read.

**Not built:**

- **2.1**, the Newman case, needs a running stack.
- The `zoeken-filteren` main spec is updated when the change is archived (sync).

