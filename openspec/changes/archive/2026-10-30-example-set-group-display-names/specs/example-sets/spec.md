# example-sets Delta: example-set-group-display-names

## ADDED Requirements

### Requirement: A declared group without a name gets the declared one

When a group the set declares already exists and its display name is empty or equal to its id, install SHALL set the declared display name and SHALL record the name it had. A group with a name of its own SHALL be left as it is. Remove SHALL put the recorded name back while the group still carries the set's name, and SHALL NOT delete a group the set did not create.

#### Scenario: An app-made group
- **GIVEN** an existing group `behandelaars` shown as "behandelaars", declared with display name "Behandelaars"
- **WHEN** the set is installed and later removed
- **THEN** the group SHALL read "Behandelaars" after install and "behandelaars" after remove, and SHALL still exist
