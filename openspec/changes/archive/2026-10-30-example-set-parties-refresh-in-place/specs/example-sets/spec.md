## ADDED Requirements

### Requirement: A refresh writes a party link again where it is

On `--refresh` the set SHALL write every party link the record holds again, in place, without removing it first. The link's cached name and e-mail address SHALL be read from the party again.

#### Scenario: an e-mail address the party gained
- **GIVEN** Marja Verbeek's link as chair, written before her person record had an e-mail address
- **WHEN** the set is refreshed
- **THEN** the link carries marja.verbeek@zuiddrecht.example
