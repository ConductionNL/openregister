# Retrofit — data-integrity-relations

Describes observed behavior of 10 methods across 6 files under `data-integrity-relations` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Controller/RelationsController.php` — __construct, index, gatherRelations, buildTimeline, validateObject
- `src/components/object-relations/RelationsTab.vue` — fetchRelations (+ normaliseResponse/normaliseEntry)
- `src/components/object-relations/DeckTab.vue` — fetchCards
- `src/components/object-relations/EventsTab.vue` — fetchEvents
- `src/store/modules/object-relations/deck.js` — useDeckRelationsStore
- `src/store/modules/object-relations/events.js` — useEventRelationsStore

## Approach

- For each capability slice: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces any observed-but-suspicious behavior (silent error swallowing, optional-app degradation modes)

## REQ map

| REQ | Methods |
|-----|---------|
| REQ-001 | RelationsController::__construct, index, validateObject |
| REQ-002 | RelationsController::gatherRelations |
| REQ-003 | RelationsController::buildTimeline + RelationsTab::fetchRelations/normaliseResponse |
| REQ-004 | DeckTab::fetchCards + useDeckRelationsStore (fetch/createOrLink/unlink) |
| REQ-005 | EventsTab::fetchEvents + useEventRelationsStore (fetch/create/link/unlink) |

Source: openspec/coverage-report.md generated 2026-05-24. Reverse-spec ghost change — implementation predates the spec.
