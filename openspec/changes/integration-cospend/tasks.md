# Tasks: Integration — Cospend

## Backend

- [x] `CospendLink` entity + mapper + migration (with `link_type` = project|bill) — `lib/Db/CospendLink.php`, `lib/Db/CospendLinkMapper.php`, `lib/Migration/Version1Date20260525210000.php` (table `openregister_cospend_links`)
- [x] `CospendService` wrapping Cospend REST API — `lib/Service/CospendLinkService.php` (link CRUD + cached project/bill metadata)
- [x] `CospendController` — `lib/Controller/CospendLinksController.php` (index/link/createAndLink/destroy/available)
- [x] `CospendProvider` — id='cospend', label='Costs', icon='CurrencyEur', group='workflow', requiredApp='cospend', storage='link-table' — `lib/Service/Integration/Providers/CospendProvider.php`
- [x] DI-tag, routes, unit tests — `lib/AppInfo/Application.php` (`CospendProvider::class` registered + tagged into `IntegrationRegistry::TAG`), `appinfo/routes.php` (`cospendLinks#*`), `tests/Unit/Service/CospendLinkServiceTest.php` + `tests/Unit/Service/Integration/Providers/LeafProvidersMetadataTest.php`

## Frontend — Tab

- [~] `CnCospendTab.vue` — linked projects/bills with totals, link/unlink, click-through to Cospend → cross-repo: lives in `@conduction/nextcloud-vue` (per design.md cross-repo note); this app only exposes the REST surface
- [~] Barrel + tests — same cross-repo location

## Frontend — Widget

- [~] `CnCospendCard.vue` (4 surfaces) → cross-repo `@conduction/nextcloud-vue`
- [~] Barrel + surface tests → cross-repo

## Registration

- [~] `src/integrations/builtin/cospend.js` — referenceType='cospend' → cross-repo: registered by `registerBuiltinIntegrations()` in `@conduction/nextcloud-vue`; this app calls `ensureIntegrationRegistry()` from `src/integrations/bootstrap.js`

## Quality

- [x] Parity gate; nl+en; strict; ESLint — l10n adds `Costs` (en + nl) labels backing `CospendProvider::getLabel()`

## Acceptance verification

- [~] E2E: link a Cospend project, verify total displays; unlink → frontend e2e lives in `@conduction/nextcloud-vue`; backend covered by `CospendLinkServiceTest`
- [~] Currency test: linked bills in multiple currencies render separately → cross-repo UI test
- [~] Hide test; reference-property test → cross-repo UI tests; backend `isEnabled()` guard covered by metadata test
