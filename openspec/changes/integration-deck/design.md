# Design: Integration — Deck

> Umbrella decisions apply.

## Approach

`DeckProvider` wraps `DeckCardService`. Tab supports both create-new and link-existing (unlike email which is link-only). Widget on `detail-page` renders a compact stack-aware card.

## Architecture Decisions

### AD-1: Default board selection is sticky per schema

**Decision**: The first time a user creates a Deck card from an object of schema X, the chosen board+stack is saved as schema-level default. Subsequent create-card affordances pre-select it.

**Why**: In real workflows, all objects of a schema (e.g., "case") go to the same board. Asking every time is friction. Schema-level stickiness is the right granularity — not user (personal bias) or app (too broad).

**Trade-off**: Cross-user stickiness means a second user's first create uses the first user's choice. Acceptable — the choice is board+stack, which is a shared resource.

### AD-2: Mini-kanban on detail-page surface

**Decision**: `CnDeckCard` at `surface='detail-page'` shows a three-column compact kanban (stacks that contain the linked card, or the two most-active stacks on the card's board). The linked card is highlighted in its current stack.

**Why**: Seeing "where is this card in the workflow?" is the most valuable Deck information for a case handler. The mini-kanban gives context in one glance without leaving the object.

**Trade-off**: Slightly more complex than a flat list. Payoff is high — this is the kind of visualisation that makes Deck useful for case work.

## Files Affected

### New files — Backend

| File | Purpose |
|---|---|
| `lib/Service/Integration/Providers/DeckProvider.php` | Wraps `DeckCardService` |
| `tests/Unit/Service/Integration/Providers/DeckProviderTest.php` | Unit test |

### Modified — Backend

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | DI-tag `DeckProvider` |
| `lib/Service/SettingsService.php` | Persist schema-level default board+stack (new key `deck.defaultBoard.{schemaSlug}`) |

### New files — Frontend

| File | Purpose |
|---|---|
| `CnDeckTab/CnDeckTab.vue` | List + create-new + link-existing |
| `CnDeckCard/CnDeckCard.vue` | 4-surface widget with mini-kanban on detail-page |
| `src/integrations/builtin/deck.js` | Registration |
| Barrels + tests |

## Risks

| Risk | Mitigation |
|---|---|
| User has no boards | Service returns empty list; tab shows "Create a board in Nextcloud Deck first" with link |
| Deck API internal classes unstable across NC versions | Existing `DeckCardService` already absorbs this; wrapper doesn't re-introduce coupling |
| Mini-kanban perf on boards with 500+ cards | Server-side stack-scoped fetch, limit to visible-range cards |
