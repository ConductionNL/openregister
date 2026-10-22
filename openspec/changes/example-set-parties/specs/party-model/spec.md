# party-model Delta

## ADDED Requirements

### Requirement: A party schema may name the property that carries its confidentiality

`x-openregister-party` MAY name a `confidentialityProperty`. The party read SHALL answer `confidential`:
- true when that property holds a value other than 0, false, null or empty;
- false when it holds one of those;
- null when the schema names no such property.

#### Scenario: A resident without secrecy
- **GIVEN** a person party whose schema names `confidentiality` as its confidentiality property, holding 0
- **WHEN** the party is read
- **THEN** `confidential` SHALL be false
