## MODIFIED Requirements

### Requirement: Who may answer a task is decided by resolving its performers

The task service SHALL decide whether a caller may answer a task by resolving
the task's performer references through the principal resolvers and asking
whether the caller is among the resulting user ids.

It SHALL NOT decide this by string comparison against the stored assignee,
and SHALL NOT decide it by group membership inferred from the assignee's
spelling. Those two rules are the whole of the current decision, and together
they mean a performer is authorised only when somebody happened to name a
group exactly right.

The five sanctioned visibility relationships are unchanged. Only the
resolution of *performer* changes: `requester`, `watcher`, administrator and
subject-anchored visibility all keep their present meaning.

A task naming no performer SHALL keep whatever behaviour it has today. This
change narrows nothing and widens nothing about unassigned tasks.

#### Scenario: A resolved performer may answer

- **GIVEN** a task assigned to `{type: 'group', id: 'behandelaars'}`
- **AND** alice is a member of that group
- **WHEN** alice completes the task
- **THEN** the completion MUST be accepted

#### Scenario: A caller outside every resolution may not answer

- **GIVEN** the same task
- **AND** bob is in no group and is not the requester or a watcher
- **WHEN** bob attempts to complete it
- **THEN** the attempt MUST be refused

---

### Requirement: A task records both the reference and the resolution that created it

A task SHALL store the performer reference as authored, and SHALL
additionally record the user ids that reference resolved to at the moment the
task was created.

The stored reference is what authorises: it is re-resolved on every answer,
so a task follows the role rather than the roster.

The recorded resolution is evidence, not authority. It answers "who was this
in front of when it was raised", which is the question an audit asks after a
committee has been re-composed and the reference alone can no longer say. It
SHALL NOT be consulted by the answer guard.

#### Scenario: The recorded resolution does not authorise a former member

- **GIVEN** a task created while alice was in `juristen`
- **AND** alice has since left that group
- **WHEN** alice attempts to answer
- **THEN** the attempt MUST be refused
- **AND** the recorded resolution MUST still show alice, as evidence of who
  was asked
