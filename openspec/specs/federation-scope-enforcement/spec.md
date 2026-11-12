# federation-scope-enforcement Specification

## Purpose
What a federated or shared caller may reach is decided by the scope it was granted, enforced where the data is served: a share serves exactly what its scope grants, a flow's state is readable only by an organisation that owns the flow, and packaging configuration is gated exactly like publishing it.

## Requirements

### Requirement: A share serves exactly what its scope grants (REQ-FSE-001)

The federation serving endpoints SHALL apply a share's scope on the SINGLE-object
path as strictly as on the collection path. For a share whose `scope` is
`object`, `GET`/`PUT`/`DELETE` of `/api/federation/{shareToken}/objects/{id}`
SHALL be refused unless `{id}` is the object the share's `objectUri` names.

The refusal SHALL be a `404`, not a `403`: distinguishing "outside your grant"
from "does not exist" would turn a scoped token into an oracle for enumerating
the sharing organisation's object ids.

An `object`-scope share that names no `objectUri` SHALL grant nothing. A grant
whose scope field says "one object" and whose stored target is empty is
malformed, and the only safe reading of a malformed grant is the narrow one.

Shares of scope `register`, `schema` and `query` are unaffected: their breadth IS
the grant, and the organisation and confidentiality filter remains their guard, on
the single-object read and on `PUT` and `DELETE` alike. A `query` share reaches by
id only an object its query selects. A share without an organisation writes
nothing and serves only objects that carry no organisation.

The organisation filter compares the share's organisation with the record's own
organisation field. A `@self.organisation` or an `organisation` property inside the
object's stored data is data, not the record's organisation, and never answers it.
A `query` share's own filter, including a `uuid` filter, is kept as it is when a
single object is addressed; the addressed record is added as a further condition.

#### Scenario: An object-scope token cannot read a different object

- **GIVEN** an accepted outgoing share with `scope: object` granting `granted-1`
  in register `zaken` / schema `zaak`
- **WHEN** `GET /api/federation/{token}/objects/someone-elses-object` is called
- **THEN** the response status MUST be `404`
- **AND** the object read MUST NOT be attempted

#### Scenario: The granted object is still served

- **GIVEN** the same share
- **WHEN** `GET /api/federation/{token}/objects/granted-1` is called
- **THEN** the response status MUST be `200` and the object MUST be returned

#### Scenario: An object-scope token cannot write or delete a different object

- **GIVEN** the same share with `permissions: read-write`
- **WHEN** `PUT` or `DELETE` is called for an id the share does not name
- **THEN** the response status MUST be `404`
- **AND** no save or delete MUST reach the object store

#### Scenario: A write only reaches an object the share could read

- **GIVEN** an accepted outgoing share with `scope: schema` and `permissions: read-write` for organisation `org-a`
- **WHEN** `PUT` or `DELETE` is called for an object of another organisation, for a confidential object, or for an id that does not exist
- **THEN** the response status MUST be `404`
- **AND** no save or delete MUST reach the object store
- **AND** a `PUT` that does reach its object MUST store the share's organisation, whatever `@self.organisation` the body names

#### Scenario: A query share reaches by id only what its query selects

- **GIVEN** an accepted outgoing share with `scope: query`, a query filter and `permissions: read-write`
- **WHEN** `GET`, `PUT` or `DELETE` is called for an id the query does not select
- **THEN** the response status MUST be `404`
- **AND** no save or delete MUST reach the object store

#### Scenario: The organisation filter reads the record, not its data

- **GIVEN** an accepted outgoing share with `scope: schema` and `permissions: read-write` for organisation `org-a`
- **AND** a record of organisation `org-b` whose stored data holds `@self.organisation: org-a`, or a record without an organisation whose data holds `organisation: org-a`
- **WHEN** `GET`, `PUT` or `DELETE` is called for that record, or the share's collection is listed
- **THEN** the single-object calls MUST answer `404` and no save or delete MUST reach the object store
- **AND** the collection MUST NOT contain the record

#### Scenario: A query share's own id list is kept

