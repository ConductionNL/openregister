# flow-engine

## ADDED Requirements

### Requirement: An object trigger can start a flow on a state change

The `openregister.trigger-object` node SHALL accept `object.transitioned` as its
`event`, with an optional `transition` filter of `actions`, `from` and `to`
lists. A flow SHALL start for a transition only when every list present in the
filter contains the matching value from the transition. A filter SHALL be
refused on any other event.

#### Scenario: a maker's flow starts when an application is closed

- **GIVEN** a published flow whose object trigger names `event: object.transitioned`, schema `aanvraag` and `transition: { "to": ["closed"] }`
- **WHEN** a case handler moves an `aanvraag` record from `open` to `closed` through `POST /api/objects/{id}/transition`
- **THEN** exactly one run of that flow is queued with the record as its subject
- **AND** the run context holds `action`, `from` `open` and `to` `closed`
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/flow-trigger-transition.spec.ts}

#### Scenario: a transition outside the filter starts nothing

- **GIVEN** the same flow
- **WHEN** the case handler moves the record from `closed` back to `open`
- **THEN** no run of that flow is queued
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/flow-trigger-transition.spec.ts}

#### Scenario: a filter on the wrong event is refused

- **GIVEN** a maker saving an object trigger with `event: object.created` and a `transition` filter
- **WHEN** the flow is saved through `PUT /api/flows/{id}`
- **THEN** the node's config is refused with a message naming `transition` and `object.transitioned`
- @e2e exclude {API contract; covered by the TriggerObjectNode unit test in task 1.1}

### Requirement: An update trigger can watch named fields

The `openregister.trigger-object` node SHALL accept an optional
`changedFields` list with `event: object.updated`. A flow SHALL start for an
update only when at least one named top-level property has a different value
after the save than before it. The run context SHALL carry the changed
property names as `changedFields`.

#### Scenario: an AI field is filled only when the description changes

- **GIVEN** a published flow on `object.updated` for schema `melding` with `changedFields: ["omschrijving"]`, whose last step writes `categorie` back onto the record
- **WHEN** a user edits the `omschrijving` of a melding
- **THEN** one run is queued and its context lists `omschrijving` under `changedFields`
- **AND** the flow's own write of `categorie` queues no second run
- @e2e exclude {specified only; task 3.2 adds the Newman case}

#### Scenario: an update without the old version does not start a watching flow

- **GIVEN** the same flow
- **WHEN** an update event arrives without the previous version of the object
- **THEN** no run is queued, because nothing proves a watched field changed
- @e2e exclude {engine-internal; covered by the listener unit test in task 2.1}
