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

## Discovery cluster 29

- [ ] C29.1 A frozen state beside archived: visible, searchable, refusing data writes, with an authorised unfreeze (D-C29-1).
- [ ] C29.2 A lifecycle state may declare that entering it freezes the object (D-C29-2).
- [ ] C29.3 An immutable-once-set property rule, refused on change whatever the state (D-C29-3).
- [ ] C29.4 An object closed to new entries while staying readable.
- [ ] C29.5 A withdrawn entry: out of the working timeline, in the record, readable with actor and reason (D-C29-4).
- [ ] C29.6 A locked note refusing edits, beside `note-edit-history`.
- [ ] C29.7 Freezing, closing, withdrawing and locking on the audit trail.
- [ ] C29.8 Tests: frozen appears in search and refuses a write, archived does not appear, the immutable refusal, the withdrawal readable by an authorised reader.
- [ ] C29.9 Hand over to the dossiq lane with candidate ids C-case-core-8, C-tasks-and-phases-35, C-documents-5, C-communication-4, C-communication-12 and C-communication-13.
