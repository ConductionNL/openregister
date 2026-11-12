---
status: proposed
---

# zoeken-filteren

## ADDED Requirements

### Requirement: Ordering on a string property ignores letter case on every supported database (REQ-CIO-001)

When `_order` names a property whose schema type is `string` (or the metadata fields `@self.name`, `@self.summary`), the query SHALL order by the lower-cased value, then by the raw value in the same direction, then by `_id` ascending. This SHALL hold on PostgreSQL and on MySQL/MariaDB with the same result for the same data. Properties of other types SHALL keep native ordering. On PostgreSQL every string column that has an index SHALL also have a `lower()` expression index.

#### Scenario: mixed case sorts as a reader expects
- **GIVEN** publications titled "Zienswijze", "aanvraag" and "Besluit"
- **WHEN** a reader lists them ordered by title ascending
- **THEN** the order is "aanvraag", "Besluit", "Zienswijze"

#### Scenario: the same order on MariaDB
<!-- @e2e exclude The e2e instance runs PostgreSQL only; covered by PHPUnit CaseInsensitiveOrderingTest::testTheSqlIsLowerThenRawThenIdOnMySql and a MariaDB run recorded in the PR body. -->

- **GIVEN** the same three records on MariaDB
- **WHEN** they are ordered by title ascending
- **THEN** the order is the same as on PostgreSQL

#### Scenario: equal values page stably
<!-- @e2e exclude Paging determinism; covered by PHPUnit CaseInsensitiveOrderingTest::testTiesAreBrokenByRawValueThenId. -->

- **GIVEN** "besluit" and "Besluit" on two records
- **WHEN** the list is paged one record at a time
- **THEN** each record appears exactly once, in the same order on every request

#### Scenario: a date still sorts as a date
<!-- @e2e exclude Covered by PHPUnit CaseInsensitiveOrderingTest::testANonStringPropertyKeepsNativeOrdering. -->

- **GIVEN** a date property in `_order`
- **WHEN** the list is ordered
- **THEN** no `LOWER()` is applied to it
