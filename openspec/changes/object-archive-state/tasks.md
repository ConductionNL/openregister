# Tasks: object-archive-state

## 1. The marker and the guard

- [x] 1.1 `@self.archived` (`by`, `at`, `reason`) written outside the object's data, with an audit entry on archive and on unarchive (D-2).
- [x] 1.2 Write guard: a write to an archived object's data is refused with a message naming the archive, the archiver and the time (D-3).
- [x] 1.3 `x-openregister-archive` accepted by the schema annotation validator; the endpoint refuses 422 on a schema that does not declare it (D-5).

## 2. API

- [x] 2.1 Routes `POST` and `DELETE /api/objects/{register}/{schema}/{id}/archive`, requiring `update` (openregister ADR-010).
- [x] 2.2 `@self.archived` rendered on object reads and lists.

## 3. Exclusion

- [x] 3.1 Query parser: archived objects excluded by default, `_archived=true` and `_archived=any` (D-4).
- [x] 3.2 The same default in the aggregation endpoint and in every search provider, so a tile and its list agree.

## 4. Tests

- [x] 4.1 `tests/e2e/ci/object-archive-state.spec.ts`: archive, confirm it leaves the list, confirm the write refusal, restore.
- [x] 4.2 Unit tests for the guard, the query default, the aggregation default and the annotation validator; Newman for the routes.
- [x] 4.3 `openspec validate object-archive-state --strict`.

## Discovery cluster 29

- [x] C29.1 A frozen state beside archived: visible, searchable, refusing data writes, with an authorised unfreeze (D-C29-1).
- [x] C29.3 An immutable-once-set property rule, refused on change whatever the state (D-C29-3).
- [x] C29.7 Freezing and unfreezing on the audit trail.
- [x] C29.8 Tests: frozen appears in the list and refuses a write, archived does not appear, the immutable refusal.

### Not in the first PR, and why

- [ ] C29.2 A lifecycle state may declare that entering it freezes the object (D-C29-2).

  The freeze itself ships, and `@self.frozen` already carries the `state` that
  declared it, so the marker this needs is in place. What is missing is the
  listener that reads a `freezesObject` key off
  `x-openregister-lifecycle.states` and calls it. That listener has to sit
  beside `ArchivalNominationListener`, which the archiving lane is still
  editing on its third PR. Two lanes registering listeners on
  `ObjectTransitionedEvent` in the same file is how one of them gets dropped in
  a merge.

- [ ] C29.4 An object closed to new entries while staying readable.
- [ ] C29.5 A withdrawn entry: out of the working timeline, in the record, readable with actor and reason (D-C29-4).
- [ ] C29.6 A locked note refusing edits, beside `note-edit-history`.

  All three are about TIMELINE ENTRIES and NOTES, not about the object. They
  belong in the files the timeline lane owns, and building half of them here
  would collide with that lane and leave the other half in place claiming a
  requirement is met. The object-level primitives they will build on are done:
  `ObjectStateWriteException` already carries a named state and an actor, and
  the `frozen` marker is the pattern a per-entry lock copies.

- [ ] C29.9 Hand over to the dossiq lane with candidate ids C-case-core-8,
      C-tasks-and-phases-35, C-documents-5, C-communication-4,
      C-communication-12 and C-communication-13.

  The contract the consumers need is in the PR body: the two states, the four
  endpoints, the query flag and the `immutable` property keyword. C-case-core-8
  (frozen and readable), C-tasks-and-phases-35 (frozen by a closing phase, once
  C29.2 lands) and C-documents-5 (a final document) are answered by what ships
  here. C-communication-4, -12 and -13 wait on the timeline lane.
