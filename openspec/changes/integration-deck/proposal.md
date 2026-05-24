# Integration: Deck (Cards)

## Problem

`DeckCardService` + `DeckController` (254 LOC) link Deck cards to OR objects. No UI exists; cross-team workflow tracking currently requires manual NC Deck app switching. Today this leaf is **partial** per the 2026-05-24 registry audit — the backend `DeckCardService` delegate works and returns real linked cards, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnDeckTab` / `CnDeckCard` (mini-kanban). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like PipelinQ and Procest have no working integration UI path and reinvent kanban-card linking locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: Uses OR's `DeckCardService` delegate — returns real linked Deck cards with board/stack metadata
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (mini-kanban)
- **Target NC class(es)**: existing `OCA\OpenRegister\Service\DeckCardService` (NC Deck-backed) — no new NC class import required
- **Storage strategy**: `link-table` (`openregister_deck_links`)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Key capability:** Create new cards from OR (not just link existing) — most common usage

## Proposed Solution

`DeckProvider` + `CnDeckTab` (list linked cards, create new card inline with board/stack selection, link existing) + `CnDeckCard` widget (4 surfaces — includes mini-kanban view for detail-page showing stack position). Provider wraps the existing `DeckCardService` and falls back to `IntegrationHealth::missingApp('deck')` when NC Deck is not installed.

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
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `DeckCardService` delegation for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('deck')` when NC Deck absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnDeckTab` + `CnDeckCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
