## Purpose

Who may act as whom. A delegation grant records that one principal is permitted
to execute work with another user's rights, on what scope, until when, and on
whose say-so — and is the record an access decision consults when a piece of work
names an identity its author does not hold.

This is a distinct axis from capability consent ("may this agent use tool T").
The two are never merged: a user approving a tool must not be able to widen whose
identity the work wears.

## ADDED Requirements

### Requirement: Acting as yourself is not delegation

A principal acting as their own identity MUST NOT require a grant, MUST NOT
create one, and MUST NOT be recorded as delegated.

This keeps the common case frictionless and keeps the grant store meaningful: a
store that records every self-action cannot answer "who can act as the mayor?"
without first filtering out the noise.

#### Scenario: A self-named identity needs no grant

- **WHEN** a principal names their own identity as the one work executes as
- **THEN** the work is permitted without consulting the grant store
- **AND** no grant record is created

### Requirement: Naming another user's identity MUST require a live grant

Work that names an identity other than the author's own MUST be refused unless
the author holds a grant that is granted, unexpired, unrevoked, and whose scope
covers the work.

The check MUST happen at the moment the work runs, not only when it was
authored. A grant revoked after a flow was saved MUST stop that flow from
executing.

#### Scenario: An ungranted identity is refused

- **WHEN** work names an identity its author holds no grant for
- **THEN** the work is refused with a reason naming both the principal and the
  identity
- **AND** it does not execute with any other identity instead

#### Scenario: A revoked grant stops work that previously ran

- **GIVEN** work that has been executing under a grant
- **WHEN** the grant is revoked
- **THEN** the next execution is refused
- **AND** the refusal names the revocation rather than reporting a permissions
  error against the acted-as user

#### Scenario: An expired grant is not a live grant

- **WHEN** work runs under a grant whose expiry has passed
- **THEN** the work is refused
- **AND** the refusal is distinguishable from a grant that was denied or revoked

### Requirement: A grant MUST be recorded with its provenance and reason

A grant MUST record the principal, the acted-as identity, its scope, its expiry,
who granted it, and why. A grant whose origin cannot be answered is not
auditable, and an unauditable delegation is indistinguishable from an
unauthorized one.

#### Scenario: A granted delegation answers who allowed it

- **WHEN** a grant is read
- **THEN** it names who granted it and the reason given
- **AND** an administrator can enumerate every principal permitted to act as a
  given user

### Requirement: A user may grant delegation over themselves and no one else

Consent to be acted as MUST come from the user whose identity is named, or from
an administrator. A principal MUST NOT be able to grant themselves the right to
act as somebody else.

#### Scenario: A self-issued grant over a third party is refused

- **WHEN** a principal attempts to create a grant naming an identity that is
  neither their own nor one they administer
- **THEN** the attempt is refused
- **AND** no grant record is created in any status

### Requirement: A consent request MUST be routed to the user it names

A request for delegation over a third party MUST be delivered to that user as an
actionable task, and MUST carry the identity of the requester, the scope
requested, and the reason.

🔴 The description a user is shown MUST be derived from server-side state. It
MUST NOT be composed from text supplied by the requester, and MUST NOT be
composed from output produced by a language model — a document an agent reads can
instruct it to request a grant, and the request's own description must not be
written by the thing being granted.

#### Scenario: The consent prompt describes the grant from server state

- **WHEN** a user is shown a pending consent request
- **THEN** the scope, requester and expiry shown are read from the grant record
- **AND** no part of the description is taken from requester-supplied free text

#### Scenario: A denial is recorded distinctly from no answer

- **WHEN** a user denies a consent request
- **THEN** the grant's status is `denied`
- **AND** it is distinguishable from a request that expired unanswered

### Requirement: A grant requested for one piece of work MUST carry a scope and an expiry

A grant created in response to a specific request MUST default to a bounded scope
and a finite expiry rather than to unrestricted, indefinite delegation.

Without this every request ratchets permissions upward and nothing is ever
revoked, because the moment at which a grant should end is never revisited.

#### Scenario: A granted request is bounded by default

- **WHEN** a consent request is granted without the granter specifying otherwise
- **THEN** the resulting grant carries a scope no broader than what was requested
- **AND** it carries an expiry

### Requirement: A repeated request MUST NOT re-ask while one is pending

Requests MUST be deduplicated on the principal, the acted-as identity and the
scope — not on the piece of work that triggered them.

Keyed per unit of work, a hundred queued runs needing one grant produce a hundred
notifications. Keyed correctly, one request represents all of them and one answer
resolves all of them.

A denial MUST suppress re-requests for a cooling period. A user shown the same
prompt repeatedly will eventually accept it, so an un-cooled retry loop converts a
refusal into an approval.

#### Scenario: Many blocked units of work raise one request

- **GIVEN** several pieces of work blocked on the same principal, identity and
  scope
- **WHEN** they each require consent
- **THEN** exactly one pending request exists
- **AND** answering it releases all of them

#### Scenario: A denial is not immediately re-asked

- **GIVEN** a denied request
- **WHEN** the same principal requires the same delegation again within the
  cooling period
- **THEN** no new request is delivered to the user
- **AND** the work is refused with the prior denial as the reason

### Requirement: Work blocked on consent MUST park in a distinct state

Work that cannot proceed because it lacks a grant MUST enter a state that names
that reason, distinct from a generic wait.

An operator asking "why is this stuck" MUST get "it is waiting for X to allow Y
to act as them", not "it is waiting".

A denial MUST fail the parked work with that reason. A request that expires
unanswered MUST fail the parked work closed rather than leaving it parked
indefinitely.

#### Scenario: Parked work names what it is waiting for

- **WHEN** work parks for consent
- **THEN** its state identifies the pending grant
- **AND** the principal and acted-as identity are readable from the work itself

#### Scenario: A denial releases parked work as failed

- **WHEN** the grant a parked unit of work waits on is denied
- **THEN** that work fails with the denial as its reason
- **AND** it does not remain parked

#### Scenario: An unanswered request does not park work forever

- **WHEN** a pending request reaches its expiry with no answer
- **THEN** the request is marked expired
- **AND** the work waiting on it fails closed

### Requirement: The grant store MUST NOT be governed by the authorization it decides

Reading a grant MUST NOT require the authorization that reading the grant is
being used to decide. The store MUST be readable through a path that is not
subject to object-level access control.

Otherwise resolving a delegation requires a subject, and resolving the subject
requires the delegation.

#### Scenario: A grant resolves without an object-level access decision

- **WHEN** an access decision needs to know whether a grant exists
- **THEN** the lookup succeeds without performing an object-level permission
  check
- **AND** it does not require elevation to a trusted userless principal

### Requirement: Every delegated execution MUST be auditable

Work that executed under a grant MUST record the principal, the acted-as
identity, the grant relied on, and the reason recorded on that grant.

"Who did this, and who allowed them to" MUST be answerable from the record alone,
without correlating logs.

#### Scenario: A delegated action names its authority

- **WHEN** work executes under a grant
- **THEN** the audit record names the principal, the acted-as identity and the
  grant
- **AND** the acted-as user can enumerate everything done on their behalf
