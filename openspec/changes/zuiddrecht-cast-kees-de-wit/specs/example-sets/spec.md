# example-sets Delta

## ADDED Requirements

### Requirement: The Zuiddrecht cast has Kees de Wit, a contact person without a BSN

The Zuiddrecht cast SHALL hold Kees de Wit, of the organisation `buurtoost`, with an e-mail address and an address,
and with no BSN and no account.

#### Scenario: A fragment names Kees as a contact person
- **GIVEN** a fragment writes a person row from `{{cast:keesdewit.displayName}}` with no BSN
- **WHEN** the set is installed
- **THEN** the row SHALL be written, and no account SHALL be created for him
