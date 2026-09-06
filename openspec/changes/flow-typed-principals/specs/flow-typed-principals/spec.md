## Purpose

What it means to name a performer in a flow: a reference that carries its own
type, a registry of resolvers that turn a type and an id into real people, a
refusal at authoring time when the type is unknown and at creation time when
the reference names nobody, and one predicate that decides who may answer.

The property this capability exists to guarantee is narrow and was measurably
absent: **a step that asks somebody must be answerable by somebody.**

## ADDED Requirements

### Requirement: A performer reference carries its own type

The system SHALL represent a performer as a reference of the shape
`{type, id}`, where `type` names a kind of principal and `id` identifies one
within that kind.

The system SHALL accept the following types in the engine itself: `user` and
`group`. It SHALL accept any further type for which a resolver has been
registered, and the engine SHALL NOT enumerate those types in its own code.

A **bare string** SHALL be read as `{type: 'user', id: <string>}`. Every
performer stored before this capability existed is a bare string, and reading
it as a user reference preserves exactly the behaviour those flows have
today: `mayAnswer` matched a uid first.

A reference SHALL be accepted anywhere a performer is named: the direct
assignee, the candidate list and the routing fallback alike. A field that
takes several performers SHALL accept a list of references, mixing types
freely — a step may offer a task to one named person and two groups.

#### Scenario: A typed reference names a group

- **GIVEN** a step whose assignee is `{type: 'group', id: 'behandelaars'}`
- **WHEN** the step creates its task
- **THEN** the task MUST record the reference as given
- **AND** every member of `behandelaars` MUST be able to answer it

#### Scenario: A bare string still means a user

- **GIVEN** a step, authored before this capability, whose assignee is the
  bare string `alice`
- **WHEN** the step creates its task
- **THEN** the reference MUST be read as `{type: 'user', id: 'alice'}`
- **AND** the behaviour MUST be indistinguishable from before

#### Scenario: One field carries several references of different types

- **GIVEN** a step whose candidates are `{type: 'user', id: 'alice'}` and
  `{type: 'group', id: 'juristen'}`
- **WHEN** the step creates its task
- **THEN** both MUST be recorded
- **AND** alice MUST be able to claim it, and so MUST any member of
  `juristen`

---

### Requirement: A resolver is contributed, never hard-coded

The system SHALL define an `IPrincipalResolver` interface. An implementation
SHALL declare the one type it resolves, and SHALL answer, for a given id, the
set of user ids that are the principal for that id at the moment it is asked.

The system SHALL collect resolvers through a dispatched registration event,
the same recipe by which flow nodes are already contributed. OpenRegister
SHALL register the `user` and `group` resolvers itself. Any other type SHALL
come from a consuming app registering a listener.

OpenRegister SHALL NOT call a consuming app directly to resolve a principal,
and SHALL NOT name decidiq, hermiq or dossiq anywhere in this mechanism. A
type whose owning app is absent from an instance SHALL simply have no
resolver there, which is a refusal (see below), not an error at boot.

Resolution SHALL be evaluated when it is needed and SHALL NOT be cached
across requests. Group membership, committee composition and job function all
change while a task is open, and a task that outlives a reorganisation must be
answerable by whoever holds the role now, not by whoever held it when the run
started.

#### Scenario: An app contributes a type the engine has never heard of

- **GIVEN** an app that registers a resolver for the type `position`
- **WHEN** a step names `{type: 'position', id: 'voorzitter'}`
- **THEN** the reference MUST be accepted
- **AND** the engine MUST resolve it by calling that resolver

#### Scenario: Membership is read when it is asked, not when the task was made

- **GIVEN** an open task assigned to `{type: 'group', id: 'juristen'}`
- **AND** the task was created while alice was not a member
- **WHEN** alice is added to `juristen` and then answers
- **THEN** the answer MUST be accepted

---

### Requirement: An unknown type is refused when the step is saved

The system SHALL refuse to save a step configuration naming a performer whose
type has no registered resolver, and the refusal SHALL name the type and the
field.

This is refused at SAVE and not at run time on purpose. A misconfigured
performer discovered at run time is discovered by a suspended run that nobody
is watching, and the author who could fix it has long since moved on.

