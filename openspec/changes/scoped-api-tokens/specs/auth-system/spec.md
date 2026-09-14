# auth-system

## ADDED Requirements

### Requirement: A token or Consumer may carry a grant narrower than its user

A personal API token and a Consumer SHALL accept an optional `grant` naming
registers, schemas, verbs from the ADR-010 set except `manage`, an optional
row condition in the RBAC match grammar and an optional `expiresAt`. The
permission handler SHALL intersect the grant with the user's rights on every
read, list, write and action, filtering lists at the SQL level. A grant
wider than the issuer's own rights SHALL be refused at issue time.

#### Scenario: a supplier reads only its own cases

- **GIVEN** a token with grant `{schemas: ["case"], verbs: ["read"], match: {"supplier": "@self.tokenSubject"}}`
- **WHEN** the token lists cases
- **THEN** only cases whose `supplier` equals the token's subject are returned and a `PUT` is refused with 403
- @e2e exclude {proposal only; task 4.1 adds tests/e2e/ci/scoped-token.spec.ts when the editor ships}

#### Scenario: manage cannot be granted

- **GIVEN** a user issuing a token
- **WHEN** the grant names `manage`
- **THEN** the issue is refused with 422
- @e2e exclude {validator, covered by unit tests}

#### Scenario: a shrunken user shrinks the token

- **GIVEN** a token granting `update` on schema `case` issued by a user who later loses `update`
- **WHEN** the token writes a case
- **THEN** the write is refused
- @e2e exclude {intersection, covered by PermissionHandler unit tests}

### Requirement: The effective grant is visible and writes name the token

`GET /api/whoami` SHALL report the effective grant of the calling principal,
and every audit entry written through a granted token or Consumer SHALL
carry the token or Consumer id as `actorVia`.

#### Scenario: an auditor sees which token wrote

- **GIVEN** a write made through token T
- **WHEN** the object's audit trail is read
- **THEN** the entry carries the user as actor and T as `actorVia`
- @e2e exclude {audit field, covered by unit tests}

### Requirement: A token carries a required end date and is warned before it lapses (REQ-SAT-003)

Issuing a token SHALL require an end date, and a request without one SHALL
be refused. The end date SHALL be enforced at use. The holder SHALL be
warned before it lapses. Renewing SHALL be a deliberate act, recorded with
the actor and the new end date.

#### Scenario: a six week migration token does not last six years

- **GIVEN** an issue request with no end date
- **WHEN** it is submitted
- **THEN** it is refused, naming the requirement

#### Scenario: an expired token is refused

- **GIVEN** a token past its end date
- **WHEN** it is used
- **THEN** the call is refused

#### Scenario: the holder hears about it first

- **GIVEN** a token approaching its end date
- **WHEN** the warning period is reached
- **THEN** the holder is notified, naming the token and the date
- @e2e exclude {warning over time, covered by unit tests with a clock fixture}

### Requirement: A service account is a principal owned by a team (REQ-SAT-004)

An integration MAY hold a service account: a principal that carries grants
and tokens, is owned by a team rather than by a person, and cannot sign in
interactively. Its tokens SHALL follow every rule an ordinary token
follows, including the end date.

#### Scenario: an integration survives a leaver

- **GIVEN** a service account owned by a team, whose creator leaves
- **WHEN** the creator's account is disabled
- **THEN** the service account and its tokens keep working

#### Scenario: a service account cannot sign in

- **GIVEN** a service account
- **WHEN** an interactive sign-in is attempted with it
- **THEN** it is refused

### Requirement: A token carries its own rate limit, and outbound destinations are allowlisted (REQ-SAT-005)

A token MAY carry a rate limit set at issue. A call over the limit SHALL
be refused, naming the limit. Outbound destinations for webhooks and
automations SHALL be checked against an administered allowlist at save
time, and a destination outside it SHALL be refused then, not at delivery.

#### Scenario: one runaway integration does not take the balie down

- **GIVEN** a token with a rate limit
- **WHEN** it exceeds the limit inside the window
- **THEN** further calls are refused, naming the limit
- @e2e exclude {rate over time, covered by unit tests with a clock fixture}

#### Scenario: a bad destination fails while somebody is watching

- **GIVEN** an administered outbound allowlist
- **WHEN** a webhook is saved with a destination outside it
- **THEN** the save is refused, naming the allowlist
