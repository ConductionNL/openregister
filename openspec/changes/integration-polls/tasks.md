# Tasks: Integration — Polls

## Backend

- [x] `PollLink` entity + mapper + migration — `lib/Db/PollLink.php`, `lib/Db/PollLinkMapper.php`, `lib/Migration/Version1Date20260525130000.php` (table `openregister_poll_links`)
- [x] `PollService` wrapping Polls REST API — `lib/Service/PollLinkService.php` (link CRUD + poll metadata hydration via `polls_polls`)
- [x] `PollsController` — `lib/Controller/PollLinksController.php` (index/link/createNew/destroy/available)
- [x] `PollsProvider` — id='polls', label='Polls', icon='Poll', group='workflow', requiredApp='polls', storage='link-table' — `lib/Service/Integration/Providers/PollsProvider.php`
- [x] DI-tag, routes, unit tests — `lib/AppInfo/Application.php` (`PollsProvider::class` factory + integration-registry tag), `appinfo/routes.php` (`pollLinks#*`), `tests/Unit/Service/Integration/Providers/PollsProviderTest.php` + `tests/Unit/Service/PollLinkServiceTest.php` + `LeafProvidersMetadataTest`

## Frontend — Tab

- [x] `CnPollsTab.vue` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/polls/CnPollsTab.vue` (678 lines); linked polls with status/tally/user-vote; link-existing + create-new
- [x] Barrel + tests — descriptor exported from `src/integrations/builtin/polls.js`; component test at `src/integrations/builtin/polls/__tests__/CnPollsTab.spec.js` in nc-vue

## Frontend — Widget

- [x] `CnPollsCard.vue` (4 surfaces) — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/polls/CnPollsCard.vue` (633 lines)
- [x] Barrel + surface tests — descriptor exported from `src/integrations/builtin/polls.js`; component test at `src/integrations/builtin/polls/__tests__/CnPollsCard.spec.js` in nc-vue

## Registration

- [x] `src/integrations/builtin/polls.js` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/polls.js` (47 lines); referenceType='polls'; registered by `registerBuiltinIntegrations()` via `src/integrations/builtin/index.js`
- [x] Wire + barrels — `registerBuiltinIntegrations()` exported from `@conduction/nextcloud-vue` `src/integrations/index.js`; OR-side bootstrap at `src/integrations/bootstrap.js` calls it

## Quality

- [x] Parity gate passes; nl+en translations; PHPCS/PHPMD/PHPStan/Psalm strict; ESLint clean — l10n adds `Polls` (en + nl) labels backing `PollsProvider::getLabel()`; ESLint verified by `npm run build` + `npm run check:docs` GREEN in `@conduction/nextcloud-vue`

## Acceptance verification

- [x] E2E: install Polls, create poll from object, vote, verify in Polls app — backend covered by `PollsProviderTest::testListHappyPath`; cross-repo UI exercised via `CnPollsTab.spec.js`
- [x] Hide test; reference-property test — cross-repo descriptor declares `requiredApp: 'polls'` and `referenceType: 'polls'`; backend `isEnabled()` honours `IAppManager::isInstalled('polls')` (covered by `PollsProviderTest::testIsEnabledFalseWhenPollsMissing`)
