## 1. Extract shared formatting services

- [x] 1.1 Create `lib/Service/Reference/ObjectPreviewFormatter.php`, extracting `extractTitle()`, `extractDescription()`, `extractPreviewProperties()`, `resolveSchemaName()`, `resolveRegisterName()`, the icon-resolution logic currently in `ObjectsProvider::resolveSchemaIcon()`, and the rich-object/`IReference` build logic out of `lib/Reference/ObjectReferenceProvider.php`; extract `parseReference()`'s three regex blocks into a single `PATTERNS` array of `{name, build, regex}` definitions (D4) so the recognizing and building directions share one source instead of two hand-synced lists
- [x] 1.2 Refactor `ObjectReferenceProvider` to delegate to `ObjectPreviewFormatter`, keeping `getId()`, `getTitle()`, `getOrder()`, `getIconUrl()`, `getSupportedSearchProviderIds()`, `getCacheKey()`, `getCachePrefix()` and all existing public behavior unchanged
- [x] 1.3 Create `lib/Service/Search/ObjectSearchResultFormatter.php`, extracting the icon-precedence, deep-link-URL, and subline/excerpt-building logic out of `lib/Search/ObjectsProvider.php` (`search()` lines ~475-605, `buildSubline()`, `buildExcerpt()`, `sliceExcerpt()`)
- [x] 1.4 Refactor `ObjectsProvider` to delegate result formatting to `ObjectSearchResultFormatter`, keeping `getId()`, `getName()`, `getSupportedFilters()`, `getCustomFilters()`, and all existing public behavior unchanged

## 2. `smartPickerEnabled` schema flag

- [x] 2.1 Add `Schema::$smartPickerEnabled` (protected bool, default `false`) with `isSmartPickerEnabled()`/`setSmartPickerEnabled()` (calling `markFieldUpdated()`) and `jsonSerialize()` inclusion, mirroring the existing `searchable` column's real convention (`isSearchable()`, not `getSearchable()` — avoids a PHPMD `BooleanGetMethodName` violation); add a new `Version1DateYYYYMMDDHHMMSS.php` migration (structurally identical to `Version1Date20250929120000`) that adds the `smart_picker_enabled` column to `openregister_schemas` with `notnull: true, default: false`
- [x] 2.2 Add `SchemaMapper::findSmartPickerEnabledIds(): array`, returning schema ids with `smartPickerEnabled = true`, mirroring `findSearchableIds()`

## 3. New abstract base classes

- [x] 3.1 Create `lib/AppHost/Reference/AbstractSchemaReferenceProvider.php` extending `ADiscoverableReferenceProvider`, implementing `ISearchableReferenceProvider`; a subclass implements only `getRegisterSlug()`/`getSchemaSlug()`. `getId()` and `getSupportedSearchProviderIds()` are `final`, computed as `openregister-ref-{registerSlug}-{schemaSlug}` and `openregister_objects_{registerSlug}_{schemaSlug}`; `getTitle()`/`getIconUrl()` are `final`, read live via `SchemaMapper::find()->getTitle()` and the shared icon-resolution helper (`openregister.icon.mdi`/`MdiIconRenderer`) from `ObjectPreviewFormatter`. `matchReference()`/`resolveReference()`/`getCachePrefix()` reuse `ObjectPreviewFormatter`, reject any parsed reference whose register/schema pair does not equal the resolved slugs, and short-circuit to false/null when the schema's `smartPickerEnabled` is `false`
- [x] 3.2 Create `lib/AppHost/Search/AbstractSchemaSearchProvider.php` implementing `OCP\Search\IProvider`; a subclass implements only `getRegisterSlug()`/`getSchemaSlug()`. `getId()` is `final`, computed as `openregister_objects_{registerSlug}_{schemaSlug}`; `getName()` is `final`, read live via `SchemaMapper::find()->getTitle()`. `search()` forces `@self.schema`/`@self.register` into the query, delegates to `ObjectService::searchObjectsPaginated(_rbac: true, _multitenancy: true)`, and returns an empty completed `SearchResult` when the schema's `smartPickerEnabled` is `false`

## 4. Cache invalidation gap fix — single pattern registry drives both directions

- [x] 4.1 Add `buildCanonicalUrls(registerId, schemaId, uuid): array` to `ObjectPreviewFormatter`, iterating the same `PATTERNS` array `parseReference()` uses (1.1) to forward-build every canonical URL; add `resolveCachePrefix(referenceText): string` (parse-and-collapse-or-passthrough) as the single method both `getCachePrefix()` implementations (1.2, D2) and the invalidation hook below delegate to — so no code path hand-types the `"{registerId}/{schemaId}/{uuid}"` format independently
- [x] 4.2 In `ObjectService::saveObject()`, after the save persists, resolve `IReferenceManager`/`ObjectPreviewFormatter`/`DeepLinkRegistryService` from the container; invalidate each unique prefix from `buildCanonicalUrls()` run through `resolveCachePrefix()`, plus the `DeepLinkRegistryService::resolveUrl()` result if non-null; wrap in try/catch(\Throwable), log a warning on failure, never fail the save

