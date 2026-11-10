# federation

## ADDED Requirements

### Requirement: An object is shared with a principal at another instance, with a two-sided lifecycle (REQ-PCF-001)

The system SHALL send a federated share of an object to a principal at
another instance, and SHALL support accepting, declining and revoking it
from either side. A revocation or a decline on the far side SHALL be
reflected here. A federated recipient SHALL be evaluated by the same
permission layer as a local principal. Receiving a federated share SHALL
continue to work unchanged.

#### Scenario: a case reaches the omgevingsdienst

- **GIVEN** a schema declared federatable and a recipient at another instance
- **WHEN** the object is shared and the recipient accepts
- **THEN** they can open it at their own instance, signed in there

#### Scenario: revoking reaches the other side

- **GIVEN** an accepted federated share
- **WHEN** the sender revokes it
- **THEN** the recipient can no longer open it

#### Scenario: inbound shares are unchanged

- **GIVEN** a federated share received from another instance
- **WHEN** it is processed
- **THEN** it behaves exactly as before this change

### Requirement: A federated share declares what crosses (REQ-PCF-002)

Each federated share SHALL declare what crosses: the object's data, its
files, its public timeline entries, or a subset of those. The default
SHALL be the narrowest. Anything not declared SHALL NOT be sent. A schema
SHALL declare whether its objects may be federated at all, defaulting to
not.

#### Scenario: the internal notes stay at home

- **GIVEN** a share declaring data and files only
- **WHEN** the recipient opens the object
- **THEN** no timeline entry is present

#### Scenario: an undeclared schema is not federatable

- **GIVEN** a schema declaring nothing
- **WHEN** a user tries to share one of its objects with a remote principal
- **THEN** it is refused
