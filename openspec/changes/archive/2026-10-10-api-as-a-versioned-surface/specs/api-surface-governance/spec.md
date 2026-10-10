# api-surface-governance

## ADDED Requirements

### Requirement: A schema declares links out of its objects (REQ-AVS-001)

A schema MAY declare external links, each carrying a title, a URL template
with placeholders resolved from the object's own values, and an optional
condition. A link whose placeholders cannot all be resolved SHALL NOT be
offered. A declared link SHALL be returned with the object, so any surface
can render it without a second call.

#### Scenario: a case opens the same address in the BAG viewer

- **GIVEN** a schema declaring a link with the template holding a placeholder for the object's `bagId`
- **WHEN** an object carrying a `bagId` is read
- **THEN** the link is returned with the placeholder filled

#### Scenario: a missing value hides the link

- **GIVEN** the same schema and an object with no `bagId`
- **WHEN** it is read
- **THEN** no link is returned for that declaration

### Requirement: The instance publishes its capabilities and its limits (REQ-AVS-002)

The system SHALL publish the API versions it serves with their status, the
upload limit, the maximum page size, the rate limits in force and the
features that are switched on. The part carrying no secret SHALL be
readable without a session; anything naming a register, a schema or an
operational feature flag SHALL require one.

#### Scenario: a client reads the upload limit before uploading

- **GIVEN** an instance with an upload limit
- **WHEN** an unauthenticated client reads the capabilities
- **THEN** the upload limit and the supported versions are returned

#### Scenario: the unauthenticated answer names no register

- **GIVEN** the same read
- **THEN** the response holds no register name and no schema name

### Requirement: Every API call records its caller, and a caller carries a limit and an address binding (REQ-AVS-003)

The system SHALL record, per principal and per endpoint and version, the
number of calls and the time of the last one, and SHALL NOT record the
payload. An administrator SHALL be able to read that record for a period.
A rate limit SHALL be settable per token or consumer; a call over it SHALL
be refused, naming the limit and the reset time. A token MAY be bound to
source addresses, and a call from another address SHALL be refused.

#### Scenario: a deprecation becomes a conversation

- **GIVEN** two suppliers calling a deprecated endpoint
- **WHEN** an administrator reads the caller record for the last month
- **THEN** both principals are listed with their call counts and last call

#### Scenario: no payload is kept

- **GIVEN** a call carrying case data in its body
- **WHEN** the caller record is read
- **THEN** it holds the principal, route, version and counts, and no body

#### Scenario: a runaway integration is bounded

- **GIVEN** a token with a rate limit of sixty calls a minute
- **WHEN** the sixty first call arrives inside the minute
- **THEN** it is refused, naming the limit and the reset time
- @e2e exclude {rate behaviour over time, covered by unit tests with a clock fixture}

### Requirement: The instance answers the well-known paths and honours an administered proxy (REQ-AVS-004)

The instance SHALL answer the standard well-known discovery paths,
`security.txt` included, with administered content. Every outbound call the
application makes SHALL honour a single administered proxy setting when
one is set.

#### Scenario: a responsible disclosure contact is findable

- **GIVEN** an administered security contact
- **WHEN** the well-known security path is requested
- **THEN** it is served with that contact

#### Scenario: an outbound call goes through the proxy

- **GIVEN** an administered proxy
- **WHEN** the application makes any outbound call
- **THEN** the call goes through the proxy
- @e2e exclude {network condition, covered by unit tests with a fake transport}
