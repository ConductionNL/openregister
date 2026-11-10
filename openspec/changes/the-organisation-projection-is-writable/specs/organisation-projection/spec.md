# Organisation projection

## MODIFIED Requirements

### Requirement: An organisation is addressable as an object (REQ-ORP-101)

An organisation MUST be readable through the object API as a virtual object on
the `directory` register, so a schema property can reference it with a `$ref`.

The object's id MUST be the organisation uuid, so a reference stored before this
change keeps naming the same record.

`find()` MUST resolve through a merge chain, so a reference stored before a
merge resolves to the surviving organisation rather than to a row that owns
nothing.

The projection MUST support create and update, and MUST refuse delete. It was
read-only, on the reasoning that a write path would be a second way to mutate a
tenant that bypasses the organisation lifecycle. That reasoning holds; the
conclusion did not. A read-only projection cannot replace the leaf-app
`organization` schemas it exists to retire, because the apps that declared them
create organisations. So the write path exists and goes THROUGH the lifecycle
rather than around it (REQ-ORP-104).

#### Scenario: An organisation is readable as an object

- **WHEN** the `nc-organisation` schema is listed by an authorised caller
- **THEN** each organisation they may see is returned as an object whose id is
  its uuid.

#### Scenario: A reference to a merged organisation still resolves

- **GIVEN** an organisation that was merged into another
- **WHEN** it is fetched by its own uuid
- **THEN** the surviving organisation is returned.

## ADDED Requirements

### Requirement: An organisation can be created through the projection (REQ-ORP-104)

Creating an object on the `nc-organisation` schema MUST create an organisation
through the organisation lifecycle, not through the mapper, so that slug
generation, owner assignment, the admin-user membership and the admin-group RBAC
grant all happen as they do for an organisation created anywhere else.

A create without an acting user MUST be refused. A create without a name MUST be
refused rather than defaulted: the slug is derived from the name, so an empty
name would produce a tenant whose slug the next create collides with.

The `nc-organisation` schema MUST carry `x-openregister-object-source.readOnly:
false`, and no other virtual schema may. The save dispatch reads that annotation
before it will delegate a write, so a provider that implements the write
interface while its schema still says `readOnly: true` refuses every write with
nothing naming the annotation as the reason.

That annotation MUST be reconciled on an EXISTING schema, not only set on a new
one. The seed returns early when the schema is found, so an instance that
already seeded `nc-organisation` would otherwise keep the `readOnly: true` it was
created with, and the capability would reach fresh installs only.

#### Scenario: A create goes through the lifecycle

- **WHEN** an object is created on `nc-organisation` with a name
- **THEN** an organisation exists carrying that name, a generated slug, the
  acting user as owner, and the admin-group RBAC grant.

#### Scenario: A create without a name is refused

- **WHEN** an object is created on `nc-organisation` with no name, or an empty one
- **THEN** the create is refused.

#### Scenario: An already-seeded schema is flipped, not skipped

- **GIVEN** an instance whose `nc-organisation` schema carries `readOnly: true`
- **WHEN** the directory-schema seed runs
- **THEN** the schema carries `readOnly: false`.

### Requirement: Only an organisation's administrator may write it (REQ-ORP-105)

Membership is enough to READ the projection. Writing MUST require that the
acting user administers the organisation, which is the instance admin or the
organisation's owner, decided by the same check the rest of the app uses so the
projection and the app cannot disagree about who administers an organisation.

A write on an organisation the acting user does not administer MUST be refused
with the same response as a write on one that does not exist, so the projection
stays unusable as an enumeration oracle. The distinction MUST be logged, because
an administrator reading the log needs it.

A write MUST NOT follow a merge chain, even though a read does. Following one
would silently edit the surviving organisation while the caller believes it is
editing the record it addressed. A write on a merged-away organisation MUST be
refused.

#### Scenario: A member who is not the owner cannot write

- **GIVEN** an organisation the acting user belongs to but does not own
- **WHEN** they update it through the projection
- **THEN** the write is refused.

#### Scenario: A write on a merged-away organisation is refused

- **GIVEN** an organisation that was merged into another
- **WHEN** it is updated by its own uuid
- **THEN** the write is refused rather than applied to the survivor.

### Requirement: An organisation cannot be deleted through the projection (REQ-ORP-106)

Deleting an object on `nc-organisation` MUST be refused, always.

An organisation is the tenant boundary: every object, register and schema on the
instance is scoped to one. Deleting it through the object API would orphan all
of that, from a caller that believes it is removing a reference record. Merging
is the operation that exists for retiring an organisation, and it keeps every
stored reference pointing at a record that still owns something.

The refusal MUST come from the provider, not from an accident of routing. Over
HTTP today a delete on any virtual schema is refused EARLIER, by
`ObjectsController::destroy()` resolving the uuid through `MagicMapper` and
answering 404 when the register/schema table does not exist, so `remove()` is
never reached and the message never surfaces. That is a pre-existing gap in the
virtual-schema delete path, shared with every read-only projection, and it is
not what this requirement rests on: it would stop refusing the moment that
lookup learns about virtual objects.

#### Scenario: A delete is refused

- **WHEN** the provider is asked to remove an organisation
- **THEN** the delete is refused, and the refusal names merging as the operation
  that retires an organisation.

#### Scenario: A delete over HTTP does not remove the organisation

- **WHEN** an object is deleted on `nc-organisation` through the object API
- **THEN** the organisation still exists afterwards.

### Requirement: Only the identity facet is writable (REQ-ORP-107)

A write MUST apply only the projected identity properties. Quota, users, groups
and authorization are not projected and MUST NOT be reachable through a write,
which is the same boundary the read side draws.

A property outside the projection MUST be ignored rather than rejected. The
store already discards unprojected properties on the way in, so rejecting here
would fail a request over a field the caller cannot see in the first place.

#### Scenario: An unprojected property is ignored

- **WHEN** an organisation is updated with a `quota` property
- **THEN** the update succeeds and the organisation's quota is unchanged.
