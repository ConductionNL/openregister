# object-access-contract

## ADDED Requirements

### Requirement: Additional object access control is declared on the schema, not guarded in an app

An app that needs access to an object to depend on that object's own data SHALL
express it in the schema or object `authorization` block, using the principal
vocabulary and the `match` clause OpenRegister already resolves. It SHALL NOT
implement a service that loads the object and decides access itself.

OpenRegister SHALL enforce the declaration on both layers: `PermissionHandler`
for a single object, `MagicRbacHandler` for the list and aggregation paths. The
two SHALL agree, so an object a caller may not read is absent from a list and
refused on a find, never one and not the other.

A guard in a consuming app is permitted only where the rule cannot be
expressed declaratively, and SHALL carry a comment naming the expression it
tried and why it failed.

#### Scenario: a case assignee reads their case and no other

- **GIVEN** a `case` schema whose `authorization.read` holds `{ "group": "authenticated", "match": { "assignee": "$userId" } }`
- **WHEN** a user who is the assignee of one case lists cases
- **THEN** the list holds that case only, and a find on any other case is refused
- @e2e exclude {specs only in this change; task 5.2 adds tests/e2e/api-direct/object-access-contract.spec.ts when the contract ships}

#### Scenario: a member of the assignees array is admitted on both layers

- **GIVEN** the same schema with a second entry matching `{ "assignees": { "$contains": "$userId" } }`
- **WHEN** a user named in `assignees` but not in `assignee` lists cases and then finds one by uuid
- **THEN** both answer the same set, and neither admits a case that names neither
- @e2e exclude {the layer-agreement assertion is a unit test on MagicRbacHandler against PermissionHandler}

#### Scenario: a stranger is refused, and the refusal is the least privileged probe

- **GIVEN** the same schema and a case naming neither
- **WHEN** an ordinary authenticated user who is in no relevant group requests it
- **THEN** the request is refused, and the refusal is recorded with the principal and the rule that did not match
- @e2e exclude {specs only in this change; task 5.2 adds the probe}

### Requirement: A schema declares what an undecidable access question means

An `authorization` block MAY declare `onUndecidable` with the value `closed` or
`open`. Absent, it SHALL be `closed`.

A decision is undecidable when the object cannot be resolved, the schema is not
configured, or the evaluation throws. OpenRegister SHALL apply the declared
posture, SHALL log the question that could not be answered together with what
was missing, and SHALL NOT let an evaluation error read as a grant unless the
schema says `open`.

#### Scenario: a schema that says nothing refuses when the object cannot be resolved

- **GIVEN** a schema with no `onUndecidable`
- **WHEN** the access evaluation cannot resolve the object
- **THEN** access is refused and a warning names the object and the missing input
- @e2e exclude {an injected resolution failure is a unit test, not a browser path}

#### Scenario: a schema that says open is honoured and still logged

- **GIVEN** a schema declaring `onUndecidable: open`
- **WHEN** the same evaluation cannot resolve the object
- **THEN** access is granted and a warning still names the object and the missing input
- @e2e exclude {as above}

### Requirement: The issuer of a grant holds the access they issued from

When a principal issues a per-object grant, `ObjectGrantResolver` SHALL record
that principal as the grant's issuer. An issuer SHALL hold, on that object, the
access the grant conveys, for as long as the grant stands.

Revoking the grant SHALL revoke the issuer's derived access with it.

Access SHALL NOT be inferred from any other property of any other object. A
`createdBy` on a share record is data, not a grant.

#### Scenario: the issuer keeps reading after the recipient is removed from the team

- **GIVEN** a user who issued a read grant on an object they could reach
- **WHEN** the schema rule that once admitted them stops matching
- **THEN** they still read that object, because their own grant stands
- @e2e exclude {specs only in this change; task 5.2 covers issue and revoke}

#### Scenario: revoking the grant revokes the issuer's derived access

- **GIVEN** the same user and grant
- **WHEN** the grant is revoked
- **THEN** the issuer is refused on that object
- @e2e exclude {as above}

### Requirement: A grant is validated before it is issued

The grant issue path SHALL call `TokenGrantValidator::refusalFor()` and SHALL
refuse issuance when it answers a reason. The reason SHALL reach the caller.

A validator with no production caller SHALL be removed rather than kept.

#### Scenario: a grant naming no verb is refused at issue

- **WHEN** a caller issues a grant whose `verbs` list is empty
- **THEN** issuance is refused and the answer carries the validator's reason
- @e2e exclude {unit-tested on the issue endpoint; the validator's own cases already have tests}

#### Scenario: a grant wider than the issuer's own rights is refused

- **WHEN** a caller issues a grant naming a verb they do not themselves hold
- **THEN** issuance is refused naming that verb
- @e2e exclude {as above}

### Requirement: An app can ask one question instead of guessing

OpenRegister SHALL answer, over its permissions surface, whether a named
principal may perform a named action on a named object, and SHALL return the
rule that decided it.

The answer SHALL be computed by the same code that enforces the decision, so it
cannot drift from what the read path does.

#### Scenario: the answer and the enforcement agree on a refusal

- **GIVEN** an object an ordinary user may not read
- **WHEN** the app asks whether that user may read it, and then reads it as that user
- **THEN** the answer is no and the read is refused
- @e2e exclude {specs only in this change; the agreement assertion is a unit test over both paths}
