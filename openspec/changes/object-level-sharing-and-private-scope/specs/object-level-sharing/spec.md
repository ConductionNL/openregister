## ADDED Requirements

### Requirement: Access can be granted on a single object

A principal — a user, a group, or a remote federated principal — SHALL be invitable
on ONE object. A per-object grant SHALL compose with the schema's rules rather than
replace them: it narrows within what the schema and tenancy already permit, and
SHALL NOT admit anything they refuse.

Only the object's owner (and administrators) SHALL grant or revoke. A recipient
SHALL NOT be able to widen a grant, add a principal, or re-share onward.

A grant SHALL NOT cross a tenant boundary. The organisation UUID remains the only
tenant key and is evaluated independently; a group is an RBAC permission principal
only and SHALL NEVER be a tenant discriminator.

#### Scenario: An invited user reaches one object and not its siblings

- **WHEN** an owner invites a user on one private object
- **THEN** that user can read that object
- **AND** other objects of the same schema remain inaccessible to them

#### Scenario: A recipient cannot re-share

- **WHEN** an invited principal attempts to add another principal to the object's grants
- **THEN** the request is refused
- **AND** the grants are unchanged

#### Scenario: A grant cannot cross a tenant boundary

- **WHEN** a principal outside the object's organisation is invited
- **THEN** they are still denied

#### Scenario: Revocation denies immediately

- **WHEN** an owner revokes a principal's grant
- **AND** that principal next requests the object
- **THEN** the request is denied, without waiting for a cache to expire or a job to run

### Requirement: Share records live in Nextcloud core and are read through, never cached

Object shares SHALL be stored as Nextcloud shares via a registered share provider,
so their lifecycle — creation, expiry, password, revocation, federation — is core's.
OpenRegister SHALL read them at decision time and SHALL NOT keep an OpenRegister-side
copy of share state.

`IShare` is first-class core state that mutates outside OpenRegister, so a cached
copy would desync. A stale grant admits a principal whose share was revoked, and a
stale revocation hides an object from a principal still entitled to it — an
access-control bug in both directions.

#### Scenario: A share revoked in core takes effect immediately in OpenRegister

- **WHEN** a share is deleted through Nextcloud's own share machinery rather than through OpenRegister
- **THEN** the next OpenRegister access decision for that principal denies

#### Scenario: An expired share stops admitting

- **WHEN** a share carries an expiry date that has passed
- **THEN** the principal is no longer admitted

### Requirement: A grant carries a permission, and the verb is enforced where the action happens

A grant SHALL carry a permission. The RBAC evaluator grants VISIBILITY of the
object; any verb that is not an object RBAC action — running a flow, for example —
SHALL be enforced at the endpoint that performs it, by reading the grant.

Two enforcement points for one grant is a deliberate consequence of the object RBAC
actions being create / read / update / delete, and SHALL be tested: a principal
granted read-only who can see the object MUST still be refused the action.

#### Scenario: A read-only recipient is refused the action

- **WHEN** a principal granted only read attempts an action their grant does not cover
- **THEN** the action is refused
- **AND** they can still read the object

### Requirement: One sharing primitive across the fleet

Consumers SHALL use this primitive rather than a per-schema copy. The bespoke
per-object share list previously added to brokered credentials and flows SHALL be
superseded by it, and the credential broker's share admit branch SHALL consume the
shared primitive rather than its own copy of the shape.

#### Scenario: The broker admits through the shared primitive

- **WHEN** a credential is shared through the object-sharing primitive and a recipient drives a brokered call
- **THEN** the call is admitted by the broker's access guard
- **AND** the broker reads the shared primitive rather than a per-schema property