#### Scenario: A type no app provides is refused at authoring time

- **GIVEN** an instance with no resolver for the type `gremium`
- **WHEN** an author saves a step naming `{type: 'gremium', id: 'raad'}`
- **THEN** the save MUST be refused
- **AND** the refusal MUST name both the unknown type and the field it was in

---

### Requirement: A reference that resolves to nobody refuses the task

When a step creates a task, the system SHALL resolve every performer
reference on it. If the direct assignee resolves to no user, task creation
SHALL fail, and the failure SHALL name the reference that resolved to nobody.

If the step names candidates rather than a direct assignee, creation SHALL
fail when the union of every candidate reference is empty. A task that no
candidate can see is a task in nobody's inbox.

The failure SHALL be a step failure, handled by the flow's own error policy,
so an author may deliberately choose to continue past it. It SHALL NOT be
swallowed, and it SHALL NOT produce a suspended run waiting on an
unanswerable task.

**This is the defect this capability exists for.** Measured on a live
instance, 27 of 30 asking steps named a performer that resolved to nobody;
every one of them created a task, suspended its run, and reported nothing
wrong.

#### Scenario: An empty resolution fails the step instead of parking the run

- **GIVEN** a step assigned to `{type: 'group', id: 'afdelingshoofd'}`
- **AND** no such group exists on the instance
- **WHEN** the run reaches that step
- **THEN** the step MUST fail, naming that reference
- **AND** the run MUST NOT be left suspended on an unanswerable task

#### Scenario: A group that exists but is empty is also nobody

- **GIVEN** a step assigned to a group that exists and has no members
- **WHEN** the run reaches that step
- **THEN** the step MUST fail
- **AND** the failure MUST distinguish "resolved to no users" from "no such
  principal", because the two are fixed in different places

---

### Requirement: Authorisation to answer is resolver-backed

The system SHALL decide whether a caller may answer a task by resolving the
task's performer references and asking whether the caller is among the
resulting users.

The system SHALL NOT decide this by comparing the caller's uid to the stored
assignee string, and SHALL NOT decide it by asking whether the caller belongs
to a group whose name happens to equal that string.

A task naming NO performer SHALL remain answerable by anyone the task
service already authorises, unchanged: whether an unassigned task is open to
all is a `flow-tasks` question and not this capability's to redefine.

#### Scenario: A position authorises an answer the way a group does

- **GIVEN** a task assigned to `{type: 'position', id: 'voorzitter'}`
- **AND** a resolver that answers `[bob]` for that position
- **WHEN** bob completes the task
- **THEN** the completion MUST be accepted

#### Scenario: A coincidental name no longer authorises

- **GIVEN** a task assigned to `{type: 'user', id: 'juristen'}`
- **AND** a group also named `juristen` containing alice
- **WHEN** alice attempts to answer
- **THEN** the attempt MUST be refused, because the reference names a user

---

### Requirement: Stored assignee strings are migrated once, and what cannot be migrated is reported

The system SHALL provide a repair step that reads every stored performer
string on a task and on a flow definition, resolves it once against the
registered resolvers, and rewrites it as a typed reference.

A string that resolves to exactly one type SHALL be rewritten to that type. A
string that resolves under MORE than one type SHALL be left alone and
reported, because the repair cannot know which was meant, and guessing would
silently move who may answer.

A string that resolves under no type SHALL be left alone and reported. The
repair SHALL NOT delete it, refuse the upgrade, or fail the instance: a flow
naming a performer nobody can be is already broken, and the repair's job is
to say so, not to become the thing that breaks it.

The report SHALL name each unmigrated string, the flow or task carrying it,
and which of the two reasons applies.

#### Scenario: An ambiguous string is reported rather than guessed

- **GIVEN** a stored assignee string `support` that names both a user and a
  group
- **WHEN** the repair runs
- **THEN** the string MUST be left as it is
- **AND** the report MUST name it as ambiguous, naming both types

#### Scenario: The repair does not fail an upgrade over a broken flow

- **GIVEN** 27 stored assignee strings that resolve to nothing
- **WHEN** the repair runs
- **THEN** it MUST complete
- **AND** it MUST report all 27, with the flow each belongs to
