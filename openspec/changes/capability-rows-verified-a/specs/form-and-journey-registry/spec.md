## ADDED Requirements

### Requirement: An outsider fills in a published form and a record is created

A form whose journey declares `access: anonymous` SHALL be reachable on a public address that
needs no account. A visitor who fills it in and submits it SHALL create a record in the schema
the journey writes to. The visitor SHALL see a confirmation, and SHALL NOT see other records.

#### Scenario: A resident reports a broken lamp post
- **GIVEN** a form `melding-openbare-ruimte` whose journey declares `access: anonymous` and writes to schema `melding`
- **WHEN** a visitor without an account opens the form's public address, fills it in and submits it
- **THEN** a `melding` record holds the submitted answers
- **AND** the visitor sees a confirmation and no other record

#### Scenario: A form that needs an account stays closed
- **GIVEN** a form whose journey declares an access other than `anonymous`
- **WHEN** a visitor without an account opens its public address
- **THEN** the form is not shown and nothing is written
