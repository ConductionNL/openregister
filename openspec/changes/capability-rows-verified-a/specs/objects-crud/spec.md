## ADDED Requirements

### Requirement: The record form gives a date a date picker and a yes or no field a switch, and checks an email or web address on save

The record form SHALL show a date picker for a string property with format `date`, `time` or
`date-time`, and a switch for a boolean property. A property with format `email` or `uri` SHALL
be edited as text, and a save holding a value that does not match the format SHALL be refused
with a message that names the property.

#### Scenario: A date gets a date picker
- **GIVEN** a schema property `ingangsdatum` of type string with format `date`
- **WHEN** a record editor opens the edit dialog of a record
- **THEN** the `ingangsdatum` field is a date picker

#### Scenario: A yes or no field gets a switch
- **GIVEN** a schema property `spoed` of type boolean
- **WHEN** a record editor opens the edit dialog of a record
- **THEN** the `spoed` field is a switch

#### Scenario: A wrong email address is refused
- **GIVEN** a schema property `email` of type string with format `email`
- **WHEN** a record editor saves `email` as `jan-at-example`
- **THEN** the save is refused and the message names `email`
