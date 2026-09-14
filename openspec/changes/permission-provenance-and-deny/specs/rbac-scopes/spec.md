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
