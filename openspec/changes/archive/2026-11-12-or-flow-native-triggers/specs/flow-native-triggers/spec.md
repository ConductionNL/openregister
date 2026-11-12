## ADDED Requirements

### Requirement: Flows fire on native file and user events (REQ-NT-001)

OpenRegister SHALL fire flow triggers on Nextcloud file events (`file.created`,
`file.updated`, `file.deleted`) and user events (`user.created`, `user.deleted`).
These triggers carry no object subject; the event's details SHALL be placed on
the run as a `payload`, and each field SHALL be read defensively so one
unreadable field does not lose the trigger. The catalog SHALL list these triggers.

#### Scenario: A file event fires with its payload

- **GIVEN** a flow wired to `file.created`
- **WHEN** a file is created
- **THEN** a run is queued with the file's id, path, name and mimetype as its payload

#### Scenario: The flow reads the payload as its first item

- **GIVEN** a queued subjectless run carrying a payload
- **WHEN** the worker advances it
- **THEN** the run's first item is the payload

### Requirement: Native triggers match on the trigger id alone (REQ-NT-002)

A file or user has no register or schema, so a flow wired to a native trigger
SHALL match on the trigger id alone, regardless of register/schema.

#### Scenario: A file trigger has no object subject

- **WHEN** a file event fires
- **THEN** the trigger carries an empty subject

@e2e exclude backend triggers — covered by NativeFlowTriggerListenerTest and
live-verified on 8080 (catalog lists the triggers; a payload-seeded run completed
with the file path as its first item); driving them from the real Files/User UI
is covered once a flow store is provisioned
