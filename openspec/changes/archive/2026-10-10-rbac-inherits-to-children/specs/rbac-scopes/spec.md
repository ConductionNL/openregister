# rbac-scopes

## ADDED Requirements

### Requirement: A schema declares the property that names its parent (REQ-RIC-001)

A schema MAY declare `x-openregister-hierarchy` with a `parent` property
name and a `maxDepth`. The named property MUST be a declared reference to
the same schema; a schema that names anything else SHALL fail to save with
HTTP 422. A schema without the annotation SHALL resolve authorization
exactly as it does today.

#### Scenario: a valid hierarchy declaration is accepted

- **GIVEN** a schema `case` with a `parentCase` property declared as a reference to `case`
- **WHEN** the schema is saved with `x-openregister-hierarchy: {"parent": "parentCase", "maxDepth": 5}`
- **THEN** the save succeeds
- @e2e exclude {annotation validator, covered by unit tests}

#### Scenario: a parent property that points elsewhere is refused

- **GIVEN** a schema `case` whose `assignee` property references the schema `user`
- **WHEN** the schema is saved with `x-openregister-hierarchy: {"parent": "assignee"}`
- **THEN** the save fails with HTTP 422 and the message names the property
- @e2e exclude {annotation validator, covered by unit tests}

#### Scenario: an undeclared hierarchy changes nothing

- **GIVEN** a schema without `x-openregister-hierarchy`
- **WHEN** a per-object grant is evaluated on one of its objects
- **THEN** the answer is the same as before this change
- @e2e exclude {regression assertion, covered by unit tests}

### Requirement: A grant on an ancestor answers for its descendants (REQ-RIC-002)

Where a schema declares its hierarchy, a per-object grant on an object
SHALL grant the same verbs on every descendant reachable through the
declared parent property, without a second grant. The inherited grant
SHALL carry the ancestor's verbs and no others. A grant written directly
on a descendant SHALL be evaluated beside the inherited one under the
existing most-specific-wins resolution. The object list SHALL return the
same set the per-object check allows.

#### Scenario: read on the root reaches the grandchild

- **GIVEN** a root object, a child and a grandchild linked by the declared parent property
- **AND** a user holding a per-object `read` grant on the root only
- **WHEN** the user reads the grandchild
- **THEN** the grandchild is returned

#### Scenario: read does not become write

- **GIVEN** the same user and the same `read` grant on the root
- **WHEN** the user saves a change to the child
- **THEN** the write is refused

#### Scenario: the list agrees with the read

- **GIVEN** the same user and the same grant
- **WHEN** the user lists the schema's objects
- **THEN** the root, the child and the grandchild are all in the result
- **AND** an object in a different tree they hold no grant on is not

#### Scenario: a direct grant on a descendant still applies

- **GIVEN** a user with `read` on the root and a direct `update` grant on the child
- **WHEN** the user saves a change to the child
- **THEN** the write succeeds
- @e2e exclude {resolution order, covered by unit tests}

### Requirement: Resolution is bounded and fails closed (REQ-RIC-003)

Ancestor resolution SHALL stop at the schema's `maxDepth` and SHALL detect
a cycle in the parent chain. In both cases the resolution SHALL return no
grant and SHALL log the refusal with the object and the reason. Resolution
SHALL be a single recursive query on both the object path and the list
path.

#### Scenario: a cycle grants nothing

- **GIVEN** two objects that name each other as parent
- **AND** a user with a per-object grant on neither
- **WHEN** the user reads one of them
- **THEN** access is refused and the refusal is logged with the cycle
- @e2e exclude {fail-closed path, covered by unit tests}

#### Scenario: a chain longer than maxDepth stops

- **GIVEN** a schema with `maxDepth: 3` and a chain of six objects
- **AND** a user holding a grant on the top object only
- **WHEN** the user reads the sixth
- **THEN** access is refused
- @e2e exclude {depth cap, covered by unit tests}

#### Scenario: a list over a tree stays one query

- **GIVEN** a tree of 500 objects at depth 5
- **WHEN** a user with a grant on the root lists them
- **THEN** the list is answered within the performance budget of openregister ADR-009
- @e2e exclude {performance assertion, covered by the query-count test}

### Requirement: An inherited grant names where it came from (REQ-RIC-004)

`GET /api/scopes` and the scope audit SHALL report an inherited grant with
the ancestor object that carries it, distinguishable from a grant written
on the object itself.

#### Scenario: the audit points at the ancestor

- **GIVEN** a user who may read a grandchild only through a grant on the root
- **WHEN** an administrator asks who has access to the grandchild
- **THEN** the user is listed with the root named as the source of the grant
- @e2e exclude {discovery endpoint, covered by Newman}
