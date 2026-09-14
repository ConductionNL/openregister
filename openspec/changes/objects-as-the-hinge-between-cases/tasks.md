# Tasks: objects-as-the-hinge-between-cases

## 1. The reverse view

- [ ] 1.1 One bounded query per schema group answering which objects reference this one (D-1).
- [ ] 1.2 Title, status and last change per referencing record, paged per group.
- [ ] 1.3 The caller's access applied inside the query.

## 2. The lens

- [ ] 2.1 A lens property declaring a reference property and a property to read through it (D-2).
- [ ] 2.2 Resolved at read, never stored, refused on write.
- [ ] 2.3 Withheld rather than empty when the referenced object is unreadable (D-3).

## 3. The generic list surface

- [ ] 3.1 Declared list columns and search fields per schema (D-4).
- [ ] 3.2 The generic surface renders them for any schema; an undeclared schema keeps today's defaults.

## 4. Geography and intake sources

- [ ] 4.1 Declared geographic inheritance from referenced objects and parties, each feature naming its relation (D-5).
- [ ] 4.2 A feature on the record outranks an inherited one.
- [ ] 4.3 An intake source as an object: several, switchable, permissioned, audited (D-6).

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/object-as-hinge.spec.ts`: an address with three referencing records, a lens that follows a change, a second intake source.
- [ ] 5.2 Unit tests: the reverse query's access filter, the lens write refusal, withheld versus empty, the geographic precedence.
- [ ] 5.3 A regression test that a schema declaring none of this behaves as today.
- [ ] 5.4 `openspec validate objects-as-the-hinge-between-cases --strict`.

## 6. Hand over

- [ ] 6.1 Hand the reverse view and the lens to the dossiq lane, which declares the object types a case type may reference, with the seven candidate ids.
- [ ] 6.2 Tell the buildiq lane that CT-6, the layout per case type, renders these and is not specified here.
