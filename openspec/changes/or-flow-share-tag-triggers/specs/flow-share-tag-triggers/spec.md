## ADDED Requirements

### Requirement: Flows fire on native share and tag events (REQ-ST-001)

OpenRegister SHALL fire flow triggers on Nextcloud share events
(`share.created`, `share.deleted`) and system-tag events (`tag.assigned`,
`tag.unassigned`), carrying the event's details as the run payload with each
field read defensively. The catalog SHALL list these triggers.

#### Scenario: A share event fires with its payload

- **GIVEN** a flow wired to `share.created`
- **WHEN** a share is created
- **THEN** a run is queued carrying the share's node, type, recipient and path

#### Scenario: A tag assignment fires with the object and tags

- **GIVEN** a flow wired to `tag.assigned`
- **WHEN** a tag is assigned to an object
- **THEN** a run is queued carrying the object type/ids and the tags

@e2e exclude backend triggers — share covered by NativeFlowTriggerListenerTest;
tag live-verified on 8080 (its OCP event class is absent from the composer test
stubs); both catalog entries verified live
