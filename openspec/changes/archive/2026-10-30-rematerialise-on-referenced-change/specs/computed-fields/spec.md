# computed-fields Delta: rematerialise-on-referenced-change

## ADDED Requirements

### Requirement: A referenced change refreshes the materialised values that read it

When an object is updated, OpenRegister SHALL re-save the objects in the same register that point at it through a
declared `x-openregister-references` entry (mode `relatedObject`), when one of their enabled materialised calculations
reads `@ref.<name>.<field>` for a field whose value changed. It SHALL re-save at most 200 such objects per dependency
per refresh and log the rest with the command that refreshes them, and SHALL NOT fail the save that triggered it.

The save SHALL only decide whether a refresh is needed; the re-saves SHALL run in a background job queued through the
listener deferral service (ADR-078), as the user whose update queued them, so the update returns before any referrer is
re-saved. A refresh queued from a re-save SHALL carry the cascade depth, and the cascade SHALL stop at depth 2. With the
deferral kill switch (`listenerDeferral = inline`) the refresh SHALL run within the save.

#### Scenario: A case type starts listing flows

- **GIVEN** a case whose `hasStartableFlows` materialises `@ref.caseType.startableFlows` and is false
- **WHEN** its case type is saved with a flow in `startableFlows`
- **THEN** a refresh is queued, and once the job has run the case is re-saved and its `hasStartableFlows` is true

#### Scenario: A change no calculation reads

- **GIVEN** the same case
- **WHEN** its case type's title changes and `startableFlows` does not
- **THEN** no refresh is queued and no case is re-saved
