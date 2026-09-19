# Tasks: platform-comments-entity

- [ ] 1.1 One entity collection per claiming app, registered from the existing listener (D-1).
- [ ] 1.2 A validation closure per collection accepting only that app's claimed pairs.
- [ ] 1.3 The `openregister` collection stays registered; existing comments resolve unchanged (D-2).
- [ ] 1.4 A display name and icon per collection.
- [ ] 1.5 Readability stays the object's readability, whichever collection was used (D-3).
- [ ] 2.1 `tests/e2e/ci/platform-comments-entity.spec.ts`: a comment through an app's collection, refused for a foreign object.
- [ ] 2.2 Unit tests: the per-collection closure, the legacy collection, the visibility check.
- [ ] 2.3 `openspec validate platform-comments-entity --strict`.
- [ ] 3.1 Hand over to the dossiq lane: claim the pairs, register no listener.
