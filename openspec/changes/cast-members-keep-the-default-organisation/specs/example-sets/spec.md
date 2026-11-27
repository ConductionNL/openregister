# Example sets

## MODIFIED Requirements

### Requirement: A cast organisation may declare its quota and its members

An `openregisterOrganisations` entry MAY declare a `quota` (`storage`, `requests`, `bandwidth`, whole numbers) and `members` (cast people's keys). An organisation the install creates SHALL carry that quota and those members' accounts. Each of those members SHALL also be a member of the default organisation, so the default organisation, where the set's objects are, stays their active organisation. The catalogue SHALL refuse an unknown quota key, a value that is not a whole number and a member that is not a cast person with an account.

#### Scenario: A second tenant Pieter can read
- **GIVEN** the cast declares Gemeente Waterland with members `marieke` and `pieter` and a storage quota of 100 GB
- **WHEN** the set is installed on an instance without it
- **THEN** Waterland SHALL be created with that quota, and Pieter SHALL find it among his organisations

#### Scenario: A member of a new organisation keeps reading the set's objects
- **GIVEN** the cast declares Gemeente Waterland with member `pieter`, and the set's cases sit in the default organisation
- **WHEN** the set is installed and Pieter signs in for the first time
- **THEN** Pieter SHALL be a member of the default organisation, it SHALL be his active organisation, and he SHALL read the cases his read rules grant

#### Scenario: An organisation created but not completed is still removed
- **GIVEN** the install created Gemeente Waterland but could not add its members to the default organisation
- **WHEN** the install reports the refusal
- **THEN** Waterland SHALL be recorded as made by the set, so removing the set deletes it
