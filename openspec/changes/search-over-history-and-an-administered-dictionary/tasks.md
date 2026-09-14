# Tasks: search-over-history-and-an-administered-dictionary

## 1. The projection

- [ ] 1.1 A narrow, indexed projection of lifecycle transitions: object, property, value, entered, left.
- [ ] 1.2 A rebuild from the recorded transitions, resumable and bounded.
- [ ] 1.3 The projection is pruned with the trail it derives from.

## 2. The predicate

- [ ] 2.1 A `was ever` filter over a property's historical values in the object query grammar.
- [ ] 2.2 A `changed between` filter over a period.
- [ ] 2.3 The predicate is compiled into the same access-filtered query as the current-state filters.
- [ ] 2.4 A predicate naming a property with no projection is refused, naming the property.

## 3. The dictionary

- [ ] 3.1 A synonym-group and stopword register, per language, with an admin surface.
- [ ] 3.2 Query-time expansion under an administered cap, per group and per query.
- [ ] 3.3 Stopword removal that would empty a query falls back to the original query.
- [ ] 3.4 The response reports the terms the query expanded to.

## 4. Tests

- [ ] 4.1 Unit tests for the predicate compilation, the access filter, the expansion cap and the empty-query fallback.
- [ ] 4.2 An e2e over a list filtered by a state a case has left.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.
