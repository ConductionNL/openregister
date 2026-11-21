## ADDED Requirements

### Requirement: Changes to authorisation rules, API tokens and record types are on the audit trail

The audit trail SHALL hold an entry for every administrator action that changes who may do what, not only for changes to records. Creating, changing or deleting a schema or a register, changing a schema's authorisation rules, and creating or revoking an API token SHALL each write one entry naming the actor, the moment, the item changed and what changed. A token value or other secret SHALL never be written to the entry.

#### Scenario: A changed authorisation rule is traceable
- **GIVEN** a schema `meldingen` whose read rule lists the group `behandelaars`
- **WHEN** an administrator adds the group `stagiairs` to that rule
- **THEN** the audit trail holds an entry naming the administrator, `meldingen`, and the rule before and after

#### Scenario: A revoked token is traceable without its value
- **GIVEN** an API token `koppeling-gis` owned by user `jan`
- **WHEN** an administrator revokes it
- **THEN** the audit trail holds an entry naming the administrator, `koppeling-gis` and `jan`
- **AND** the entry holds no token value

#### Scenario: A deleted record type is traceable
- **GIVEN** a schema `oude-meldingen` without objects
- **WHEN** an administrator deletes it
- **THEN** the audit trail holds an entry naming the administrator and `oude-meldingen`
