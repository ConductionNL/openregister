# party-model

## ADDED Requirements

### Requirement: A party holds a typed role on an object for a period (REQ-PRM-001)

The system SHALL record a party on an object as a row naming the object,
the party, the role and the period the role runs. More than one party
SHALL be able to hold the same role on one object, and one party SHALL be
able to hold more than one role. A schema SHALL be able to declare which
party kinds and roles it accepts, and a write naming a kind or a role the
schema does not accept SHALL be refused, naming the kind.

#### Scenario: two gemachtigden on one case

- **GIVEN** a schema that accepts the role `gemachtigde`
- **WHEN** two parties are given that role on one object
- **THEN** reading the object returns both, each with its own period

#### Scenario: an undeclared party kind is refused

- **GIVEN** a schema that accepts only the party kind `organisation`
- **WHEN** a party of kind `person` is added to one of its objects
- **THEN** the write is refused and the message names the kind
- @e2e exclude {validator behaviour, covered by unit tests}

#### Scenario: the primary party is replaced and the change is recorded

- **GIVEN** an object whose primary party is party A
- **WHEN** an authorised user replaces the primary party with party B
- **THEN** the object names party B, and the audit trail holds one entry naming A, B and the actor

### Requirement: A party without an account carries its own fields and is reachable (REQ-PRM-002)

A party SHALL be able to exist with no Nextcloud account, carrying its own
properties and one or more addresses, each address carrying a kind. The
notification engine SHALL resolve such a party's recipient from its
addresses. Inbound mail from any address a party holds SHALL resolve to
that party rather than creating a second party.

#### Scenario: a melder with no account is notified

- **GIVEN** a party with no account and one correspondence address
- **WHEN** an outbound message is addressed to that party
- **THEN** the message is delivered to the correspondence address
- @e2e exclude {delivery path, covered by unit tests with a mail fixture}

#### Scenario: a second address resolves to the same party

- **GIVEN** a party holding two e-mail addresses
- **WHEN** mail arrives from the second address
- **THEN** it is linked to that party and no second party is created

#### Scenario: an organisation names a parent without a cycle

- **GIVEN** an organisation party A whose parent is organisation B
- **WHEN** B is given A as its parent
- **THEN** the write is refused naming the cycle
- @e2e exclude {tree guard, covered by unit tests}

### Requirement: An indicator on a party declares its effect and is honoured (REQ-PRM-003)

An indicator held on a party SHALL be readable on every object that party
holds a role on, and SHALL declare its effect: warn the reader, refuse
publication, or refuse an outbound message. The declared effect SHALL be
evaluated where the act happens, and a refusal SHALL name the indicator
and the party.

#### Scenario: a protected address refuses publication

- **GIVEN** a party carrying an indicator whose effect is refuse publication
- **WHEN** an object that party holds a role on is published
- **THEN** the publication is refused and the message names the indicator

#### Scenario: an indicator reaches every case of that party

- **GIVEN** a party holding a role on three objects
- **WHEN** an indicator is set on the party
- **THEN** all three objects read the indicator without being written

### Requirement: A person query over the administered cap is refused (REQ-PRM-004)

The system SHALL accept an administered maximum result count for a party
query. A query whose result set would exceed it SHALL be refused with a
message naming the cap, SHALL NOT return a truncated page, and SHALL write
the refused attempt to the audit trail with the actor and the query.

#### Scenario: a broad person search is refused, not truncated

- **GIVEN** an administered cap of ten and a query matching forty parties
- **WHEN** a user runs the query
- **THEN** the response is a refusal naming the cap and holds no party

#### Scenario: the refused attempt is on the trail

- **GIVEN** the refusal above
- **WHEN** an administrator reads the audit trail
- **THEN** one entry names the actor, the query and the cap
- @e2e exclude {audit assertion, covered by unit tests}
