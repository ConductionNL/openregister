## ADDED Requirements

### Requirement: A refresh updates a held flow to its declaration

A refresh SHALL update a flow the record holds where it is, keeping its uuid, to the fields the set declares. It SHALL publish the flow again only when a declared field changed, SHALL leave the flow switched on or off as it is, and SHALL keep its runs. A held flow that no longer exists SHALL be written again.

#### Scenario: a changed declaration
- **GIVEN** an installed example flow
- **WHEN** the set is refreshed with the flow's steps in new places
- **THEN** the flow has the same uuid, the new places and a new published version

#### Scenario: the held flow is already published
- **GIVEN** an installed example flow whose current version is published, by nobody in particular
- **WHEN** the set is refreshed with a changed declaration
- **THEN** the changed flow is published as the next version, and the refresh does not refuse it as "Only a draft version can be published"

#### Scenario: an unchanged declaration
- **WHEN** the set is refreshed and the flow's declaration did not change
- **THEN** no new version is published
