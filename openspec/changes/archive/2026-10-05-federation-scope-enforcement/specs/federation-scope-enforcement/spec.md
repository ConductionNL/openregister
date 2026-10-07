## ADDED Requirements

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
the grant, and confidentiality filtering remains their guard.

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
