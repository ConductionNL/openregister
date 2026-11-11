## Purpose

How a piece of work acquires the identity it executes as: the primitive that
establishes an acting user for the duration of one operation, where a run's
identity comes from, and when that identity is re-resolved rather than trusted.

This capability separates two questions that a single field has been answering:
who *caused* work to happen (provenance, immutable) and whose rights it *executes
with* (authorization, re-evaluated). Implements ADR-099.

## ADDED Requirements

### Requirement: An acting identity MUST NOT outlive the operation that established it

Establishing an acting user for a scoped operation MUST NOT write that identity
into the session. The identity MUST be restored to whatever preceded it when the
operation ends, including when it ends by throwing.

Restoring the *previous* identity rather than clearing it is required so that
nested scopes compose: an inner scope returning control to an outer one MUST
leave the outer scope's identity in force.

#### Scenario: The identity is released when the operation returns

- **WHEN** an operation runs under an established acting identity and returns
  normally
- **THEN** the identity in force afterwards is the one that preceded the
  operation

#### Scenario: The identity is released when the operation throws

- **WHEN** an operation running under an established acting identity throws
- **THEN** the throw propagates unchanged
- **AND** the identity in force afterwards is the one that preceded the operation

#### Scenario: The identity is not persisted to the session

- **WHEN** an acting identity is established during a request that carries a
  session
- **THEN** the session's own recorded user is unchanged, both during and after
  the operation
- **AND** a subsequent request on that session acts as the session's user, not as
  the established identity

#### Scenario: Nested scopes restore to their immediate caller

- **WHEN** an operation acting as user A establishes a nested scope acting as
  user B, and the nested scope ends
- **THEN** the identity in force is A, not the identity that preceded A

### Requirement: An acting identity narrows and MUST NOT widen

Establishing an acting identity MUST NOT grant any access the named user does not
already hold. A row the named user cannot read MUST remain unreadable inside the
scope, and an action the named user cannot perform MUST remain refused.

#### Scenario: A scope cannot read what its identity cannot read

- **WHEN** an operation runs as a user who lacks read access to a given object
- **THEN** the operation does not receive that object, regardless of the access
  held by the identity in force before the scope was established

### Requirement: Work that has no user to act for MUST be code-initiated

A trusted operation that runs without any acting user MUST be reachable only from
code shipped with the application — installation, migration, repair, and seeding
of the application's own data.

It MUST NOT be reachable from a flow node, from a tool invoked by an agent, or
from the handling of an inbound request. Where a grant is absent, the correct
outcome is a refusal; escalating to a userless trusted operation instead MUST NOT
be possible from those paths.

#### Scenario: A flow step cannot escalate to a userless operation

- **WHEN** a flow step's identity cannot be resolved
- **THEN** the step is refused with a reason naming the missing identity
- **AND** the step does not execute as a trusted userless operation

#### Scenario: An inbound request cannot escalate to a userless operation

- **WHEN** an authenticated inbound request resolves to no acting user
- **THEN** the request is refused
- **AND** it does not execute as a trusted userless operation

### Requirement: A unit of work MUST record its acting identity separately from its provenance

A record of work MUST carry two distinct values: who caused it, and whose rights
it executes with. The provenance value MUST NOT be consulted to decide access.

The two MUST be allowed to differ. A run started on a schedule has a cause that
is not a person and an acting identity that is.

#### Scenario: Provenance and authorization are recorded separately

- **WHEN** a unit of work is recorded
- **THEN** it carries both a provenance value and an acting identity
- **AND** an access decision made during that work consults only the acting
  identity

#### Scenario: Existing records keep their meaning

- **WHEN** a record created before this capability existed is read
- **THEN** its acting identity is its recorded provenance value
- **AND** no such record is left with an absent acting identity

### Requirement: An acting identity MUST be re-resolved at the moment work runs

The identity a unit of work executes as MUST be resolved when that work runs —
at each scheduled firing, and again on each resumption after a suspension — not
captured once when the work was defined or queued.

An identity that no longer resolves to an enabled user MUST fail the work closed.
It MUST NOT fall back to the identity of whoever defined the work, to an
administrator, or to no identity at all.

#### Scenario: A resumed unit of work re-resolves its identity

- **WHEN** work suspended earlier resumes
- **THEN** its acting identity is resolved again before any step runs
- **AND** the rights applied are those the identity holds at resumption, not
  those it held when the work was suspended

#### Scenario: A departed identity fails the work closed

- **WHEN** work resumes or fires and its recorded acting identity no longer
  resolves to an enabled user
- **THEN** the work fails with a reason naming the unresolvable identity
- **AND** no step of it executes

#### Scenario: A dead identity under a registered schedule is surfaced, not skipped

- **WHEN** a registered schedule's acting identity no longer resolves to an
  enabled user
- **THEN** the schedule is disabled
- **AND** the owner of the schedule's definition is notified
- **AND** the schedule does not silently continue to be skipped while remaining
  enabled
