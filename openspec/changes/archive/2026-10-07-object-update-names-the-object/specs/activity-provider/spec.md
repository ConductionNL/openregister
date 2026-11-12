# activity-provider

## ADDED Requirements

### Requirement: A register save that changes nothing publishes nothing

The system SHALL dispatch `RegisterUpdatedEvent` from `RegisterMapper::update()`
only when the stored register differs from the register before the save,
ignoring the `updated` timestamp. A save that changes nothing SHALL NOT write a
`register_updated` activity, SHALL NOT send a `register-changed` notification
and SHALL NOT fire a register webhook.

#### Scenario: an app re-imports its unchanged register

- **GIVEN** the pipelinq register is installed and unchanged
- **WHEN** pipelinq runs `importFromApp()` again (setup wizard provision step, example data load)
- **THEN** no `register_updated` activity is written and no admin gets a "Register was updated" popup
- @e2e exclude {the decision sits in RegisterMapper::update and is covered by RegisterMapperUpdateEventTest, which drives the real update() with real Register and RegisterUpdatedEvent classes}

#### Scenario: an administrator renames a register

- **GIVEN** a register titled "CRM"
- **WHEN** an administrator saves it with the title "CRM register"
- **THEN** exactly one `RegisterUpdatedEvent` is dispatched and the `register_updated` activity is written
- @e2e exclude {covered by RegisterMapperUpdateEventTest; the activity write itself is covered by ActivityEventListenerTest}

### Requirement: An object activity names the schema and the object

The system SHALL publish object activities with the object's schema title when
the schema can be resolved, and the activity provider SHALL render them as
`{schema} {title} created`, `{schema} {title} updated` and
`{schema} {title} deleted`. Without a schema title the provider SHALL keep the
existing `Object updated: {title}` form.

#### Scenario: a user edits a client in pipelinq

- **GIVEN** a client object "Gemeente Demo" in the schema titled "Client"
- **WHEN** the user saves a change to it
- **THEN** the activity stream shows "Client Gemeente Demo updated" and no register activity
- @e2e exclude {rendering is covered by ProviderSubjectHandlerTest and the parameters by ActivityServiceTest; the activity stream UI is Nextcloud's own}

### Requirement: A canonical object notification does not print a register id

The system SHALL render the canonical object notification subject without the
register clause when the notification carries no register name, so it reads
`Object "Gemeente Demo" updated` and never `updated in register "20"`.

#### Scenario: a notification without a register name

- **GIVEN** an `object_updated` notification with `objectTitle` "Gemeente Demo", `registerId` 20, no `registerName` and no `_text`
- **WHEN** the notifier prepares it
- **THEN** the subject is `Object "Gemeente Demo" updated`
- @e2e exclude {covered by AnnotationNotifierTest; no browser path renders a notification without `_text`}
