## ADDED Requirements

### Requirement: The scoped acting-user and trusted-system operations MUST have a stated contract

Two scoped operations exist on the object service and have shipped without a
spec-level contract: one narrows the caller to a named user for the duration of a
callable, the other elevates to a trusted userless principal. Both are relied on
by flow nodes and background jobs to decide access, so their guarantees are
observable behaviour rather than implementation detail.

The narrowing operation MUST behave as `delegated-identity` requires: it
establishes the named user as the acting identity for the callable only, restores
the previously acting identity when the callable ends — including on a throw —
and grants nothing the named user does not already hold.

The elevating operation MUST be reachable only from code shipped with the
application, per `delegated-identity`.

#### Scenario: The narrowing operation grants nothing

- **WHEN** a callable runs narrowed to a user who lacks a permission
- **THEN** an action requiring that permission is refused inside the callable
- **AND** the refusal names the narrowed user, not the ambient caller

#### Scenario: The elevating operation is not reachable from request handling

- **WHEN** handling of an inbound request attempts a trusted userless operation
- **THEN** the attempt is refused
- **AND** the refusal is reported rather than downgraded to a silent no-op

### Requirement: Authorization decisions MUST answer for the acting identity, not the ambient session

Every predicate that decides access — the row-level authorization predicate and
the tenancy predicate — MUST resolve the subject from the acting identity in
force. A caller holding an explicit identity MUST NOT have that identity ignored
in favour of whatever the ambient session carries.

Where no acting identity is in force, an access decision MUST fail closed. A read
MUST NOT silently drop its authorization predicate and return more than the
subject may see; a write MUST NOT be attributed to a named owner while being
decided against a different subject.

#### Scenario: A read and the write it feeds decide against one identity

- **WHEN** a lookup selects the object that a subsequent write or delete acts on,
  both within one scoped identity
- **THEN** both the lookup and the action decide against that same identity
- **AND** an object the identity may not act on is not selected by the lookup

#### Scenario: A sessionless read does not widen

- **WHEN** a read runs with no acting identity in force
- **THEN** the read is refused
- **AND** it does not return rows that an authorization predicate would have
  excluded

### Requirement: Delegation MUST NOT be expressed as a permission verb

Acting as another user is a property of the caller's identity, not an action
performed on an object. It MUST NOT be added to the permission vocabulary, and
MUST NOT be expressed as a scope, role or verb on a register, schema or object.

This preserves ADR-010's rule that the core verb set is core's bitmask and that
extensions are enforced at the endpoint performing the action rather than by
widening the RBAC vocabulary.

#### Scenario: No delegation verb appears in the permission model

- **WHEN** the permission model for a register or schema is read
- **THEN** it contains no verb, scope or role expressing "may act as another
  user"
