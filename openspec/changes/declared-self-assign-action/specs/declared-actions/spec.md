# declared-actions (delta)

## ADDED Requirements

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
