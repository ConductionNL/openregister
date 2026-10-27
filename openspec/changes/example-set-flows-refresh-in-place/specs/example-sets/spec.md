## ADDED Requirements

### Requirement: A refresh updates a held flow to its declaration

A refresh SHALL update a flow the record holds where it is, keeping its uuid, to the fields the set declares. It SHALL publish the flow again only when a declared field changed, SHALL leave the flow switched on or off as it is, and SHALL keep its runs. A held flow that no longer exists SHALL be written again.

#### Scenario: a changed declaration
- **GIVEN** an installed example flow
- **WHEN** the set is refreshed with the flow's steps in new places
- **THEN** the flow has the same uuid, the new places and a new published version

#### Scenario: an unchanged declaration
- **WHEN** the set is refreshed and the flow's declaration did not change
- **THEN** no new version is published
