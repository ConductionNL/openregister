# Organisation projection

## ADDED Requirements

### Requirement: An organisation is addressable as an object (REQ-ORP-101)

An organisation MUST be readable through the object API as a virtual object on
the `directory` register, so a schema property can reference it with a `$ref`.

The object's id MUST be the organisation uuid, so a reference stored before this
change keeps naming the same record.

`find()` MUST resolve through a merge chain, so a reference stored before a
merge resolves to the surviving organisation rather than to a row that owns
nothing.

The projection MUST be read-only. The authoritative record is the Organisation
row, and a write path here would be a second way to mutate a tenant that
bypasses the organisation lifecycle.

#### Scenario: An organisation is readable as an object

- **WHEN** the `nc-organisation` schema is listed by an authorised caller
- **THEN** each organisation they may see is returned as an object whose id is
  its uuid.

#### Scenario: A reference to a merged organisation still resolves

- **GIVEN** an organisation that was merged into another
- **WHEN** it is fetched by its own uuid
- **THEN** the surviving organisation is returned.

### Requirement: The projection carries the identity facet only (REQ-ORP-102)

The projection MUST carry name, description, summary, the legal identifiers
(OIN, TOOI, RSIN, KVK, PKI), image, type and registration status.

It MUST NOT carry quota, users, groups or authorization. Those are tenancy
administration, and this schema exists so another record can reference an
organisation rather than configure one.

An empty field MUST be omitted rather than emitted as null, so a consumer can
distinguish "this organisation has no OIN" from "this projection does not carry
OINs".

A merged-away organisation MUST NOT be listed: it owns nothing, and offering it
invites a reference to a record that is not a usable target.

#### Scenario: Tenancy administration is absent

- **WHEN** an organisation is projected
- **THEN** the object carries no quota, users, groups or authorization.

#### Scenario: An organisation with no OIN omits the key

- **GIVEN** an organisation carrying no OIN
- **WHEN** it is projected
- **THEN** the object has no `oin` key at all.

### Requirement: The projection is not an enumeration oracle (REQ-ORP-103)

Reads MUST be scoped to the acting user: an admin sees every organisation,
anyone else sees only the organisations they belong to.

An organisation that is absent and one the caller may not read MUST be
indistinguishable, so the projection cannot be used to discover which tenants
exist on an instance.

An anonymous caller MUST see nothing.

#### Scenario: An anonymous caller sees no organisations

- **WHEN** an unauthenticated caller lists the schema
- **THEN** the response is empty rather than an error, and reveals no
  organisation.

#### Scenario: A denied organisation is reported as absent

- **GIVEN** an organisation the acting user does not belong to
- **WHEN** they fetch it by uuid
- **THEN** the result is null, the same as for a uuid that does not exist.
