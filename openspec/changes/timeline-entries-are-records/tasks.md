# Tasks: timeline-entries-are-records

## 1. Entry kinds

- [x] 1.1 A declared kind on an entry, with its own validated properties (D-2).
- [x] 1.2 A follow-up state of open or done on a kind, with closer and time (D-3).
- [x] 1.3 An entry with no kind behaves as a plain note, with a regression test.

## 2. Pin and the inbound source

- [x] 2.1 An authorised pin, sorting first and naming who pinned (D-4).
- [x] 2.2 The raw inbound message and its headers stored beside the entry, under the entry's access (D-5).
- [x] 2.3 The detected language recorded on the entry and indexed.

## 3. References and multi-object notes

- [x] 3.1 An administered reference pattern and its target (D-6).
- [x] 3.2 A match renders as a link and records a reference on both sides; removing the text removes it.
- [x] 3.3 One note written to several related objects, each entry naming the others.
- [x] 3.4 Administered canned text blocks, scoped, with template variable substitution.

## 4. Mentions

- [x] 4.1 A mention notifies and subscribes through `object-watchers` (D-7).
- [x] 4.2 A principal who may not read the object is neither notified nor subscribed.

## 5. Entry search

- [x] 5.1 Entries indexed as their own kind with object, kind, author, time, visibility and fields (D-1).
- [x] 5.2 Access and visibility applied inside the query, not on the result page.
- [x] 5.3 A hit names the object and the entry.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/timeline-entries.spec.ts`: a contactmoment with its fields, a pin, a reference link, an entry search across three cases.
- [x] 6.2 Unit tests: kind validation, the follow-up close, the raw source access, the language record, the reference removal, the mention that cannot grant access, the visibility filter in the query.
- [x] 6.3 `openspec validate timeline-entries-are-records --strict`.

## 7. Hand over

- [ ] 7.1 Hand the entry kinds to the dossiq lane for the contactmoment declaration, with the fifteen candidate ids.
- [ ] 7.2 Tell the portaliq lane that the public entry search is the subject-scoped reader's path.
