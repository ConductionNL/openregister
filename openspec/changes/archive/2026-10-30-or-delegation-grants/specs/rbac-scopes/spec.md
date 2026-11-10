## ADDED Requirements

### Requirement: The delegation store MUST sit outside the access control it informs

Grant lookups MUST NOT pass through the object-level authorization path. A read
that decides authorization cannot itself depend on the decision it is making, and
routing it through `ObjectService` would make it do exactly that.

It MUST NOT be resolved by elevating to a trusted userless principal either. That
would put a security-critical read behind the one escape hatch
`delegated-identity` forbids on request paths, and make every grant check a
reason to reach for it.

#### Scenario: Resolving a grant needs neither a subject nor elevation

- **WHEN** an access decision resolves whether a grant exists
- **THEN** the lookup performs no object-level permission check
- **AND** it does not enter a trusted userless scope

### Requirement: Delegation MUST remain outside the permission vocabulary

Consistent with ADR-010 and with `or-delegated-identity`: acting as another user
is a property of the caller's identity, not an action performed on an object. The
grant store MUST NOT be expressed as a permission verb, scope or role.

#### Scenario: No delegation verb is introduced

- **WHEN** the permission model is read after this change
- **THEN** it contains no verb, scope or role expressing delegation
