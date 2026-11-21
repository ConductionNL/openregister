## ADDED Requirements

### Requirement: An administrator builds a flow on a canvas, and it runs when a record changes

OpenRegister SHALL offer a flow page where an administrator places a trigger node and step nodes
on a canvas, connects them and saves the flow, without writing JSON. A saved and enabled flow
whose trigger names an object event SHALL run when a record of that register and schema
undergoes that event.

#### Scenario: A flow built on the canvas runs on a new record
- **GIVEN** an administrator on the flow page of a new flow
- **WHEN** they place an object trigger on `object.created` for schema `melding`, connect it to a send-email step, save and enable the flow
- **AND** a user creates a `melding` record
- **THEN** the flow's run list shows one run with that record as its subject

#### Scenario: A disabled flow does not run
- **GIVEN** the same flow, saved but disabled
- **WHEN** a user creates a `melding` record
- **THEN** no run is queued for the flow
