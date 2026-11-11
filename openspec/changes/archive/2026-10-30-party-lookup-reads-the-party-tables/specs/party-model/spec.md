## ADDED Requirements

### Requirement: A party is looked up in the party tables first

Looking up a party by id SHALL read the tables of the schemas that declare `x-openregister-party`, in every register
that carries them, before any instance-wide search. Only an id none of them holds SHALL be searched for across the
instance.

#### Scenario: A party in a party table
- **GIVEN** schema 7 declares `x-openregister-party` and register 3 carries it
- **WHEN** party `org-a`, stored in register 3 schema 7, is looked up
- **THEN** only the register 3 / schema 7 table is read

#### Scenario: An id outside the party tables
- **WHEN** an id the party tables do not hold is looked up
- **THEN** the instance-wide lookup still answers it
