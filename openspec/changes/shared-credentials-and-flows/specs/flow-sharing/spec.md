## ADDED Requirements

### Requirement: A flow carries a principal share list granting read or run, never edit

A flow SHALL support an optional `sharedWith[]` property with the same shape as a
credential's: entries of `type` `user` or `group`, an `id`, and a `permission` of
`read` or `run`. `read` SHALL permit seeing the flow; `run` SHALL permit
triggering it and implies `read`.

A share SHALL NEVER grant `edit`. A recipient SHALL NOT be able to change the
flow's definition, its `sharedWith[]`, or its credential-identity declaration, so
a share cannot be used to widen itself or to redirect the flow to other
credentials.

Only the flow's owner SHALL manage its `sharedWith[]`.

A share SHALL NOT admit across a tenant boundary; the organisation UUID remains
the only tenant key and is evaluated independently of the share list
(ADR-002 Rule 1).

#### Scenario: Recipient with run permission triggers the flow

- **WHEN** a user named in `sharedWith[]` with permission `run` triggers the flow
- **THEN** a run is queued
- **AND** the run records the triggering user

#### Scenario: Recipient with read permission cannot trigger

- **WHEN** a user named in `sharedWith[]` with permission `read` attempts to trigger the flow
- **THEN** the request is refused
- **AND** no run is queued

#### Scenario: Recipient cannot edit the flow

- **WHEN** a share recipient attempts to change the flow's nodes, edges, `sharedWith[]`, or credential-identity declaration
- **THEN** the request is refused
- **AND** the flow is unchanged

#### Scenario: A share cannot cross a tenant boundary

- **WHEN** a user outside the flow's organisation is named in `sharedWith[]`
- **THEN** the flow is neither visible nor runnable for that user

### Requirement: The single-object and list access decisions agree

A share verdict SHALL be identical whether it is reached through the
single-object read path or through the SQL-emitting list path. Any conditional
operator or dynamic variable introduced to express a share SHALL be implemented
in the shared PHP-side matcher AND in the SQL emitter (ADR-011), and SHALL NOT be
reimplemented locally in either.

A share that is honoured on one path and not the other is an access-control
defect: over-filtering hides a flow the user is entitled to, and under-filtering
exposes one they are not.

#### Scenario: Shared flow appears in the list and opens

- **WHEN** a share recipient lists flows and then opens the shared flow directly
- **THEN** the flow is present in the list result
- **AND** the direct read succeeds

#### Scenario: Unshared flow is absent from both paths

- **WHEN** a user who is neither owner nor share recipient lists flows and then requests the flow directly
- **THEN** the flow is absent from the list result
- **AND** the direct read is denied

#### Scenario: Revoked share disappears from both paths

- **WHEN** the owner removes a principal from `sharedWith[]`
- **THEN** the flow is absent from that principal's list result
- **AND** their direct read is denied

### Requirement: A flow declares whose credentials its runs resolve as

A flow SHALL support a `credentialIdentity` declaration with exactly two values:

- `runner` — credentials resolve as the user who triggered the run.
- `owner` — credentials resolve as the flow's owner, regardless of who triggered
  the run: the flow lends the owner's credential.

When absent, `credentialIdentity` SHALL default to `runner`, which reproduces
today's behaviour exactly.

Only the flow's owner SHALL be able to set or change `credentialIdentity`.
`owner` mode is a delegation of the owner's authority and SHALL NOT be settable
by a share recipient.

`owner` mode SHALL NOT create a disclosure path: resolution goes through the
broker, which never returns plaintext secret material on its routed path. The
recipient gains the *use* of the owner's credential, never sight of it.

#### Scenario: Default is the runner's own credentials

- **WHEN** a flow carries no `credentialIdentity` and a share recipient runs it
- **THEN** credentials resolve as the triggering user
- **AND** the run fails closed if that user holds no usable credential

#### Scenario: Owner mode lends the owner's credential

- **WHEN** a flow declares `credentialIdentity: owner` and a share recipient runs it
- **THEN** credentials resolve as the flow owner
- **AND** the recipient never receives the secret

#### Scenario: A recipient cannot switch a flow to owner mode

- **WHEN** a share recipient attempts to set `credentialIdentity` to `owner`
- **THEN** the request is refused
- **AND** the flow's declaration is unchanged

### Requirement: A run records the credential identity it resolved as

A flow run SHALL record the identity credentials were resolved as, separately
from the identity that triggered it. Both SHALL be retrievable for a completed
run so a lent-credential call is attributable after the fact.

#### Scenario: A lent-credential run is attributable

- **WHEN** a share recipient runs a flow declaring `credentialIdentity: owner`
- **THEN** the run records the recipient as the triggering identity
- **AND** separately records the owner as the credential identity

#### Scenario: A runner-mode run records one identity in both roles

- **WHEN** a share recipient runs a flow in `runner` mode
- **THEN** the triggering identity and the credential identity are both that recipient

### Requirement: Run-time credential identity is never settable from request input

Run-time resolution SHALL use the broker's existing trusted in-process
assertions, which are honoured only when no user session exists. Those assertions
SHALL remain unreachable from request input: the HTTP-routed broker call SHALL
pass none, and no request parameter, header, or body field SHALL be able to set
the identity a run resolves credentials as.

#### Scenario: A request cannot assert an identity

- **WHEN** a caller supplies an acting-identity value as a request parameter, header, or body field to the routed broker endpoint
- **THEN** the value is ignored
- **AND** the session, where present, remains authoritative

#### Scenario: A sessionless worker resolves the declared identity

- **WHEN** the background worker executes a queued run with no user session
- **THEN** credentials resolve as the identity the flow's `credentialIdentity` selected
- **AND** the assertion used is the trusted in-process one, not a request value
