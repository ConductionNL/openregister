# rbac-scopes

## ADDED Requirements

### Requirement: The set of grantable permissions is published (REQ-PPD-001)

The system SHALL expose every permission that may be granted in this
instance through a discovery endpoint. Each entry SHALL name the verb,
the app that declared it, the scope levels it may be granted at and a
description a human can read. The canonical verbs (`read`, `create`,
`update`, `delete`, `list`, `manage`) SHALL always be present. A custom
verb SHALL appear only when an app has declared it, and a verb that is
not in the catalogue SHALL NOT be grantable: a role's `actions` array or
an authorization block naming an unknown verb SHALL fail to save with
HTTP 422 naming the verb.

#### Scenario: an administrator reads what can be granted

- **GIVEN** an instance where one app has declared the custom verb `publish`
- **WHEN** a client requests the permission catalogue
- **THEN** the response includes the six canonical verbs and `publish`
- **AND** `publish` names the app that declared it and a description
- @e2e exclude {covered by the catalogue unit tests and the e2e in task 4.1}

#### Scenario: an unknown verb is refused at save

- **GIVEN** a register whose `roles` configuration names the action `approve`, which no app declares
- **WHEN** the register is saved
- **THEN** the save fails with HTTP 422 and the message names `approve`
- @e2e exclude {validator, covered by unit tests}

#### Scenario: a declared verb without an evaluator fails closed

- **GIVEN** a declared custom verb whose app registers no listener for `CustomScopeEvaluatingEvent`
- **WHEN** the verb is evaluated
- **THEN** the answer is a refusal
- **AND** the refusal names the app that owes the listener
- @e2e exclude {resolver behaviour, covered by unit tests}

### Requirement: A rule may deny a verb, and a deny is not overridden (REQ-PPD-002)

An authorization entry SHALL be able to name a verb as denied for a
group, a role or an object. A deny SHALL remove that verb inside its
scope and SHALL NOT be overridden by a broader grant, including a grant
inherited from an ancestor object under `x-openregister-hierarchy`. A
grant and a deny written for the same principal at the same level SHALL
fail to save with both rules named. The object list SHALL return the same
set the per-object check allows, so a denied object SHALL NOT appear in a
list.

#### Scenario: a deny on a child beats an inherited grant

- **GIVEN** a user holding a per-object `read` grant on a root object
- **AND** a deny of `read` on one child of that root
- **WHEN** the user reads that child
- **THEN** the read is refused

#### Scenario: the denied object is absent from the list

- **GIVEN** the same user, the same grant and the same deny
- **WHEN** the user lists the schema's objects
- **THEN** the root and its other descendants are returned
- **AND** the denied child is not in the list

#### Scenario: a broader grant does not restore a denied verb

- **GIVEN** a schema rule granting `update` to the group `behandelaars`
- **AND** a deny of `update` for the role `waarnemer`, held by a member of that group
- **WHEN** that member saves a change
- **THEN** the write is refused
- @e2e exclude {resolution precedence, covered by unit tests}

#### Scenario: a grant and a deny at one level is a configuration error

- **GIVEN** an authorization block granting and denying `delete` to the same group at the same level
- **WHEN** the block is saved
- **THEN** the save fails with HTTP 422
- **AND** the message names both rules
- @e2e exclude {validator, covered by unit tests}

### Requirement: Administration cannot be denied away (REQ-PPD-003)

A deny SHALL NOT remove `manage` from the last principal holding it on a
register. The save SHALL be refused, and the refusal SHALL name what
would be left without an administrator.

#### Scenario: the last manager cannot be denied

- **GIVEN** a register where one group holds `manage`
- **WHEN** an administrator saves a deny of `manage` for that group
- **THEN** the save fails
- **AND** the message names the register and the group
- @e2e exclude {validator, covered by unit tests}

### Requirement: Every effective permission names the rule that decided it (REQ-PPD-004)

The effective-scope discovery endpoint SHALL report, for each granted
action, the rule that granted it: the register default, the schema rule,
the named role, the per-object grant, or the ancestor object it was
inherited from. For an action a broader rule would have granted and a
deny removed, it SHALL report that deny. The existing `actions` list
SHALL keep its shape so existing callers are unaffected. The scope audit
SHALL report the same provenance, and a denial log entry SHALL name the
rule and not only the decision.

#### Scenario: a grant says where it came from

- **GIVEN** a user who may `read` a schema through a role on the register
- **WHEN** the client requests the effective scopes
- **THEN** `read` is listed in `actions`
- **AND** its provenance names the role and the register
- @e2e exclude {covered by the scopes endpoint unit tests and the e2e in task 4.1}

#### Scenario: an absence says which rule removed it

