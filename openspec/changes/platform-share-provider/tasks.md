# Tasks: platform-share-provider

- [ ] 1.1 An `IShareProvider` registered with the platform share manager (D-1).
- [ ] 1.2 The provider reads and writes the existing object share model, with no store of its own.
- [x] 1.3 Expiry and note fields carried from the platform's own share shape.
- [x] 1.4 Explicit permission bit mapping; an unmapped bit is refused naming the bit (D-2).
- [x] 1.5 A schema declaration opting its objects into platform sharing, defaulting to out (D-3).
- [ ] 2.1 `tests/e2e/ci/platform-share-provider.spec.ts`: share an object, find it in shared with you, fail an update on a read share.
- [ ] 2.2 Unit tests: one share in both lists, the refused bit, the opted-out schema.
- [ ] 2.3 `openspec validate platform-share-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the shareable schemas, build no sharing surface.

> **Measured 2026-11-13.** 1.3–1.5 are built: `PlatformSharePermissions` (read and
> update map; create, delete, share and undefined bits are refused naming the bit),
> the schema configuration key `platformSharing` (default out) and `PlatformShareGate`,
> which checks both and then writes through `ObjectSharingService::grantWithTerms()`,
> now carrying the platform share's note beside its expiry.
> 1.1/1.2 stay open on a finding: an object grant is already a core folder share
> (`ocinternal`) on the object's folder, and core routes "shared with you" by share
> type to its own providers. A second registered provider returning those same rows
> would list each share twice, which REQ-PSH-001's "the same share is there, once"
> forbids. What remains is routing the platform sharing surface on an object folder
> through the gate (e.g. `BeforeShareCreatedEvent::setError()` on an object folder),
> which needs a folder-to-object lookup and live verification. The opted-out half of
> 2.2 and the refused-bit half are covered; "one share in both lists" waits on 1.1.
