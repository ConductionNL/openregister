# example-sets Delta: example-set-recorded-floor

## ADDED Requirements

### Requirement: A recorded app config entry follows a floor declared later

When a fragment declares an app config entry with `atLeast` and the install record already holds that entry without `atLeast`, or with a lower value, install SHALL raise the key to the declared value when the key holds less, and SHALL leave a key at or above it as it is. The record's entry SHALL take the declared value and `atLeast`, and SHALL keep what the key held before the set, so the read-back uses the floor and remove still puts back the original value.

#### Scenario: A plain recorded counter becomes a floor
- **GIVEN** a counter that held 7 before the set, a record entry written as a plain 402, and the key now at 404
- **WHEN** the set, which now declares the counter as a floor of 423, is installed
- **THEN** the key SHALL hold 423, the report SHALL say `raised`, and nothing SHALL be reported lost
- **AND** once the app moves the key to 431, the read-back SHALL report nothing lost
- **AND** when the key holds 423 and the set is removed, the key SHALL hold 7 again

#### Scenario: A lower recorded floor is raised
- **GIVEN** a record entry for a floor of 400
- **WHEN** the set declares a floor of 423 and is installed
- **THEN** the key SHALL hold 423 and the report SHALL say `raised`

#### Scenario: A key above the floor keeps its value
- **GIVEN** a record entry written as a plain 402, and the key now at 450
- **WHEN** the set declares a floor of 423 and is installed
- **THEN** the key SHALL still hold 450 and nothing SHALL be reported lost
