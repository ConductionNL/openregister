# Tasks: platform-search-provider

- [ ] 1.1 A provider identity per claiming app, over the one query implementation (D-1).
- [ ] 1.2 The identity resolved from the deep link claim, not from a second declaration (D-2).
- [ ] 1.3 Searching within one identity returns only that app's claimed pairs.
- [ ] 1.4 An unclaimed pair keeps the OpenRegister identity, with a regression test.
- [ ] 1.5 A schema declares title, subline and ordering date; an undeclared schema keeps today's derivation (D-3).
- [ ] 2.1 `tests/e2e/ci/platform-search-provider.spec.ts`: two apps, one search narrowed to the first.
- [ ] 2.2 Unit tests: identity resolution, the unclaimed fall-back, the declared result fields.
- [ ] 2.3 `openspec validate platform-search-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the claim and the result fields, register no provider.
