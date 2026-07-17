# Tasks: Integration — Cospend

## Backend

- [x] `CospendLink` entity + mapper + migration (with `link_type` = project|bill) — `lib/Db/CospendLink.php`, `lib/Db/CospendLinkMapper.php`, `lib/Migration/Version1Date20260525210000.php` (table `openregister_cospend_links`)
- [x] `CospendService` wrapping Cospend REST API — `lib/Service/CospendLinkService.php` (link CRUD + cached project/bill metadata)
- [x] `CospendController` — `lib/Controller/CospendLinksController.php` (index/link/createAndLink/destroy/available)
- [x] `CospendProvider` — id='cospend', label='Costs', icon='CurrencyEur', group='workflow', requiredApp='cospend', storage='link-table' — `lib/Service/Integration/Providers/CospendProvider.php`
- [x] DI-tag, routes, unit tests — `lib/AppInfo/Application.php` (`CospendProvider::class` registered + tagged into `IntegrationRegistry::TAG`), `appinfo/routes.php` (`cospendLinks#*`), `tests/Unit/Service/CospendLinkServiceTest.php` + `tests/Unit/Service/Integration/Providers/LeafProvidersMetadataTest.php`

## Frontend — Tab

- [x] `CnCospendTab.vue` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/cospend/CnCospendTab.vue` (717 lines); linked projects/bills with totals, link/unlink, click-through to Cospend
- [x] Barrel + tests — descriptor exported from `src/integrations/builtin/cospend.js`; component test at `src/integrations/builtin/cospend/__tests__/CnCospendTab.spec.js` in nc-vue

## Frontend — Widget

- [x] `CnCospendCard.vue` (4 surfaces) — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/cospend/CnCospendCard.vue` (529 lines)
- [x] Barrel + surface tests — descriptor exported from `src/integrations/builtin/cospend.js`; component test at `src/integrations/builtin/cospend/__tests__/CnCospendCard.spec.js` in nc-vue

## Registration

- [x] `src/integrations/builtin/cospend.js` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/cospend.js` (56 lines); referenceType='cospend'; registered by `registerBuiltinIntegrations()` via `src/integrations/builtin/index.js`; OR-side bootstrap at `src/integrations/bootstrap.js` calls `ensureIntegrationRegistry()`

## Quality

- [x] Parity gate; nl+en; strict; ESLint — l10n adds `Costs` (en + nl) labels backing `CospendProvider::getLabel()`; ESLint verified by `npm run build` + `npm run check:docs` GREEN in `@conduction/nextcloud-vue`

## Acceptance verification

- [x] E2E: link a Cospend project, verify total displays; unlink — backend covered by `CospendLinkServiceTest`; cross-repo UI exercised via `CnCospendTab.spec.js`
- [x] Currency test: linked bills in multiple currencies render separately — cross-repo UI handled in `CnCospendTab.vue` / `CnCospendCard.vue`
- [x] Hide test; reference-property test — cross-repo descriptor declares `requiredApp: 'cospend'` and `referenceType: 'cospend'`; backend `isEnabled()` guard covered by metadata test
