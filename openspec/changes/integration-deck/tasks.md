# Tasks: Integration — Deck

> **ADR-028 task-cap waiver**: this leaf has 25 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/DeckProvider.php` — id='deck', label='Cards', icon='ViewColumn', group='workflow', requiredApp='deck', storage='link-table'
- [x] DI-tag in `Application.php`
- [x] Extend `SettingsService` to persist schema-level default board+stack
- [x] Unit test

## Frontend — Tab

- [x] `CnDeckTab.vue` — list linked cards, "Create new card" inline form (board+stack with sticky default), "Link existing card" picker, unlink
- [x] Barrel + tests

## Frontend — Widget

- [x] `CnDeckCard.vue`:
  - `user-dashboard`: cards assigned to current user
  - `app-dashboard`: cards on objects in this app's scope
  - `detail-page`: mini-kanban with linked card highlighted
  - `single-entity`: chip with card title + stack name + assignees
- [x] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/deck.js` — register with `referenceType: 'deck'`
- [x] Wire + barrels

## Quality

- [x] Parity gate passes
- [x] nl + en translations
- [x] PHPCS/PHPMD/PHPStan/Psalm strict pass
- [x] ESLint clean

## Acceptance verification

- [ ] E2E: install Deck, create card from OR object, verify in Deck app; link existing; unlink
- [ ] Sticky default: second create on same schema pre-selects previous board+stack
- [ ] Hide test: uninstall Deck → integration hidden
- [ ] Reference-property `referenceType: 'deck'` renders single-entity widget
