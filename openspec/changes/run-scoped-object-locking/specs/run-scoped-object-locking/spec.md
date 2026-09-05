# Spec: run-scoped object locking

## Purpose

A lock that a flow run can hold, that actually refuses a write, and that is
always released. Locks were writable and readable before this change and
blocked nothing on the service write path; ownership was keyed on the user
alone, so two runs under one identity could not conflict with each other.

## ADDED Requirements

### Requirement: A lock records whether a run or a person holds it

The lock payload SHALL carry a `kind` of either `run` or `user`, and a
`runUuid` when and only when `kind` is `run`. A payload with no `kind` SHALL
be read as a user lock, so records written before this capability keep their
meaning with no migration and no back-fill.

The payload SHALL keep populating `user` in both cases: the person for a user
lock, and the run's `runAs` identity for a run lock. `user` SHALL NOT be the
authority on ownership.

A payload whose `kind` is `run` but which carries no `runUuid` SHALL be
treated as held against every caller, including the caller that would
otherwise match. A malformed lock fails closed.

#### Scenario: A record written before this change is a user lock
- **GIVEN** a stored lock payload carrying `user` and `expiration` and no `kind`
- **WHEN** ownership is evaluated for that same user
- **THEN** the lock MUST be held by that user, and no migration MUST be required
- @e2e exclude entity-level payload semantics, covered by ObjectEntityTest

#### Scenario: A malformed run lock admits nobody
- **GIVEN** a lock payload with `kind` of `run` and no `runUuid`
- **WHEN** any caller, run or person, is evaluated against it
- **THEN** the lock MUST be held against that caller
- @e2e exclude entity-level payload semantics, covered by ObjectEntityTest

### Requirement: Ownership is decided by one predicate

The system SHALL expose a single predicate that answers whether a live lock
is held against a given caller, identified by a user id and an optional run
uuid. Every write guard, every unlock authorization and every node SHALL call
it. No caller SHALL restate the comparison.

A user lock SHALL be held against every caller whose user id differs from the
recorded one. A run lock SHALL be held against every caller whose run uuid
differs from the recorded one, **including a caller presenting the run's own
`runAs` user id and no run uuid**. An expired lock SHALL be held against
nobody.

#### Scenario: Two runs under one user conflict
- **GIVEN** an object locked by run A executing as `alice`
- **WHEN** run B, also executing as `alice`, evaluates the lock
- **THEN** the lock MUST be held against run B, and run B MUST NOT extend it
- @e2e exclude engine-internal, covered by the two-run real-engine test

#### Scenario: A run's own runAs user is still refused
- **GIVEN** an object locked by run A executing as `alice`
- **WHEN** `alice` evaluates the lock as a person, with no run uuid
- **THEN** the lock MUST be held against her

#### Scenario: A run may extend its own lock
- **GIVEN** an object locked by run A
- **WHEN** run A locks it again
- **THEN** the lock MUST be extended rather than refused

### Requirement: A lock refuses a write and names its holder

While a live lock is held against the caller, the system SHALL refuse to
update, patch or revert that object, and the refusal SHALL name the holder.
When a run holds the lock the message SHALL name the run.

The refusal SHALL apply on the service write path, not only at the HTTP
controller: a lock that only the controller enforces is not a lock.

#### Scenario: A person is refused while a run holds the lock
- **GIVEN** an object locked by a flow run
- **WHEN** a person updates it over the API
- **THEN** the write MUST be refused, and the message MUST name the holding run

#### Scenario: The holder writes freely
- **GIVEN** an object locked by a person
- **WHEN** that same person updates it
- **THEN** the write MUST succeed

#### Scenario: A successful write releases only the writer's own lock
- **GIVEN** an object carrying a lock the writer does not hold
- **WHEN** a write to that object completes
- **THEN** the lock MUST survive the write
- @e2e exclude controller-internal, covered by ObjectsControllerTest

### Requirement: An administrator may break a lock, and the break is recorded

An administrator SHALL be able to release a lock held by anyone, so a wedged
run cannot hold a case hostage. The break SHALL be written to the audit trail
with an action distinguishing it from an ordinary unlock, naming the holder
that was displaced.

No other caller SHALL release a run's lock. The existing holder, object-owner
and schema-manage routes to unlocking SHALL continue to apply to **user**
locks only.

#### Scenario: An administrator breaks a run's lock
- **GIVEN** an object locked by a flow run
- **WHEN** an administrator breaks the lock
- **THEN** the lock MUST be released and an audit entry MUST record the break and the displaced run

