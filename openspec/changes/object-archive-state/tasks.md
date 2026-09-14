# Tasks: object-archive-state

## 1. The marker and the guard

- [ ] 1.1 `@self.archived` (`by`, `at`, `reason`) written outside the object's data, with an audit entry on archive and on unarchive (D-2).
- [ ] 1.2 Write guard: a write to an archived object's data is refused with a message naming the archive, the archiver and the time (D-3).
- [ ] 1.3 `x-openregister-archive` accepted by the schema annotation validator; the endpoint refuses 422 on a schema that does not declare it (D-5).

## 2. API

- [ ] 2.1 Routes `POST` and `DELETE /api/objects/{register}/{schema}/{id}/archive`, requiring `update` (openregister ADR-010).
- [ ] 2.2 `@self.archived` rendered on object reads and lists.

## 3. Exclusion

- [ ] 3.1 Query parser: archived objects excluded by default, `_archived=true` and `_archived=any` (D-4).
- [ ] 3.2 The same default in the aggregation endpoint and in every search provider, so a tile and its list agree.

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/object-archive-state.spec.ts`: archive, confirm it leaves the list, confirm the write refusal, restore.
- [ ] 4.2 Unit tests for the guard, the query default, the aggregation default and the annotation validator; Newman for the routes.
- [ ] 4.3 `openspec validate object-archive-state --strict`.
