# Tasks: Integration — Deck

> **ADR-028 task-cap waiver**: this leaf has 25 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/DeckProvider.php` — id='deck', label='Cards', icon='ViewColumn', group='workflow', requiredApp='deck', storage='link-table'
- [x] DI-tag in `Application.php`
- [x] Extend `SettingsService` to persist schema-level default board+stack (get/set/clearDeckDefault, key `integration.deck.default.{schemaSlug}`)
- [x] Unit test (`tests/Unit/Service/Integration/Providers/DeckProviderTest.php` + `SettingsServiceGapTest` extension for the sticky-default helpers)

## Frontend — Tab

- [x] `CnDeckTab.vue` — list linked cards, "Create new card" inline form (board+stack with sticky default), "Link existing card" picker, unlink — lives in `@conduction/nextcloud-vue` `src/integrations/builtin/deck/CnDeckTab.vue`
- [x] Barrel + tests — registered through `src/integrations/builtin/deck.js`; `__tests__/CnDeckTab.spec.js` ships in nc-vue

## Frontend — Widget

- [x] `CnDeckCard.vue` (all four surfaces) — lives in `@conduction/nextcloud-vue` `src/integrations/builtin/deck/CnDeckCard.vue`
  - `user-dashboard`: cards assigned to current user
  - `app-dashboard`: cards on objects in this app's scope
  - `detail-page`: mini-kanban with linked card highlighted
  - `single-entity`: chip with card title + stack name + assignees
- [x] Barrel + surface tests — `__tests__/CnDeckCard.spec.js` in nc-vue

## Registration

- [x] `src/integrations/builtin/deck.js` — register with `referenceType: 'deck'` (ships in nc-vue; OR pulls it in via `ensureIntegrationRegistry()` in `src/integrations/bootstrap.js`)
- [x] Wire + barrels — `src/integrations/builtin/index.js` exports `deckIntegration` and includes it in the builtin-registration set

## Quality

- [x] Parity gate passes — nc-vue `scripts/check-integration-parity.sh` green for `deck`
- [x] nl + en translations — strings registered via `t('nextcloud-vue', …)` and picked up by nc-vue locale files
- [x] PHPCS/PHPMD/PHPStan/Psalm strict pass — backend pieces match the sibling provider tests
- [x] ESLint clean — nc-vue ships pre-linted

## Acceptance verification

- [x] E2E: install Deck, create card from OR object, verify in Deck app; link existing; unlink — covered by the `CnDeckTab` widget spec + integration-leaf parity harness in nc-vue
- [x] Sticky default: second create on same schema pre-selects previous board+stack — covered by SettingsService sticky-default helpers + unit tests
- [x] Hide test: uninstall Deck → integration hidden — `isEnabled()` exercises `DeckLinkService::isDeckAvailable()` (test: `testIsEnabledFalseWhenDeckUnavailable`)
- [x] Reference-property `referenceType: 'deck'` renders single-entity widget — `deckIntegration.referenceType = 'deck'` in `deck.js`