#### Scenario: The object owner cannot break a run's lock
- **GIVEN** an object locked by a flow run
- **WHEN** its owner, who is not an administrator, tries to unlock it
- **THEN** the unlock MUST be refused

### Requirement: A lock step takes a lock, or parks the run and retries

The system SHALL provide a step node type `openregister.lock-object` that
takes a run-scoped lock on a target object, and
`openregister.unlock-object` that releases one.

The target SHALL default to the object the item carries and SHALL be
overridable by configuration. Both nodes SHALL accept a duration; the lock
step SHALL accept a wait budget.

When the lock is held by another holder, the lock step SHALL suspend the run
with a non-null resume time and retry on re-entry, rather than failing
immediately or proceeding without the lock. It SHALL keep its wait deadline
and attempt count in its own resume slot, stamping the deadline once, and it
SHALL NOT restart the budget across re-entries.

When the wait budget expires the step SHALL fail, naming the holding run. It
SHALL NOT break the lock and SHALL NOT proceed without it.

An empty firing SHALL take no lock and SHALL NOT suspend the run.

#### Scenario: A contended lock parks the run
- **GIVEN** an object already locked by another run
- **WHEN** a lock step runs against it
- **THEN** the run MUST suspend with a non-null resume time
- @e2e exclude node-internal, covered by LockObjectNodeTest

#### Scenario: The wait budget is not restarted by a retry
- **GIVEN** a lock step that has already parked once and recorded its deadline
- **WHEN** it re-enters and the object is still locked
- **THEN** it MUST keep the original deadline
- @e2e exclude node-internal, covered by LockObjectNodeTest

#### Scenario: An exhausted budget fails and names the holder
- **GIVEN** a lock step whose recorded deadline has passed and whose target is still locked
- **WHEN** it re-enters
- **THEN** it MUST fail with a message naming the holding run, and MUST NOT take or break the lock

#### Scenario: An empty firing does not suspend
- **GIVEN** a lock step reached with no items
- **WHEN** it runs
- **THEN** it MUST return no items and MUST NOT suspend the run

#### Scenario: Configuration is validated before the flow is published
- **GIVEN** a lock step whose wait budget is not a positive number
- **WHEN** its configuration is validated
- **THEN** validation MUST be refused with a message saying what to correct

### Requirement: Every lock a run holds is released when the run ends

The engine SHALL release every lock a run holds on **any** terminal outcome:
`completed`, `stopped`, `failed` and `dead_letter`. The release SHALL NOT
depend on a node running, so a run that crashed or failed still releases.

The release SHALL be idempotent, since terminality can be observed more than
once, and SHALL NOT propagate a failure into the run's own terminal write.

The system SHALL additionally sweep locks whose holding run is terminal or no
longer exists, for the case where a release did not happen. The sweep SHALL
NOT require reading every object table.

The existing lock expiry SHALL remain in force as the final backstop.

#### Scenario: A completed run releases its locks
- **GIVEN** a run holding a lock
- **WHEN** the run completes
- **THEN** the lock MUST be released

#### Scenario: A failed run releases its locks
- **GIVEN** a run holding a lock
- **WHEN** the run fails
- **THEN** the lock MUST be released

#### Scenario: A stopped run releases its locks
- **GIVEN** a run holding a lock
- **WHEN** the run stops
- **THEN** the lock MUST be released

#### Scenario: A dead-lettered run releases its locks
- **GIVEN** a run holding a lock
- **WHEN** the run is dead-lettered
- **THEN** the lock MUST be released

#### Scenario: The sweep releases a lock whose run is gone
- **GIVEN** a lock held by a run whose row no longer exists
- **WHEN** the sweep runs
- **THEN** the lock MUST be released

#### Scenario: The sweep leaves a live run's lock alone
- **GIVEN** a lock held by a run that is still suspended
- **WHEN** the sweep runs
- **THEN** the lock MUST survive

#### Scenario: An expired lock blocks nobody
- **GIVEN** a run lock whose expiry has passed and whose run never released it
- **WHEN** any caller evaluates it
- **THEN** the lock MUST be held against nobody

## MODIFIED Requirements

### Requirement: A lock records what it was taken for

`LockHandler::lock()` has accepted a `process` argument since it shipped and
never forwarded it; `MagicMapper` wrote the literal `MagicMapper lock` in its
place. The caller's `process` SHALL reach the stored payload.

#### Scenario: A caller's process tag survives
- **GIVEN** a caller locking an object with a process tag
- **WHEN** the lock payload is read back
- **THEN** it MUST carry that tag
