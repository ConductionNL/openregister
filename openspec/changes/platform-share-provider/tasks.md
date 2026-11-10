# Tasks: platform-share-provider

- [ ] 1.1 An `IShareProvider` registered with the platform share manager (D-1).
- [ ] 1.2 The provider reads and writes the existing object share model, with no store of its own.
- [ ] 1.3 Expiry and note fields carried from the platform's own share shape.
- [ ] 1.4 Explicit permission bit mapping; an unmapped bit is refused naming the bit (D-2).
- [ ] 1.5 A schema declaration opting its objects into platform sharing, defaulting to out (D-3).
- [ ] 2.1 `tests/e2e/ci/platform-share-provider.spec.ts`: share an object, find it in shared with you, fail an update on a read share.
- [ ] 2.2 Unit tests: one share in both lists, the refused bit, the opted-out schema.
- [ ] 2.3 `openspec validate platform-share-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the shareable schemas, build no sharing surface.
