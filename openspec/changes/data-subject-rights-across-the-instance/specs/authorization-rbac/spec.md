# authorization-rbac

## ADDED Requirements

### Requirement: Everything one principal can reach is listed and revoked in one act (REQ-DSR-004)

The system SHALL list everything a principal can reach, resolved from the
permission resolver rather than by walking surfaces, and SHALL show that
list before anything is removed. An authorised administrator SHALL be able
to revoke all of it in one act, which SHALL be recorded naming every grant
it removed.

#### Scenario: uitdiensttreding is one act

- **GIVEN** a principal holding grants from four sources
- **WHEN** an administrator opens the reach listing
- **THEN** all four are listed with where each comes from

#### Scenario: the revocation is recorded in full

- **GIVEN** that listing
- **WHEN** the administrator revokes everything
- **THEN** each grant is removed and one audit entry names all of them

### Requirement: A grant to a principal outside the organisation carries an end date (REQ-DSR-005)

A grant to a principal outside the organisation SHALL carry an end date,
and SHALL be refused without one. The holder SHALL be warned before it
lapses. When it lapses, what the principal created SHALL stay with the
organisation and SHALL stay attributed.

#### Scenario: a temporary adviser stays temporary

- **GIVEN** a grant to an external principal with no end date
- **WHEN** it is created
- **THEN** it is refused, naming the requirement

#### Scenario: the work survives the access

- **GIVEN** an external grant that lapses
- **WHEN** the objects they wrote on are read
- **THEN** their contributions are present and attributed, and they can no longer reach them
