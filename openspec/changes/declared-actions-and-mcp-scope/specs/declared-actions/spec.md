# Declared actions and the `mcp` scope

An extensible but gated action vocabulary, a scope describing what may be offered
to an agent, and an index of the rights that exist to give.

## ADDED Requirements

### Requirement: A schema may declare additional actions, and only declared ones may be authorised

A schema MAY declare actions under `x-openregister-action`, each with a `name`
and a `description`. An `authorization` block MAY name an action only when it is
one of `create`, `read`, `update`, `delete`, `list`, or is declared on that
schema. Any other action SHALL fail the schema import.

Declaring an action SHALL NOT itself grant or enforce anything. It defines a name
that authorization blocks, event listeners and the grantable-rights index may
refer to.

#### Scenario: A declared action can be authorised
- **GIVEN** a schema declares `sendMail` under `x-openregister-action`
- **WHEN** its authorization block grants `sendMail` to a group
- **THEN** the schema imports successfully

#### Scenario: An undeclared action fails the import
- **WHEN** a schema's authorization block names an action that is neither canonical nor declared
- **THEN** the import fails, naming the offending action and the allowed set
- **AND** the schema is not written

#### Scenario: A typo is rejected rather than silently ineffective
- **WHEN** an authorization block names `raed`
- **THEN** the import fails
- **AND** it does NOT import as a right that can never be granted and never errors

### Requirement: Actions raise events

Every action SHALL dispatch an event carrying the register, schema, action,
object id and actor. Events SHALL fire for refused actions as well as permitted
ones. No listener SHALL be registered by default.

#### Scenario: A refusal is observable
- **WHEN** an actor attempts an action they are not permitted
- **THEN** an event is dispatched recording the attempt and its refusal

#### Scenario: A permitted action is observable
- **WHEN** an actor performs a declared action successfully
- **THEN** an event is dispatched carrying register, schema, action, object id and actor

### Requirement: The `mcp` scope describes what may be offered, never what is held

`mcp` SHALL be recognised beside `public`, `authenticated` and `admin`. An action
authorised to `mcp` on a schema means that action MAY be offered to an agent.

It SHALL NOT make any tool callable by any agent. Whether a specific agent holds
a right SHALL remain resolved by Hermiq against that agent's own grants, because
RBAC groups are per user and cannot separate two agents owned by one person.

#### Scenario: The scope does not confer the right
- **GIVEN** a schema authorises `read` to `mcp`
- **AND** an agent holds no grant for that tool
- **WHEN** the agent calls it
- **THEN** it is refused exactly as an ungranted tool is

#### Scenario: Two agents under one owner differ
- **GIVEN** two agents owned by the same user
- **WHEN** one is granted a tool and the other is not
- **THEN** only the granted one may call it

### Requirement: The grantable-rights index is cached and write-invalidated

The system SHALL maintain an index of every `(register, schema, action)` that may
be offered, built across all schemas. It SHALL be invalidated when a schema is
created, updated or deleted, and SHALL NOT rely on time-based expiry.

#### Scenario: A revoked right stops being offered
- **WHEN** a schema's authorization is changed to remove an action from `mcp`
- **THEN** the index no longer lists it
- **AND** it is not served as grantable

#### Scenario: An absent index rebuilds rather than serving stale data
- **WHEN** the index is missing
- **THEN** it is rebuilt on read
- **AND** no stale entry is served in the meantime
