# example-sets Delta: example-set-app-config-at-least

## ADDED Requirements

### Requirement: An integer app config entry may be a floor

An app config entry with `type: int` MAY carry `atLeast: true`. Install SHALL write the value only when the key is missing or holds a lower integer, and SHALL leave a key at or above the value as it is. The install's read-back SHALL report the entry lost only when the key holds less than the value. Remove SHALL leave a key that no longer holds the written value. `atLeast` on an entry that is generated or not of type `int` SHALL refuse the set.

#### Scenario: A counter below the floor is raised
- **GIVEN** an app's integer key `counter` holding 7, and an entry `{key: counter, value: "423", type: int, atLeast: true}`
- **WHEN** the set is installed
- **THEN** the key SHALL hold 423 and the install SHALL be ok

#### Scenario: A counter above the floor is never lowered
- **GIVEN** the key `counter` holding 450
- **WHEN** the set is installed
- **THEN** the key SHALL still hold 450 and the entry SHALL be reported kept

#### Scenario: A counter the app moved on is not lost, and survives remove
- **GIVEN** an installed set whose floor raised `counter` to 423, after which the app moved it to 431
- **WHEN** the install's read-back runs, and later the set is removed
- **THEN** nothing SHALL be reported lost, and `counter` SHALL still hold 431 after remove

#### Scenario: A counter that fell below the floor is lost
- **GIVEN** an installed floor of 423 and the key now holding 12
- **WHEN** the install's read-back runs
- **THEN** the entry SHALL be reported lost

#### Scenario: Only a written integer can be a floor
- **GIVEN** an entry with `atLeast: true` that is generated or of type `string`
- **WHEN** the set is gathered
- **THEN** the set SHALL be refused, naming `atLeast`
