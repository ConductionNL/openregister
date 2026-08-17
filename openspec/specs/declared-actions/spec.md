# declared-actions Specification

## Purpose
TBD - created by archiving change declared-actions-and-mcp-scope. Update Purpose after archive.

## Requirements

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

Because it describes rather than decides, the scope SHALL be inert for
enforcement in BOTH directions. It SHALL NOT grant an action to any caller,
including a member of a real Nextcloud group named `mcp`. It SHALL NOT cause an
action to be refused that would otherwise be permitted: an authorization block
is fail-closed once non-empty, so a block whose only content is an `mcp` offer
SHALL evaluate as though no authorization were configured.

An explicitly empty rule list SHALL survive that treatment. `"read": []` means
"grant this action to nobody" and is the strictest rule the grammar can express;
treating it as "no rule" would make it default-open.

#### Scenario: The scope does not confer the right
- **GIVEN** a schema authorises `read` to `mcp`
- **AND** an agent holds no grant for that tool
- **WHEN** the agent calls it
- **THEN** it is refused exactly as an ungranted tool is

#### Scenario: A real group named `mcp` is not the scope
- **GIVEN** an administrator has created a Nextcloud group named `mcp`
- **AND** a schema authorises `read` to `mcp`
- **WHEN** a member of that group reads
- **THEN** the scope does not admit them

#### Scenario: Annotating a schema does not revoke
- **GIVEN** a schema with no authorization block
- **WHEN** `"read": ["mcp"]` is added to record its agent surface
- **THEN** every other action remains permitted exactly as before

#### Scenario: A deny-all rule survives the scope being stripped
- **GIVEN** a schema authorising `read` to nobody via an empty rule list
- **WHEN** the `mcp` scope is stripped from the block
- **THEN** the action is still denied

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
