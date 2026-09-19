# realtime-updates

## ADDED Requirements

### Requirement: An object knows who has it open

The system SHALL record a presence row (user, object, last seen) on a
heartbeat from a client viewing an object's detail, SHALL expire a row 90
seconds after its last heartbeat, and SHALL list the other present users to
any caller who may read the object. Presence SHALL write nothing to the
object, its audit trail or its versions.

#### Scenario: two handlers see each other

- **GIVEN** two users with read on one object, both with its detail open
- **WHEN** either lists the object's presence
- **THEN** the other user is returned with the time they arrived
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/object-presence.spec.ts when the component ships}

#### Scenario: a closed tab disappears within two beats

- **GIVEN** a user whose client stopped sending heartbeats
- **WHEN** 90 seconds pass
- **THEN** the user is no longer listed and a departure is pushed
- @e2e exclude {expiry, covered by PresenceService unit tests with a clock}

### Requirement: Presence changes are pushed, not polled

The system SHALL push an event of kind `presence` on the per-object channel
on arrival, departure and expiry, and SHALL NOT push on a renewal that
changes nothing. The push SHALL reach only the users authorised to read the
object.

#### Scenario: a renewal is silent

- **GIVEN** a present user
- **WHEN** their client sends the next heartbeat inside the window
- **THEN** no push is emitted
- @e2e exclude {push dedup, covered by NotifyPushListener unit tests}

### Requirement: The live-updates client offers presence

The shared live-updates client plugin SHALL expose `presence(objectUuid)`
returning a reactive list of other present users and managing the heartbeat
and the departure call for the component's lifetime.

#### Scenario: a detail page mounts one component

- **GIVEN** a manifest that places the presence component in a detail header
- **WHEN** a user opens the detail
- **THEN** the component shows the other readers and stops the heartbeat when the page unmounts
- @e2e exclude {client plugin, covered by vitest on the plugin and the e2e spec of task 3.1}
