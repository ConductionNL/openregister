## 1. Method implementation

- [ ] 1.1 **Add `getDistinctActorCount` to `AuditTrailMapper`**
  - spec_ref: REQ-ORDA-001, REQ-ORDA-002, REQ-ORDA-003, REQ-ORDA-004, REQ-ORDA-005, REQ-ORDA-006
  - files: `lib/Db/AuditTrailMapper.php`
  - Append the new public method **after** `getStatisticsGroupedBySchema` (the most recently added aggregation, keeps the aggregation block grouped) and **before** the private helpers section.
  - Signature: `public function getDistinctActorCount(array $schemaIds, int $hours): int`.
  - Body:
    1. `if ($schemaIds === []) { return 0; }` — empty short-circuit per REQ-ORDA-002.
    2. `if ($hours <= 0) { throw new \InvalidArgumentException(sprintf('hours must be positive, got %d', $hours)); }` per REQ-ORDA-003.
    3. Compute `$since = (new \DateTimeImmutable())->modify(sprintf('-%d hours', $hours));`.
    4. Build the query via `$this->db->getQueryBuilder()` mirroring the bind style of `getActionChartData` — verify the exact `IQueryBuilder::PARAM_*` constants used by the existing methods at apply time. Bind `$schemaIds` with `IQueryBuilder::PARAM_INT_ARRAY`; bind `$since->format('Y-m-d H:i:s')` as a string (matching the format the other aggregation methods use — check before committing).
    5. Predicate: `schema_id IN (:schemaIds) AND created >= :since AND user_id IS NOT NULL`.
    6. SELECT: `COUNT(DISTINCT user_id) AS distinct_actors`.
    7. Execute, fetch the scalar, cast to `int`, return.
  - PHPDoc block per REQ-ORDA-007 (every bullet in the requirement must appear).
  - SPDX-License-Identifier + SPDX-FileCopyrightText already present at file head — no change.
  - acceptance_criteria: PHPCS / Psalm / PHPStan pass via `composer check:strict`; the new method is callable from a service registered against the DI container in the unit test.

- [ ] 1.2 **Verify table column names against the running schema**
  - spec_ref: REQ-ORDA-001
  - files: read-only check; no edit
  - Confirm `audit_trail` table has columns `user_id`, `schema_id`, and `created` with the types the spec assumes. If `audit_trail` uses different column names (e.g. `created_at`, `user`, `schema`), update the SQL accordingly in task 1.1's predicate before commit. Do not invent a migration — the spec assumes existing columns.
  - acceptance_criteria: A quick `\OC::$server->getDatabaseConnection()->getSchemaManager()->listTableColumns('oc_audit_trail')` (or equivalent) confirms the three columns exist.

## 2. Tests

- [ ] 2.1 **Unit test: happy path**
  - spec_ref: REQ-ORDA-001, REQ-ORDA-005, REQ-ORDA-006
  - files: `tests/Unit/Db/AuditTrailMapperTest.php` (extend existing file; add new `testGetDistinctActorCount*` methods)
  - Seed audit_trail rows in `setUp` (or per-test): 2 rows for schema 1 by alice, 1 row for schema 2 by bob, 3 rows for schema 3 by carol — all within the last 24 hours.
  - Assert `getDistinctActorCount([1, 2, 3], 24) === 3`.
  - acceptance_criteria: Test passes against the configured DB; no flakiness from time-window edge cases.

- [ ] 2.2 **Unit test: actor counted once across multiple schemas**
  - spec_ref: REQ-ORDA-005
  - files: same as 2.1
  - Seed: 1 row per schema in `[1, 2, 3]` all by `user_id = "alice"`.
  - Assert `getDistinctActorCount([1, 2, 3], 24) === 1`.

- [ ] 2.3 **Unit test: NULL user_id rows excluded**
  - spec_ref: REQ-ORDA-004
  - files: same as 2.1
  - Seed: 1 row for schema 1 by alice, 1 by bob, 1 with `user_id = NULL` — all in the last 24h.
  - Assert `getDistinctActorCount([1], 24) === 2`.

- [ ] 2.4 **Unit test: empty `$schemaIds` short-circuits**
  - spec_ref: REQ-ORDA-002
  - files: same as 2.1
  - Use a query-counting wrapper or assert the DB connection's query log to confirm no SQL was issued. Assert returned value is `0`.

- [ ] 2.5 **Unit test: zero / negative `$hours` raises `\InvalidArgumentException`**
  - spec_ref: REQ-ORDA-003
  - files: same as 2.1
  - Two assertions: `expectException(\InvalidArgumentException::class)` for `getDistinctActorCount([1], 0)` and again for `getDistinctActorCount([1], -5)`.
  - Assert the exception message contains the string `"hours"`.

- [ ] 2.6 **Unit test: time-window boundary**
  - spec_ref: REQ-ORDA-006
  - files: same as 2.1
  - Two sub-cases:
    - Row at `now() - 48h` → excluded by `getDistinctActorCount([1], 24)`.
    - Row at exactly `now() - 24h` → included.
  - Use a fixed clock (or a sufficiently-large `$hours` slack) to avoid flakiness from second-level drift between test setup and assertion.

## 3. Quality gates

- [ ] 3.1 **`composer check:strict` passes** — PHPCS, PHPMD, Psalm, PHPStan all green on the modified file.
- [ ] 3.2 **PHPUnit passes** — all new tests pass; no existing audit-trail tests regress.
- [ ] 3.3 **Hydra gates pass** — SPDX, forbidden-patterns, stub-scan, composer-audit all green (the file already carried SPDX headers; the new method should not introduce any forbidden helpers or stubs).

## 4. Release coordination

- [ ] 4.1 **Tag the OR release that carries this method**
  - After merge, ensure the next OR semver release notes mention `AuditTrailMapper::getDistinctActorCount` so downstream apps know which floor to bump to.
  - openbuilt's `openbuilt-app-detail-overview` spec lists `openregister-distinct-actor-aggregation` in `depends_on` — its apply phase will block until this change is merged.

## 5. Documentation

- [ ] 5.1 **Update `docs/audit-trail.md`** (if such a doc page exists in openregister's docusaurus content)
  - Add a one-paragraph reference describing the new method, the empty-schema short-circuit, the NULL-user exclusion, and a one-line code example.
  - If no `docs/audit-trail.md` exists today, skip — do not create a new doc page for one method.
  - acceptance_criteria: Doc page renders cleanly via the existing docusaurus preset.
