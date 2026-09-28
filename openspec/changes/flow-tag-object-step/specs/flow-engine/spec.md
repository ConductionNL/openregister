# flow-engine

## ADDED Requirements

### Requirement: A flow step can add or remove a tag on an object

The flow engine SHALL offer a built-in step `openregister.tag-object` that adds
or removes a named Nextcloud system tag on the object of each item, or on an
object named by a template. It SHALL act as the run's identity and SHALL fail,
naming the object, when that identity may not update the object. Items SHALL
pass through unchanged.

#### Scenario: a large lead is labelled by a rule

- **GIVEN** a sales manager's flow triggered on lead created, filtered on `value` above 10000, with a tag step adding "Large deal" in colour `d94c3d`
- **WHEN** a lead with value 25000 and a lead with value 4000 are created
- **THEN** `GET /api/objects/{register}/{schema}/{id}/tags` lists "Large deal" for the first lead and nothing for the second
- **AND** the tag "Large deal" carries colour `d94c3d`
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/flow-tag-object.spec.ts}

#### Scenario: a run identity without update is refused

- **GIVEN** a flow running as a user who can read but not update leads
- **WHEN** its tag step reaches a lead
- **THEN** the step fails naming the lead's uuid and the lead's tags are unchanged
- @e2e exclude {specified only; task 2.1 adds TagObjectNodeTest, task 3.1 adds tests/e2e/ci/flow-tag-object.spec.ts}

### Requirement: The tag step changes nothing that is already so

Adding a tag an object already has, or removing a tag it does not have, SHALL
do nothing and SHALL NOT raise a tag assigned or unassigned event. A colour
given on the step SHALL be applied when the tag is created, and to an existing
tag only when that tag has no colour.

#### Scenario: a tag-triggered flow does not loop on its own step

- **GIVEN** a flow triggered on `tag.assigned` for "Large deal" whose step adds "Large deal"
- **WHEN** a user tags a lead "Large deal"
- **THEN** the flow runs once and its step assigns nothing
- @e2e exclude {specified only; task 2.1 adds TagObjectNodeTest, task 3.1 adds tests/e2e/ci/flow-tag-object.spec.ts}

#### Scenario: an administrator's colour is kept

- **GIVEN** a tag "Large deal" an administrator coloured `2d7b3f`
- **WHEN** a flow's step adds "Large deal" with colour `d94c3d`
- **THEN** the tag keeps colour `2d7b3f`
- @e2e exclude {specified only; task 1.1 adds TaggingHandlerTest, task 3.1 adds tests/e2e/ci/flow-tag-object.spec.ts}
