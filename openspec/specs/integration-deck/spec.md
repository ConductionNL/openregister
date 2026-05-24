# integration-deck Specification

## Purpose
TBD - created by archiving change integration-deck. Update Purpose after archive.
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

#### Scenario: Reference field renders single-entity widget

- **GIVEN** an object property declares `referenceType: 'deck'` and a card id value
- **WHEN** the detail page renders the field
- **THEN** `CnDeckCard` MUST mount with `surface='single-entity'` and the card id forwarded via `value`

---

### Requirement: Permission Inheritance

`DeckProvider::requiresPermission()` SHALL return `null`. Deck ACLs govern per-board access transitively.

#### Scenario: Provider exposes no extra OR permission

- **WHEN** `DeckProvider::requiresPermission()` is invoked
- **THEN** the return value MUST be `null`
- **AND** access decisions MUST fall through to Deck's own ACLs per board

