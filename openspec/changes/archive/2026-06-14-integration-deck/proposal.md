# Integration: Deck (Cards)

## Problem

`DeckCardService` + `DeckController` (254 LOC) link Deck cards to OR objects. No UI exists; cross-team workflow tracking currently requires manual NC Deck app switching.

## Context

- **Backend shipped:** [DeckCardService.php](openregister/lib/Service/DeckCardService.php), [DeckController.php](openregister/lib/Controller/DeckController.php) — create cards via Deck's internal service classes (not OCS), board/stack selection, board-level object listing, link table with cleanup cascade
- **Required NC app:** `deck`
- **Storage:** `link-table` (`openregister_deck_links`)
- **Key capability:** Create new cards from OR (not just link existing) — most common usage
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`DeckProvider` + `CnDeckTab` (list linked cards, create new card inline with board/stack selection, link existing) + `CnDeckCard` widget (4 surfaces — includes mini-kanban view for detail-page showing stack position).

## Scope

**In scope:** Provider, tab with inline create + link flows, widget, registration, tests, translations, spec delta.

**Out of scope:** Modifying Deck app itself; card assignment UI beyond what Deck's service exposes; deep kanban editing (that lives in Deck).

## Acceptance criteria

- [ ] Cards tab appears when Deck installed + schema has `deck` in linkedTypes
- [ ] User can create a new Deck card from the tab (board + stack selection)
- [ ] User can link an existing card
- [ ] User can unlink (link removed, card stays in Deck)
- [ ] Detail-page widget shows a mini board view with the card's position
- [ ] Reference-property `referenceType: 'deck'` works
- [ ] Parity gate passes; nl+en done
