## ADDED Requirements

### Requirement: A linked Deck card carries its board and list

Each linked card row SHALL carry `boardTitle` and `stackTitle`, the titles of the board and list the card sits on, or empty strings when Deck gives none.

#### Scenario: a card on a case
- **THEN** the case's Kaarten row reads "Bord Woo en informatie · lijst Deze week · Lars de Koning"
