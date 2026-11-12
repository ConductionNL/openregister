# Tasks: search-deep-link-names-object-properties

- [x] 1. `lib/Service/Search/ObjectSearchResultFormatter.php`: pass the object's own scalar properties (declared, URL-encoded) to the deep-link registry under `@self` and the ids; fall back to `openregister.objects.show` when a placeholder stays unfilled.
  - unit tests in `tests/Unit/Service/Search/ObjectSearchResultFormatterTest.php`: `testADeepLinkTemplateCanNameAnOwnProperty`, `testMetadataWinsOverAnOwnPropertyOfTheSameName`, `testOwnPropertiesAreEncodedAndOnlyDeclaredScalarsPass`, `testAnUnfilledPlaceholderFallsBackToTheOpenRegisterRoute`.
- [ ] 2. Live: with dossiq's `{case}` deep links registered, search a seeded `caseDocument` title in the Nextcloud header; the hit opens `/apps/dossiq/cases/<case uuid>` (live pass, decision 139).
