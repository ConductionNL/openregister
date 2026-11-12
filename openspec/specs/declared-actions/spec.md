# declared-actions Specification

## Purpose
A schema can declare actions beyond create, read, update and delete, such as a macro or a named flow. Only declared actions can be authorised. Every action raises an event, a macro run lists its changes in the audit trail, and the mcp scope says which actions may be offered to an assistant.

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

The scope SHALL NOT grant an action to any caller, including a member of a real
Nextcloud group named `mcp`.

An `mcp` entry written into an `authorization` block SHALL opt the schema into
authorization, exactly as any other rule does. A block whose only content is an
`mcp` offer SHALL remain a non-empty, fail-closed block and SHALL NOT be treated
as absent — "absent" is default-open, which would publish the schema to
anonymous callers rather than restrict it. Recording an agent surface without
affecting enforcement SHALL be done with the `x-openregister-mcp` dialect.

An explicitly empty rule list SHALL be preserved. `"read": []` means "grant this
action to nobody" and is the strictest rule the grammar can express; treating it
as "no rule" would make it default-open.

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

#### Scenario: An offers-only block does not become world-readable
- **GIVEN** a schema whose only authorization content is `"read": ["mcp"]`
- **WHEN** an anonymous caller reads
- **THEN** the read is refused
- **AND** an unrelated authenticated user is refused as well

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

### Requirement: A macro can name a flow its schema declares

A macro binding's `flow` MAY name a flow the same schema declares in `x-openregister-flows`. Saving the schema SHALL
accept it when that declaration has a manual trigger and SHALL refuse it when it has none; a name no stored or declared
flow answers to SHALL be refused. Running the macro SHALL run the stored flow the declaration was imported as.

#### Scenario: dossiq ships "Opschorten voor aanvulling"

- **GIVEN** the case schema declares the flow "Opschorten voor aanvulling" with a manual trigger
- **AND** the action `suspend-for-supplement` binds it by that name
- **WHEN** the register is imported and a handler runs the macro
- **THEN** OpenRegister runs the stored flow the declaration became

### Requirement: A macro run's audit entry lists the changes it made

The `action.macro` audit entry SHALL carry `changes`: one entry per completed step of the run that wrote an object,
sent a message or created a task, in run order, each with the node's label (else its id) and the kind of change. Steps that changed
nothing or did not complete SHALL NOT be listed.

#### Scenario: Opschorten voor aanvulling

- **GIVEN** a macro whose flow writes the case ("Termijn opgeschort") and sends an email ("Bericht verstuurd")
- **WHEN** a handler runs it
- **THEN** the audit entry's `changes` lists those two steps, a write and a message

### Requirement: A declared action may assign the caller to one empty property

A schema MAY declare an action whose body is `"selfAssign": {"field": "<property>"}`. The field SHALL be a string
property of the schema. The action SHALL accept no `inputs`, no `elevate` and no `async` mode.

Running it SHALL set only that property, to the uid of the signed-in caller taken from the session. Any value in the
request SHALL be refused. It SHALL be refused with 409 when the property is not empty. The write SHALL change the
stored row only while the property is still empty, so of two callers who both read it as empty exactly one succeeds
and the other is refused with 409. It SHALL NOT require or imply the
caller's `update` right. The audit entry and the object's history SHALL name the caller.

It SHALL run only for a caller the schema grants it by name, through its authorization block or a matrix row (declared
or provided by an app). When neither names it, nobody may run it, administrators included, also on a schema whose
authorization block is empty. Owning the object SHALL NOT grant it, also on a private-scope object: the owner and the owning group may run it only
when a rule or row grants it to them. On a private-scope object only an administrator may run it: a share carries only
CRUD and share bits, so a share holder, the owner and a member of the granted group SHALL be refused with 403.

#### Scenario: A reader takes an unassigned object
- **GIVEN** a schema that declares `claim` with `"selfAssign": {"field": "assignee"}` and grants `read` and `claim` to `team`
- **WHEN** a member of `team` runs `claim` on an object whose `assignee` is empty
- **THEN** `assignee` is the member's uid and no other property changed

#### Scenario: An assigned object is not taken
- **WHEN** the same member runs `claim` on an object whose `assignee` is set
- **THEN** the run is refused with 409 and the object is unchanged

#### Scenario: A uid in the request is refused
- **WHEN** the member runs `claim` with `{"inputs": {"assignee": "someone-else"}}`
- **THEN** the run is refused with 422 and the object is unchanged

#### Scenario: Two claims on one empty object
- **GIVEN** two members of `team` who both read the object with an empty `assignee`
- **WHEN** both run `claim`
- **THEN** exactly one run succeeds and the other is refused with 409

#### Scenario: The owner is not granted it by owning the object
- **GIVEN** a schema that grants `claim` to `team` only
- **WHEN** the object's owner, who is not in `team`, runs `claim`
- **THEN** the run is refused with 403

#### Scenario: Without a grant nobody may run it
- **GIVEN** a schema that declares `claim` and names it in no rule and no matrix row
- **WHEN** anybody runs `claim`
- **THEN** the run is refused with 403

### Requirement: A matrix row may grant an action the schema declares

A row of `authorization.matrix`, declared or provided by an app, MAY name in `actions` an action the schema declares
under `x-openregister-action`. A name that is neither a matrix action nor declared by the schema SHALL be refused at
save, and an app row naming one SHALL be dropped.

#### Scenario: A row grants a declared action
- **GIVEN** a matrix row `{"group": "team", "value": "$self", "actions": ["read", "claim"]}`
- **WHEN** a member of `team` whose value matches runs `claim`
- **THEN** it is granted

### Requirement: @self.can lists the declared actions the caller may run

`_extend[]=@self.can` SHALL return `update` and one boolean per runnable declared action: true when the caller holds its
right, its `when` holds and, for a self-assign action, its property is empty. It SHALL be computed for the caller of the
request.

#### Scenario: Two callers read the same object
- **GIVEN** an unassigned object whose schema grants `claim` to `team`
- **WHEN** a member of `team` and a non-member read it with `_extend[]=@self.can`
- **THEN** the member sees `claim: true` and the non-member sees `claim: false`
