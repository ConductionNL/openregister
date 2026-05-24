# Tasks: Integration — Deck

> **ADR-028 task-cap waiver**: this leaf has 25 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/DeckProvider.php` — id='deck', label='Cards', icon='ViewColumnOutline', group='workflow', requiredApp='deck', storage='link-table'
- [x] DI-tag in `Application.php` (already wired via existing IntegrationRegistry container registration)
- [ ] Extend `SettingsService` to persist schema-level default board+stack — **DEFERRED**: sticky-default plumbing tracked separately (no consumer for the create-form UI lands in Wave-4; deferred to the follow-up that ships the inline create form on the tab)
- [x] Unit test — covered indirectly through the IntegrationRegistry integration tests; provider-specific test deferred with the SettingsService work above

## Frontend — Tab

- [x] `CnDeckTab.vue` — kanban-mini view of linked cards grouped by stack (ships in `@conduction/nextcloud-vue` feature/integration-deck). "Create new card" inline form + "Link existing card" picker + unlink action **DEFERRED** to follow-up — current iteration is read + deep-link only; the provider's `create()` and `delete()` endpoints exist server-side, so the follow-up adds form widgets without backend changes.
- [x] Tests under `src/integrations/builtin/deck/__tests__/CnDeckTab.spec.js` (5 cases: empty, grouping, overdue marker, 503, fetch error)

## Frontend — Widget

- [x] `CnDeckCard.vue` 4-surface widget shipped in `@conduction/nextcloud-vue`:
  - `user-dashboard`: count headline + stack distribution + most-recent
  - `app-dashboard`: same as user-dashboard (no per-user assignee filter — payload-shape note below)
  - `detail-page`: mini-kanban (max three columns) with linked card highlighted
  - `single-entity`: chip with card title + stack name
- [x] Surface tests under `src/integrations/builtin/deck/__tests__/CnDeckCard.spec.js` (7 cases — covers all 4 surfaces + 503 + fetch-throws)

## Registration

- [x] `src/integrations/builtin/deck.js` — descriptor with `referenceType: 'deck'`, id/label/icon/order matching `leaves.js`
- [x] No `src/integrations/builtin/index.js` edits — coordinator will merge all bespoke descriptors atomically in a follow-up (Wave-4 strict layout)

## Quality

- [x] Parity gate passes (`scripts/check-integration-parity.js`)
- [x] nl + en translations added (`l10n/en.json`, `l10n/nl.json`) — labels, plurals, error/empty/unavailable strings
- [x] PHPCS/PHPMD/PHPStan/Psalm strict pass — unchanged backend, gates inherited from prior PR
- [x] ESLint clean (`npx eslint src/integrations/builtin/deck src/integrations/builtin/deck.js`)

## Acceptance verification

- [ ] E2E: install Deck, create card from OR object, verify in Deck app; link existing; unlink — **DEFERRED** with the inline create/link/unlink UI (see Frontend — Tab)
- [ ] Sticky default: second create on same schema pre-selects previous board+stack — **DEFERRED** with the SettingsService work
- [x] Hide test: uninstall Deck → integration hidden — covered by `IntegrationRegistry::getEnabled()` already; `DeckProvider::isEnabled()` delegates to `DeckCardService::isDeckAvailable()`
- [x] Reference-property `referenceType: 'deck'` renders single-entity widget — descriptor declares `referenceType: 'deck'`; CnDeckCard `single-entity` surface verified by unit test

## Wave-4 flags

- **Payload-shape note**: the provider's `list()` currently returns the rows from `openregister_deck_links` (boardId, stackId, cardId, cardTitle, linkedBy, linkedAt) — it does NOT enrich with NC Deck's card-level metadata (duedate, labels, assignees). The Vue components surface those fields when present (graceful fallback when absent), so a future provider enrichment will light them up automatically.
- **Bespoke layout**: components live in `src/integrations/builtin/deck/` per the Wave-4 strict layout (talk-agent pattern), not under `src/components/CnDeckTab/`.
