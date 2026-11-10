# gdpr-data-subject-rights

## ADDED Requirements

### Requirement: The archival anonymisation runs through the pseudonymise primitive (REQ-AAO-005)

An archival anonymisation SHALL execute through the existing erasure
primitive's field-level pseudonymisation path, driven by the schema's
declared anonymisation profile rather than by a request parameter. There
SHALL be one implementation that removes, replaces, pseudonymises and
generalises property values, used by both the data subject's erasure and the
archival outcome, so the two cannot treat the same property differently.

#### Scenario: one implementation, two callers

- **GIVEN** a schema with an anonymisation profile
- **WHEN** the same property is anonymised once through an erasure and once through the archival pass
- **THEN** the resulting value is identical

#### Scenario: the profile drives it, not the request

- **GIVEN** an archival anonymisation request naming properties outside the profile
- **WHEN** it runs
- **THEN** only the profile's properties are treated
- @e2e exclude {service path, covered by unit tests}

### Requirement: The trail keeps the act and loses the values (REQ-AAO-006)

Anonymising SHALL remove the anonymised property values from the stored
diffs of the audit trail, SHALL write an entry naming the act, its actor or
pass, the decision that authorised it and the properties affected, and SHALL
re-seal the hash chain over the result. The chain SHALL verify afterwards,
and the removal SHALL itself be visible as an act on the chain. No other
audit entry SHALL be altered.

#### Scenario: the chain still verifies

- **GIVEN** a record whose audit diffs held the anonymised values
- **WHEN** it is anonymised and the chain is verified
- **THEN** the verification passes

#### Scenario: the removal is itself on the record

- **GIVEN** the same record
- **WHEN** its audit trail is read
- **THEN** an entry names the anonymisation, its authorisation and the properties affected

#### Scenario: unrelated entries are untouched

- **GIVEN** the same record with audit entries about other properties
- **WHEN** it is anonymised
- **THEN** those entries are unchanged
- @e2e exclude {chain re-seal, covered by unit tests}
