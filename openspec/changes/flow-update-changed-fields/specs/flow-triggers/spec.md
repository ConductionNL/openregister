# flow-triggers: delta for flow-update-changed-fields

## ADDED Requirements

### Requirement: An update names the fields it changed

The run context of an `object.updated` trigger MUST carry `changed`, the sorted names of the top-level fields whose value differs between the object before and after the update, when the event carries the object before the update. Metadata fields (starting with `@`) MUST NOT be listed. Without the earlier object, the context MUST NOT carry `changed`.

#### Scenario: a resident's address changes

- GIVEN a client with address "Parkstraat 24"
- WHEN the client is saved with address "Lindelaan 7" and the same name
- THEN the run context's `changed` is `["address"]`

#### Scenario: an update without the earlier object

- WHEN an `object.updated` event fires without the old object
- THEN the run context is empty
