## MODIFIED Requirements

### Requirement: The node describes its own form, served from the node catalog

The step type `openregister.user-task` SHALL describe itself to the node
catalog, and the editor SHALL draw its configuration from that description
rather than from anything the editor knows about this node.

The node's display name SHALL be **"Ask a person or group"**. The node has
accepted group performers since it existed and its name said only "person",
which is why authors reached for a free-text box and typed a role name into
a field they believed took one user.

Every field that names a performer — `assignee`, `candidateUsers`,
`candidateGroups`, `candidateRole` and `routingFallback` — SHALL be declared
with the field type `principal`, not `text`.

`candidateUsers`, `candidateGroups` and `candidateRole` SHALL be superseded
by a single `candidates` field of type `principal` accepting several
references. The three separate fields exist only because a text box cannot
express a type; a typed reference can, and three fields that differ only in
the kind of thing typed into them is the API asking the author to do the
type system's job. The three SHALL keep working as input, read as candidates
of type `user`, `group` and `role` respectively.

`performerType` SHALL be removed from the config form. It duplicated, as a
free-typed word, the type that each reference now carries.

The node SHALL continue to serve its full field list from the catalog, and
the editor SHALL continue to need no change to draw a field the node adds.

#### Scenario: The catalog serves the node with typed performer fields

- **WHEN** the node catalog is read
- **THEN** the entry for `openregister.user-task` MUST be named "Ask a person
  or group"
- **AND** its `assignee` and `candidates` fields MUST declare the type
  `principal`
- **AND** no performer field MUST declare the type `text`

#### Scenario: The three old candidate fields still configure a step

- **GIVEN** a step saved before this change with `candidateGroups: "juristen"`
- **WHEN** the step is loaded
- **THEN** its candidates MUST include `{type: 'group', id: 'juristen'}`

---

### Requirement: An agent is a performer of this node, asked with a prompt

The node SHALL accept a performer reference of type `agent`, and a step whose
performer is an agent SHALL declare a `prompt` instead of a form.

An agent step SHALL produce a task row exactly as a human step does: the same
provenance, the same audit, the same lifecycle verbs, the same outcome bag.
The consequence that matters is that an agent's answer is reviewable and
re-assignable — a task an agent has not yet answered can be handed to a
person, and a person can see what was asked.

The engine SHALL NOT invoke an agent runtime. It SHALL dispatch the request
as an event, and a consuming app performs the turn and completes the task
through the ordinary verbs. This is the same boundary the node already keeps
with every other performer, and the one gate-27 and ADR-022 require.

The separate step type for running an agent turn SHALL remain, unchanged. A
turn that is not a question to be answered is not a task, and forcing it
through an inbox would put a row in front of a person for something nobody
was ever asked.

#### Scenario: An agent answers through the same verbs a person uses

- **GIVEN** a step whose performer is `{type: 'agent', id: <uuid>}` with a
  prompt
- **WHEN** the run reaches it
- **THEN** a task MUST exist carrying the run and node provenance
- **AND** the run MUST suspend until that task is terminal
- **AND** the outcome MUST reach the following steps in the ordinary outcome
  bag

#### Scenario: An unanswered agent task can be given to a person

- **GIVEN** an open task whose performer is an agent
- **WHEN** it is reassigned to `{type: 'user', id: 'alice'}`
- **THEN** alice MUST be able to answer it
- **AND** the reassignment MUST be recorded on the task's audit

---

### Requirement: A step that names nobody is refused before it can run

`validateConfig` SHALL refuse a performer reference whose type has no
registered resolver, naming the type and the field.

The node SHALL continue to refuse a step that names no performer at all. The
existing reason is unchanged and still correct: a task nobody is assigned can
be completed by anyone.

The node SHALL NOT resolve references during validation. Whether a group has
members is a fact about the instance at run time, not about the document
being saved, and refusing a save because a committee is temporarily empty
would make a flow unauthorable for a reason that has nothing to do with it.

#### Scenario: Validation refuses an unknown type but not an empty group

- **GIVEN** an instance with no resolver for `gremium`
- **WHEN** a step naming `{type: 'gremium', id: 'raad'}` is saved
- **THEN** the save MUST be refused
- **AND** a step naming an existing but empty group MUST save without
  complaint
