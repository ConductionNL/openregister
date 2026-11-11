# example-sets (delta)

## ADDED Requirements

### Requirement: An install adopts the task or service account it declared when it already exists

An install SHALL point the record at an engine task that already holds a declared task's key instead of writing a second task with that key, and SHALL adopt a declared service account that already exists when the install creates it.

#### Scenario: the recorded task is gone but its key is held
- **GIVEN** an installed set whose recorded task uuid no longer exists, while an engine task holds the same task key
- **WHEN** the set is installed with `--refresh`
- **THEN** no second task is written, the record points at the task that holds the key, and the task is not reported missing

#### Scenario: the service account appeared before it was created
- **GIVEN** a declared service account the install does not see, which exists when it is created
- **WHEN** the set is installed
- **THEN** the account is reported adopted, nothing is dropped, and remove leaves the account
