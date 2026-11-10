# Tasks: platform-collaboration-resources

- [ ] 1.1 A collaboration resource provider answering name, icon, link and access for any object (D-1).
- [ ] 1.2 The access answer resolved through the object read path, never reimplemented.
- [ ] 1.3 A schema declaration opting its objects into collections, defaulting to out (D-3).
- [ ] 1.4 Membership changes require update rights and are audited (D-2).
- [ ] 1.5 Reading an object returns its collections and the members the reader may access.
- [ ] 2.1 `tests/e2e/ci/platform-collaboration-resources.spec.ts`: add a case to a collection, see the folder beside it, fail to remove it as a reader.
- [ ] 2.2 Unit tests: the access branch, the opted-out schema, the audit entry.
- [ ] 2.3 `openspec validate platform-collaboration-resources --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare which schemas may join, render the collection, register no provider.
