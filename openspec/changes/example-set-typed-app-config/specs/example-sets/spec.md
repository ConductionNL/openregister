# example-sets Delta: example-set-typed-app-config

## ADDED Requirements

### Requirement: An app config entry may name its type

A fragment's app config entry MAY carry `type`: `string` (the default) or `int`. An `int` entry SHALL be written as an integer, SHALL be compared and restored as one, and its record entry SHALL keep the type. An `int` entry whose value is no integer or placeholder, or any other type, SHALL refuse the set.

#### Scenario: A sequence counter
- **GIVEN** an app's integer key `counter` holding 7, and an entry `{key: counter, value: "402", type: int}`
- **WHEN** the set is installed and later removed
- **THEN** the key SHALL hold the integer 402 after install and 7 after remove
