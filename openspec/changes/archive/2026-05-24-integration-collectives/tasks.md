# Tasks: Integration — Collectives

## Backend

- [x] `CollectivesProvider` — id='collectives', label='Knowledge', icon='BookOpenPageVariant', group='docs', requiredApp='collectives', storage='link-table'
- [x] Replace 137-line `MarkerLookupTrait` stub with real implementation using `OCA\Collectives\Db\PageMapper` + `OCA\Collectives\Service\CollectiveService` (lazy via `\OCP\Server::get()` so the provider stays constructible when Collectives is absent)
- [x] `extends AbstractIntegrationProvider`
- [x] `health()` returns `status: unavailable` when NC Collectives is missing (mirrors DeckProvider / FormsProvider shape; never throws)
- [x] `list()` lists pages whose `slug` carries the `[or:{objectUuid}]` marker; soft-deleted pages (`trash_timestamp != null`) are filtered out
- [x] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [x] PHPUnit tests cover happy-path (app installed + linked page), absent-app (graceful empty), empty-result (app installed but no marker match), soft-deleted-page filter, mapper-unreachable degrade, health() ok/unavailable transitions
- [x] DI registration in `Application.php` left intact — constructor signature `(IDBConnection, IAppManager, IL10N)` preserved so `LeafProvidersMetadataTest` and the existing greenfield-provider builder still pass

## Out of scope (deferred — file a follow-up if needed)

- [ ] `CollectiveLink` entity + mapper + migration — deferred (current convention uses a slug marker on the upstream page, no OR-side table required)
- [ ] `CollectivesPageService` wrapping Collectives REST API — deferred until link-table storage is required
- [ ] `CollectivesController` — deferred (registry routes dispatch through the provider directly today)
- [ ] Frontend `CnCollectivesTab.vue` — proposal explicitly keeps the generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js`; bespoke Tab + Widget components are out of this change's scope
- [ ] Frontend `CnCollectivesCard.vue` widget surfaces — same scope note as above
- [ ] `src/integrations/builtin/collectives.js` bespoke registration — generic leaf shell already in place
- [ ] E2E browser test — frontend follow-up

## Acceptance verification

- [x] `phpunit-unit.xml` filter `CollectivesProviderTest` — 7 tests / 20 assertions green (with NC Collectives enabled in the dev container)
- [x] `phpunit-unit.xml` filter `LeafProvidersMetadataTest` — 38 tests / 183 assertions green; provider continues to honour the greenfield-stub metadata contract
- [x] Provider has zero `use MarkerLookupTrait` references — the trait was removed from the storage='link-table' path per the acceptance criterion
- [x] Real `OCA\Collectives\Db\PageMapper` + `OCA\Collectives\Service\CollectiveService` imports present in the file
