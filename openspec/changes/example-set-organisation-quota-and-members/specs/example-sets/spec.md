# example-sets Delta

## ADDED Requirements

### Requirement: A cast organisation may declare its quota and its members

An `openregisterOrganisations` entry MAY declare a `quota` (`storage`, `requests`, `bandwidth`, whole numbers) and `members` (cast people's keys). An organisation the install creates SHALL carry that quota and those members' accounts. The catalogue SHALL refuse an unknown quota key, a value that is not a whole number and a member that is not a cast person with an account.

#### Scenario: A second tenant Pieter can read
- **GIVEN** the cast declares Gemeente Waterland with members `marieke` and `pieter` and a storage quota of 100 GB
- **WHEN** the set is installed on an instance without it
- **THEN** Waterland SHALL be created with that quota, and Pieter SHALL find it among his organisations
