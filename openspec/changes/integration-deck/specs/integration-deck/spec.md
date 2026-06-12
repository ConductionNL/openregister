---
status: proposed
---

# Integration: Deck

## Purpose

Surface NC Deck cards linked to OR objects through the registry. Supports create-new and link-existing; includes a mini-kanban widget for detail pages.

**Standards**: NC Deck (internal service classes, per AD-5 of `nextcloud-entity-relations`), ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md), [nextcloud-entity-relations](../../../../specs/nextcloud-entity-relations/spec.md)

---

## Requirements

### Requirement: Deck Provider Registration

`DeckProvider` registered with id='deck', group='workflow', requiredApp='deck', storage='link-table'.

#### Scenario: Present when Deck installed

- **GIVEN** Deck app installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** provider MUST be included

#### Scenario: Hidden when Deck missing

- **GIVEN** Deck app not installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** provider MUST NOT be included

---

### Requirement: Sidebar Tab — Create and Link

Tab SHALL support both creating new cards and linking existing cards. Creating SHALL use sticky schema-level default board+stack.

#### Scenario: Create new card uses sticky default

- **GIVEN** a user previously created a card on board B stack S for schema `case`
- **WHEN** the user opens the create form on another `case` object
- **THEN** board B and stack S MUST be pre-selected in the form

#### Scenario: Link existing card

- **WHEN** the user picks an existing card via the board+stack+card picker
- **THEN** a link record MUST be created in `openregister_deck_links`
- **AND** the card MUST appear in the tab list

#### Scenario: Unlink preserves the card in Deck

- **WHEN** the user unlinks a card
- **THEN** the link MUST be removed
- **AND** the Deck card MUST remain unchanged

---

### Requirement: Mini-Kanban on Detail-Page Surface

`CnDeckCard` at `surface='detail-page'` SHALL render a compact kanban view of the linked card's board with the card highlighted in its current stack.

#### Scenario: Mini-kanban highlights current stack

- **GIVEN** an object with one linked Deck card currently in stack "In Progress"
- **WHEN** `CnDeckCard` renders with `surface='detail-page'`
- **THEN** a kanban view MUST show the card's stacks
- **AND** the linked card MUST be visually highlighted in "In Progress"

---

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'deck'` SHALL render `CnDeckCard` at `surface='single-entity'`.

---

### Requirement: Permission Inheritance

`DeckProvider::requiresPermission()` SHALL return `null`. Deck ACLs govern per-board access transitively.
