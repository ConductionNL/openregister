# example-sets Delta: example-set-object-owner

## ADDED Requirements

### Requirement: A declared object may name its owner from the cast

A declared object MAY carry `owner`, which SHALL resolve to the account id of a person the set's cast declares. Install SHALL create the object owned by that account. On `--refresh` an object the record holds that another account owns SHALL be handed to the declared owner through OpenRegister's audited ownership change, as the system; an object the owner already holds SHALL NOT be handed over again. An `owner` that resolves to anything else SHALL refuse the set before anything is written.

#### Scenario: Created as the named account
- **GIVEN** a decision declaring `owner: {{cast:esra.uid}}`
- **WHEN** the set is installed
- **THEN** the decision SHALL be owned by `esra.demir`

#### Scenario: Handed over on refresh
- **GIVEN** that decision, now owned by `__system__`
- **WHEN** the set is refreshed twice
- **THEN** it SHALL be owned by `esra.demir` after the first refresh, with one hand-over in all

#### Scenario: An owner outside the cast
- **GIVEN** a declared owner `admin`, or a placeholder that resolves to no cast account
- **WHEN** the set is checked
- **THEN** the set SHALL be refused naming the owner, and nothing SHALL be written

### Requirement: The system scope may assign a record

`ObjectOwnershipService` SHALL let a trusted system operation (inside `SystemOperationContext`) assign or reassign a record without a session user. Outside that scope only an administrator SHALL.
