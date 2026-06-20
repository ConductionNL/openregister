---
status: done
---

# integration-deck Specification

## Purpose
Links Nextcloud Deck cards to OpenRegister objects, letting users create new cards (with sticky per-schema default board and stack) or link existing ones from a sidebar tab. The detail-page surface renders a compact mini-kanban that highlights the linked card in its current stack, and unlinking leaves the Deck card untouched. Access inherits from object RBAC plus Deck's per-board ACLs.
## Requirements
### Requirement: Deck Provider Registration

The system SHALL register `DeckProvider` with id='deck', group='workflow', requiredApp='deck', storage='link-table'.

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

#### Scenario: Deck reference property renders card chip

- **WHEN** `CnDetailGrid` renders a property with `referenceType: 'deck'`
- **THEN** the system MUST render `CnDeckCard` at `surface='single-entity'` for that property

---

### Requirement: Permission Inheritance

`DeckProvider::requiresPermission()` SHALL return `null`. Deck ACLs govern per-board access transitively.

#### Scenario: Provider declares no extra permission

- **WHEN** `DeckProvider::requiresPermission()` is called
- **THEN** the system MUST return `null` so Deck ACLs govern per-board access transitively