- **GIVEN** an accepted outgoing share with `scope: query` whose filter is `_ids: [obj-a]`
- **WHEN** `GET`, `PUT` or `DELETE` is called for a record that list does not name by uuid or slug
- **THEN** the response status MUST be `404`
- **AND** no save or delete MUST reach the object store
- **AND** a record the list names MUST still be reached

#### Scenario: A query share's own uuid filter is kept

- **GIVEN** an accepted outgoing share with `scope: query` and the query filter `uuid: obj-2`
- **WHEN** `GET`, `PUT` or `DELETE` is called for `obj-1`
- **THEN** the response status MUST be `404`
- **AND** the query asked MUST still carry `uuid: obj-2`, narrowed to `obj-1`

#### Scenario: A share without an organisation fails closed

- **GIVEN** an accepted outgoing share whose organisation is null or empty
- **WHEN** `POST`, `PUT` or `DELETE` is called
- **THEN** the response status MUST be `403` and nothing MUST be written
- **AND** a `GET` of an object that carries an organisation MUST answer `404`

#### Scenario: An object-scope share with no objectUri grants nothing

- **GIVEN** a share with `scope: object` and an empty `objectUri`
- **WHEN** any single-object endpoint is called with any id
- **THEN** the response status MUST be `404`

#### Scenario: A schema-scope share still serves any object it covers

- **GIVEN** an accepted outgoing share with `scope: schema`
- **WHEN** a non-confidential object of that register/schema is requested by id
- **THEN** the response status MUST be `200`

---

### Requirement: A flow's state is readable only by an organisation that owns the flow (REQ-FSE-002)

`GET /api/flow/{flowId}/state` SHALL resolve the flow through `FlowService`
BEFORE reading its state, so the organisation scoping that governs every other
flow read governs this one too. A flow the caller may not see SHALL raise the
same `404` as a flow that does not exist.

`FlowStateMapper` SHALL remain unscoped — the engine reads it with no session —
so the scoping obligation sits with the request-facing caller.

#### Scenario: Another organisation's flow state is refused

- **GIVEN** a flow uuid belonging to an organisation the caller is not in
- **WHEN** `GET /api/flow/{flowId}/state` is called
- **THEN** the response status MUST be `404`
- **AND** the flow-state store MUST NOT be read

#### Scenario: The caller's own flow state is still served

- **GIVEN** a flow the caller's organisation owns
- **WHEN** `GET /api/flow/{flowId}/state` is called
- **THEN** the response status MUST be `200` and the stored state MUST be returned

---

### Requirement: Packaging configuration is gated exactly like publishing it (REQ-FSE-003)

`POST /api/federated-config/bundle` SHALL enforce
`FederatedConfigAccess::canPublish()`, the same gate `publish()` enforces. A
bundle is the payload a publish pushes out; gating the transport and not the
export leaves the export reachable by anyone the gate refused.

Every `IShareableConfigType::serialise()` implementation SHALL read within the
caller's authorisation and tenancy. `_rbac: false` / `_multitenancy: false` are
engine escape hatches for session-less paths; `serialise()` is reached from an
authenticated HTTP request and MUST NOT use them.

#### Scenario: A caller who may not publish cannot bundle

- **GIVEN** a caller for whom `canPublish()` is false
- **WHEN** `POST /api/federated-config/bundle` is called
- **THEN** the response status MUST be `403`
- **AND** no serialisation MUST be performed

#### Scenario: A caller who may publish still gets the bundle

- **GIVEN** a caller for whom `canPublish()` is true
- **WHEN** `POST /api/federated-config/bundle` is called with a known type
- **THEN** the response status MUST be `200` and the bundle MUST be returned

#### Scenario: Another organisation's flow is not bundled

- **GIVEN** a `{flowIds: [...]}` selection naming a flow of another organisation
- **WHEN** the flows type is serialised
- **THEN** that flow MUST be absent from the bundle
- **AND** the unscoped flow mapper MUST NOT be consulted

---

### Requirement: A bulk row whose schema cannot be resolved is refused, not written (REQ-FSE-004)