- **GIVEN** a user who would hold `update` through a schema rule, with `update` denied for their role
- **WHEN** the client requests the effective scopes
- **THEN** `update` is absent from `actions`
- **AND** the provenance names the deny and the role it was written for
- @e2e exclude {covered by the scopes endpoint unit tests}

#### Scenario: an instance with no deny is unchanged

- **GIVEN** an instance declaring no deny and no custom verb
- **WHEN** any authorization decision is made
- **THEN** the answer is the same as before this change
- @e2e exclude {regression assertion, covered by unit tests}

### Requirement: Access is compiled into the query, not applied to the result (REQ-PPD-005)

Grants, inherited grants and denies SHALL be compiled into the object
query and into the search index query as predicates, so that the returned
page, the total and every facet count are computed over the set the caller
may see. The system SHALL NOT filter a fetched page after the fact. The
list path and the per-object check SHALL agree for every object.

#### Scenario: the total counts only what the caller may see

- **GIVEN** a schema holding 100 objects of which the caller may read 12
- **WHEN** the caller lists the schema with a page size of 10
- **THEN** the total is 12, the first page holds 10 and the second holds 2

#### Scenario: facets count the permitted set

- **GIVEN** the same caller and a facet over a property
- **WHEN** the facet is requested
- **THEN** the counts sum to 12

#### Scenario: search and list agree

- **GIVEN** the same caller and an object they may not read
- **WHEN** they search for a term that object contains
- **THEN** the object is absent from the results and from the result count
- @e2e exclude {index path, covered by unit tests and the search suite}

### Requirement: A record is returned with the actions its reader may take (REQ-PPD-006)

An object read SHALL carry the actions the current user may take on that
object, resolved in the same pass that decided the read. The list SHALL
contain the verbs the caller actually holds, deny included, so a client
does not have to guess and does not discover a refusal by attempting it.

#### Scenario: the page renders only what is allowed

- **GIVEN** a user who may read and update an object but may not delete it
- **WHEN** the object is read
- **THEN** the response lists `read` and `update` and does not list `delete`

#### Scenario: a deny removes the action from the record

- **GIVEN** the same user with `update` denied on that one object
- **WHEN** the object is read
- **THEN** `update` is absent from the actions

### Requirement: An object answers who holds which right on it, and how that changed (REQ-PPD-007)

The system SHALL answer, for a named object, which principals hold which
verbs on it and the rule behind each grant. The system SHALL keep the
history of that set, so it can report who held which right at a past
moment and which rule changed it. Two roles SHALL be readable side by side
against the catalogue, showing which permissions differ.

#### Scenario: an auditor asks who could open a dossier

- **GIVEN** an object reachable by one role grant, one per-object grant and one inherited grant
- **WHEN** the object's effective permissions are read
- **THEN** the three principals are listed, each with its verbs and the rule behind it

#### Scenario: the access history answers a question about last year

- **GIVEN** a grant that was removed three months ago
- **WHEN** the object's access history is read for a date before the removal
- **THEN** the grant is reported as held at that date, with the rule that removed it afterwards
- @e2e exclude {history path, covered by unit tests}

#### Scenario: two roles are compared

- **GIVEN** the roles `behandelaar` and `senior behandelaar`
- **WHEN** the two are compared
- **THEN** the verbs only the senior role holds are listed
- @e2e exclude {catalogue read, covered by unit tests}

### Requirement: Grants may be derived at login, scoped, and given an end (REQ-PPD-008)

A rule SHALL be able to map the claims an identity provider asserts to
roles and scopes when a user signs in, in the same declared shape as any
other authorization rule. A grant MAY carry an end, including an end bound
to the deadline of the workflow step that created it, and an expired grant
SHALL NOT be resolved. When a rule that derives access changes, the system
SHALL re-run the derivation and report how many grants changed. The
`manage` verb SHALL be grantable scoped to a named area, so a person may
administer part of the instance without administering all of it.

#### Scenario: a new employee is authorised without a matrix

- **GIVEN** a rule mapping the claim `department: vergunningen` to the role `behandelaar` in that department
- **WHEN** a user with that claim signs in
- **THEN** they hold the role in that department and no other

#### Scenario: a step's grant dies with the step

- **GIVEN** a workflow step granting `read` on a file to its assignee until its deadline
- **WHEN** the deadline passes and the assignee reads the file
- **THEN** the read is refused
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}

#### Scenario: changing the rule reports what moved

- **GIVEN** a derivation rule granting access to 240 objects
- **WHEN** the rule is narrowed and saved
- **THEN** the derivation re-runs and the response names how many grants changed

#### Scenario: a delegated administrator cannot administer everything

- **GIVEN** a user holding `manage` scoped to one register
- **WHEN** they try to change the configuration of another register
- **THEN** the write is refused naming the scope of their grant
