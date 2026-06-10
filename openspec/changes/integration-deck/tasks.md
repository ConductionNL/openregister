# Tasks: Integration — Deck

> **ADR-028 task-cap waiver**: this leaf has 25 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/DeckProvider.php` — id='deck', label='Cards', icon='ViewColumn', group='workflow', requiredApp='deck', storage='link-table'
- [~] DI-tag in `Application.php` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Extend `SettingsService` to persist schema-level default board+stack — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnDeckTab.vue` — list linked cards, "Create new card" inline form (board+stack with sticky default), "Link existing card" picker, unlink — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnDeckCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: cards assigned to current user
  - `app-dashboard`: cards on objects in this app's scope
  - `detail-page`: mini-kanban with linked card highlighted
  - `single-entity`: chip with card title + stack name + assignees
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/deck.js` — register with `referenceType: 'deck'` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire + barrels — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate passes — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] nl + en translations — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] PHPCS/PHPMD/PHPStan/Psalm strict pass — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] ESLint clean — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: install Deck, create card from OR object, verify in Deck app; link existing; unlink — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Sticky default: second create on same schema pre-selects previous board+stack — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test: uninstall Deck → integration hidden — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reference-property `referenceType: 'deck'` renders single-entity widget — deferred to downstream cycle / fleet-wide adoption (handoff)
