# Tasks: Integration — Polls

## Backend

- [x] `PollLink` entity + mapper + migration — `lib/Db/PollLink.php`, `lib/Db/PollLinkMapper.php`, `lib/Migration/Version1Date20260525130000.php` (table `openregister_poll_links`)
- [x] `PollService` wrapping Polls REST API — `lib/Service/PollLinkService.php` (link CRUD + poll metadata hydration via `polls_polls`)
- [x] `PollsController` — `lib/Controller/PollLinksController.php` (index/link/createNew/destroy/available)
- [x] `PollsProvider` — id='polls', label='Polls', icon='Poll', group='workflow', requiredApp='polls', storage='link-table' — `lib/Service/Integration/Providers/PollsProvider.php`
- [x] DI-tag, routes, unit tests — `lib/AppInfo/Application.php` (`PollsProvider::class` factory + integration-registry tag), `appinfo/routes.php` (`pollLinks#*`), `tests/Unit/Service/Integration/Providers/PollsProviderTest.php` + `tests/Unit/Service/PollLinkServiceTest.php` + `LeafProvidersMetadataTest`

## Frontend — Tab

- [~] `CnPollsTab.vue` — linked polls with status/tally/user-vote; link-existing + create-new → cross-repo: `@conduction/nextcloud-vue` (per design.md cross-repo note)
- [~] Barrel + tests → cross-repo

## Frontend — Widget

- [~] `CnPollsCard.vue` (4 surfaces) → cross-repo `@conduction/nextcloud-vue`
- [~] Barrel + surface tests → cross-repo

## Registration

- [~] `src/integrations/builtin/polls.js` — referenceType='polls' → cross-repo `@conduction/nextcloud-vue` (registered by `registerBuiltinIntegrations()`)
- [~] Wire + barrels → cross-repo

## Quality

- [x] Parity gate passes; nl+en translations; PHPCS/PHPMD/PHPStan/Psalm strict; ESLint clean — l10n adds `Polls` (en + nl) labels backing `PollsProvider::getLabel()`

## Acceptance verification

- [~] E2E: install Polls, create poll from object, vote, verify in Polls app → cross-repo UI e2e; backend covered by `PollsProviderTest::testListHappyPath`
- [~] Hide test; reference-property test → cross-repo UI tests; backend `isEnabled()` honours `IAppManager::isInstalled('polls')` (covered by `PollsProviderTest::testIsEnabledFalseWhenPollsMissing`)
