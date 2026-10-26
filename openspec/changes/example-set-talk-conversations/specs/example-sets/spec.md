## ADDED Requirements

### Requirement: A fragment may put Talk conversations on its objects

A fragment MAY declare `talkConversations`. Each row SHALL name `object`, `name` and `owner`, and MAY give `participants` and `call` (`start`, `end`). The owner and participants SHALL be accounts the set creates or adopts. On install the set SHALL:
- find the owner's group conversation by name, or create it with the participants;
- write a held call into a new conversation's history, as a `call_started` and a `call_ended` message with the participants and the duration;
- link the conversation to the object.

A second install SHALL keep the conversation and SHALL NOT write the call again. Remove SHALL delete the link, and the conversation when the set created it. Without Talk the row SHALL be reported as not written.

#### Scenario: a call with the requester
- **GIVEN** a row on case 710d44a4: "Gesprek met Sanne de Vries over de stukken", Pieter and Sanne, held 10:30 to 10:42
- **WHEN** the set is installed
- **THEN** the conversation exists with both participants, its history shows a 12-minute call, and the case lists the conversation
