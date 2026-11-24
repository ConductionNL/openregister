# Tasks: platform-search-provider

- [x] 1.1 A provider identity per claiming app, over the one query implementation (D-1).
- [x] 1.2 The identity resolved from the deep link claim, not from a second declaration (D-2).
- [x] 1.3 Searching within one identity returns only that app's claimed pairs.
- [x] 1.4 An unclaimed pair keeps the OpenRegister identity, with a regression test.
- [x] 1.5 A schema declares title, subline and ordering date; an undeclared schema keeps today's derivation (D-3).
- [ ] 2.1 `tests/e2e/ci/platform-search-provider.spec.ts`: two apps, one search narrowed to the first.
- [x] 2.2 Unit tests: identity resolution, the unclaimed fall-back, the declared result fields.
- [ ] 2.3 `openspec validate platform-search-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the claim and the result fields, register no provider.

> **Built 2026-11-24.** 1.1–1.4: `SearchProviderIdentities` reads one identity per
> claiming app from the deep link registry's claims (D-2; the registry keeps the
> first claim of a pair, so each pair has one owner), named by the app's display
> name and ordered after OpenRegister's own order in first-claim order. An
> unclaimed pair belongs to the `openregister` identity. `ObjectsProvider` takes
> the identity in its `provider` filter and narrows the searchable schemas to that
> identity's pairs before the chunk loop, over the one query path; an id that
> names no identity searches nothing, and no filter searches everything as before.
> `SearchScopesCapability` advertises `providers` (the identities owning a schema
> the reader may read) beside `providerFilter`, so a client draws one entry per
> app. Nextcloud registers a search provider per class, so the platform's own
> provider list still shows the one `openregister_objects` entry; a client offers
> the identities from the capability. That platform-side presentation is checked
> live in 2.1.
> 2.2: `SearchProviderIdentitiesTest`, the identity tests in `ObjectsProviderTest`
> and `SearchScopesCapabilityTest`; the declared result fields were covered with 1.5.
