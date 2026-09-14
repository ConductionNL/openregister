# notificatie-engine

## ADDED Requirements

### Requirement: A group or a declared role is a recipient (REQ-NRG-001)

A notification rule MAY address a Nextcloud group or a role declared on
the object, in addition to a named user. The members SHALL be resolved at
dispatch time, and each member's own effective preference SHALL still
apply. A group that cannot be resolved SHALL be reported as a failed
dispatch rather than delivered to nobody silently.

#### Scenario: an unassigned case warns the team

- **GIVEN** a rule addressing the group that owns a register, and an object with no assignee
- **WHEN** the rule fires
- **THEN** every member of the group who has not switched the notification off receives it

#### Scenario: a new colleague gets the warning

- **GIVEN** the same rule, and a user added to the group after the rule was written
- **WHEN** the rule fires
- **THEN** that user receives it

#### Scenario: an unresolvable group is reported

- **GIVEN** a rule addressing a group that no longer exists
- **WHEN** it fires
- **THEN** the dispatch is recorded as failed, naming the group
- @e2e exclude {failure path, covered by unit tests}

### Requirement: The effective preference merges schema, group and user, and names the layer (REQ-NRG-002)

The effective preference SHALL be the schema default, overridden by a
group default, overridden by the user's own value. A group default SHALL
be settable by an administrator of that group. The effective-preferences
API SHALL name which layer decided each value.

#### Scenario: a team default overrides the schema default

- **GIVEN** a schema default of e-mail and a group default of in-app for one notification kind
- **WHEN** a member of that group reads their effective preferences
- **THEN** the value is in-app and the layer is named as the group

#### Scenario: the user still wins

- **GIVEN** the same group default and a user override of e-mail
- **WHEN** the same read happens
- **THEN** the value is e-mail and the layer is named as the user

### Requirement: A preference may be scoped to a register, a schema or a declared domain (REQ-NRG-003)

A user or a group MAY set a preference for one register, one schema or one
domain a leaf app declares, which SHALL override their own global value for
that scope only. A scope with no preference SHALL fall through to the
global value with no migration.

#### Scenario: loud for vergunningen, quiet for meldingen

- **GIVEN** a user whose global preference is off and who sets e-mail for one schema
- **WHEN** an event fires on that schema and on another
- **THEN** they are notified for the first and not for the second

### Requirement: One rule reaches a person and an integration, recorded once (REQ-NRG-004)

A rule SHALL declare its transports, and one firing SHALL run all of them.
The system SHALL record one outcome per transport under one event
identifier, so a user notification and an outbound integration call that
came from the same event can be read together.

#### Scenario: one event, two transports, one record

- **GIVEN** a rule declaring the in-app transport and an outbound call
- **WHEN** it fires
- **THEN** both run, and the record holds one event with two transport outcomes

#### Scenario: one transport failing does not stop the other

- **GIVEN** the same rule with an outbound endpoint that is down
- **WHEN** it fires
- **THEN** the in-app notification is delivered and the outbound outcome is recorded as failed
- @e2e exclude {transport failure, covered by unit tests}

### Requirement: A broadcast reaches every user once, recorded (REQ-NRG-005)

An administrator SHALL be able to send one message to every user, with a
subject, a body and a period it is shown for. Each user SHALL receive it
once. The broadcast SHALL be recorded with its sender, its content and its
period.

#### Scenario: a storingsmelding reaches the desk

- **GIVEN** an administrator sending a broadcast for today
- **WHEN** users open the application
- **THEN** each sees it once, and the record names the sender

### Requirement: Every platform event ships an editable template (REQ-NRG-006)

Each event the platform raises SHALL ship a named, editable message
template with its variables documented. The system SHALL be able to list
the events that have no template, and SHALL NOT substitute a generic body
silently for a missing one.

#### Scenario: the gaps are listable

- **GIVEN** an instance where one event has no template
- **WHEN** the template list is read
- **THEN** that event is named as having none

#### Scenario: an administrator edits the shipped text

- **GIVEN** a shipped template
- **WHEN** an administrator edits it and the event fires
- **THEN** the edited text is used
