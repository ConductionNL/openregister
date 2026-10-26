# party-model Delta

## ADDED Requirements

### Requirement: A party kind names its identity shapes

A schema's `partyKinds` entry MAY carry `identityShapes`, a list of the identity shapes a party of that kind has in a
shared party-kind vocabulary. The schema SHALL keep the list as distinct, trimmed, non-empty strings, and SHALL return it
with the kind.

#### Scenario: A colleague is a natural person

- **GIVEN** a case schema whose `partyKinds` names `{ key: "colleague", identityShapes: ["naturalPerson"] }`
- **THEN** the party listing's kinds carry `identityShapes: ["naturalPerson"]` for `colleague`