The bulk save safeguard SHALL distinguish "the caller named no schema"
(mixed-schema batch) from "the caller named a schema that could not be loaded".
The first SHALL continue to pass rows through to the downstream preparation,
which reports them as invalid with the proper error shape. The second SHALL
reject the row when RBAC is in force, because the per-row permission check is
what a resolved schema enables, and skipping it is a decision to write with no
authorisation check at all.

#### Scenario: An unresolvable named schema refuses the row

- **GIVEN** a bulk save naming schema `zaak`, and loading it throws
- **WHEN** the safeguard runs with RBAC in force
- **THEN** the row MUST NOT be returned as passed
- **AND** it MUST be recorded as invalid with a reason naming the schema resolution

#### Scenario: A mixed-schema batch is still passed through

- **GIVEN** a bulk save naming no schema at all
- **WHEN** the safeguard runs
- **THEN** the rows MUST be passed through and no schema lookup MUST be attempted

#### Scenario: A resolvable schema still reaches the RBAC gate

- **GIVEN** a bulk save naming a schema that loads
- **WHEN** the safeguard runs with RBAC in force
- **THEN** the per-row permission check MUST be invoked
- **AND** a row it allows MUST be passed through, a row it denies MUST be rejected

### Requirement: A share never grants more than its creator holds (REQ-FSE-005)

A share token is served without access control, so creating an outgoing share SHALL require
that the signed-in creator holds every right the share hands out:

- an `object`-scope share with `permissions: read` needs the creator's own read of that object
  (the normal read path, with access control and organisation filtering on);
- a `register`-scope share needs the right to manage that register;
- every other share (`schema` or `query` scope, and any `read-write` share) needs the right to
  manage its schema. Reading a schema is not enough, because conditional and per-object rules
  limit a reader record by record and a token serves every record of its scope.

The `manage` right is default-secure: an administrator, or a member of a group the schema's or
register's `manage` rule names. A refused share SHALL NOT be stored and SHALL be answered `403`.
A query filter that is not a set of conditions, or that names a `register` or `schema` key (which
would move the search to another register or schema), SHALL be refused with `400`. A share stored
before this rule SHALL have those two keys ignored when it is served.

A share's schema SHALL be judged as serving resolves it, inside the share's register; a share that
names no register or schema, or names a schema its register does not carry, SHALL be refused.

Revoking a share SHALL need the same rights as creating it, except that an administrator may always
revoke, so a share whose target has gone can still be ended. Listing the organisation's shares
SHALL return a share's token only to a caller who holds those rights; for everybody else the
token is empty. The creator's user id SHALL be stored with the share, and
`occ openregister:federation:share-rights` SHALL report, without changing anything, every live
outgoing share whose creator no longer holds its rights, no longer exists, or was not recorded.

#### Scenario: A member without the manage right cannot mint a read-write share

- **GIVEN** a signed-in member of `org-a` who may not manage schema `zaak`
- **WHEN** they create a `schema`-scope share of `zaak` with `permissions: read-write`
- **THEN** the response status MUST be `403`
- **AND** no share MUST be stored

#### Scenario: An object share needs the creator's own read of the object

- **GIVEN** a signed-in member whose own read of object `secret-1` is refused
- **WHEN** they create an `object`-scope read share naming `secret-1`
- **THEN** the response status MUST be `403`
- **AND** no share MUST be stored

#### Scenario: A manager of a same-named schema cannot share another register's schema

- **GIVEN** a user who may manage schema `zaak` of register `a`, and not schema `zaak` of register `b`
- **WHEN** they create a `schema`-scope share naming register `b` and schema `zaak`
- **THEN** the schema of register `b` MUST be the one judged
- **AND** the response status MUST be `403`

#### Scenario: A schema manager creates the share and its creator is recorded

- **GIVEN** a signed-in user who may manage schema `zaak`
- **WHEN** they create a `schema`-scope share of `zaak`
- **THEN** the share MUST be stored with their user id as its owner and returned with its token

#### Scenario: Listing hides the tokens of shares the caller could not grant

- **GIVEN** a share of schema `zaak` in organisation `org-a`
- **WHEN** a member of `org-a` who may not manage `zaak` lists the organisation's shares
- **THEN** that share MUST be listed with an empty token