## 5. Public reference support gap fix

- [x] 5.1 Implement `OCP\Collaboration\Reference\IPublicReferenceProvider` on `ObjectReferenceProvider`: `resolveReferencePublic()` delegates to the existing `resolveReference()` (which already fails closed on RBAC denial via the null-`$userId` anonymous path); `getCacheKeyPublic()` returns the `$sharingToken`

## 6. Documentation corrections

- [x] 6.1 Update `openspec/specs/mail-smart-picker/spec.md` "Current Implementation Status": move the provider class, Vue widget, widget registration, and translation strings from "Not yet implemented" to "Fully implemented"; leave only cache invalidation and public reference support listed until Tasks 4-5 close them
- [x] 6.2 Update `openspec/specs/deep-link-registry/spec.md`'s boot-time-registration requirement to also describe the AppHost `GenericDeepLinkRegistrationListener` / manifest-driven (`src/manifest.json` `deepLinks` block) registration path used by Pipelinq and Procest, alongside the existing bespoke-listener description

## 7. Tests

- [x] 7.1 Unit tests for `ObjectPreviewFormatter`: all three `parseReference()` URL patterns via the shared `PATTERNS` array (hash-routed, API, direct route), `buildCanonicalUrls()` round-tripping back through `parseReference()` to the same triple for each pattern, `resolveCachePrefix()`'s parse-or-passthrough guard, title/description/preview-property extraction edge cases, and the extracted icon-resolution helper
- [x] 7.2 Unit tests for `ObjectSearchResultFormatter`: icon precedence order, subline composition, excerpt slicing
- [x] 7.3 Unit tests for `AbstractSchemaReferenceProvider`: the `getId()`/`getSupportedSearchProviderIds()` computed-id derivation from slugs, `getTitle()`/`getIconUrl()` sourced live from `SchemaMapper`, the schema-match guard (matching pair resolves, non-matching pair returns null/false even for an otherwise-valid URL), and `smartPickerEnabled = false` making `matchReference()`/`resolveReference()` fail while the class remains instantiable/registered
- [x] 7.4 Unit tests for `AbstractSchemaSearchProvider`: the `getId()` computed-id derivation, results confined to the configured schema, RBAC/multitenancy denial excluding results identically to `ObjectsProvider`, and `smartPickerEnabled = false` making `search()` return an empty completed `SearchResult` rather than an error
- [x] 7.5 Regression run of the existing `ObjectsProvider` and `ObjectReferenceProvider` PHPUnit suites, confirming unchanged output after the Task 1 extraction
- [x] 7.6 Unit tests for the cache-invalidation hook: `buildCanonicalUrls()`'s output all resolving to the expected deduplicated prefix via `resolveCachePrefix()`, the separate deep-link `invalidateCache()` call firing with the resolved URL, and for `resolveReferencePublic()`/`getCacheKeyPublic()`

## 8. Acceptance Criteria

- A consuming app can subclass `AbstractSchemaReferenceProvider` and `AbstractSchemaSearchProvider` by implementing only `getRegisterSlug()`/`getSchemaSlug()` and get a working, schema-scoped Smart Picker entry with a deterministically computed id, title, and icon — no id/title/icon configuration or other method overrides required.
- `openregister_objects` and `openregister-ref-objects` provider ids, registration, and observable output are unchanged before/after the refactor.
- Two schema-scoped providers for different (register, schema) pairs never produce colliding ids, by construction.
- Editing an object invalidates its cached Smart Picker/reference preview so the next resolution reflects the new data.
- An anonymous viewer of a publicly-shared object (per the object's `_authorization` block) sees a rich preview card instead of a plain link; a viewer of a non-public object sees a plain link, never leaked metadata.
- With `smartPickerEnabled = false` on a schema whose provider classes are registered, a test confirms the provider's entry remains in the registered provider list while `matchReference()`/`resolveReference()`/`search()` all behave as functionally inert (no match, no resolve, zero results); flipping the flag back to `true` restores normal behavior with no redeploy.
- `openspec/specs/mail-smart-picker/spec.md` and `openspec/specs/deep-link-registry/spec.md` accurately describe current implementation state after this change.

## 9. Quality Checklist

- All new/changed PHP files carry SPDX license headers and `@spec` tags per this change's spec deltas.
- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes on all new and modified files.
- No debug helpers (`var_dump`, `print_r`, `error_log`) left in shipped code.
- Existing `mail-smart-picker` and `deep-link-registry` PHPUnit coverage passes unchanged.
- No breaking change to `Application::register()`'s existing two registration lines.
- New `Schema::$smartPickerEnabled` migration is idempotent (`hasColumn()` guard) and defaults existing schemas to `false`, matching the `searchable` migration's pattern.
