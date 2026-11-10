# Tasks: platform-reference-provider

- [ ] 1.1 One `IReferenceProvider` resolving any object URL the deep link registry parses (D-2).
- [ ] 1.2 Resolution applies the reading user's access; no access means the bare link (D-1).
- [ ] 1.3 A declared card per schema: title, up to three summary properties, icon, optional image (D-3).
- [ ] 1.4 An undeclared schema renders title and schema name.
- [ ] 1.5 The smart picker offers objects over the same declaration.
- [ ] 2.1 `tests/e2e/ci/platform-reference-provider.spec.ts`: an object URL in a conversation renders a card for a reader and a bare link for a non-reader.
- [ ] 2.2 Unit tests: URL parsing, the access branch, the declared and undeclared card shapes.
- [ ] 2.3 `openspec validate platform-reference-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the card per schema, register no provider.
