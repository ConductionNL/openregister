## ADDED Requirements

### Requirement: A fragment may put Deck cards on its objects

A fragment MAY declare `deckCards`. Each row SHALL name `object`, `board`, `list`, `title` and `owner`, and MAY give `description`, `due`, `done`, `assignees`, `labels`, `shareWith` and `color`. The owner and assignees SHALL be accounts the set creates or adopts. On install the set SHALL:
- find the board (by owner and title), the list and the labels by title, or write them;
- write the card;
- link it to the object.

A second install SHALL keep the card. Remove SHALL delete the card, its link and the labels the set wrote, and the list and board the set wrote when nothing else is on them. Without Deck, the row SHALL be reported as not written.

#### Scenario: a card on a case
- **GIVEN** a row on case 710d44a4 for board "Woo en informatie", list "Deze week", assignee Lars de Koning, label "Bezig"
- **WHEN** the set is installed
- **THEN** the case's Kaarten card lists "Lakken offerte lantaarnpalen", and removing the set removes the card and the board it wrote

#### Scenario: an existing board
- **WHEN** the board and list already exist
- **THEN** the card goes on them, and remove leaves them
