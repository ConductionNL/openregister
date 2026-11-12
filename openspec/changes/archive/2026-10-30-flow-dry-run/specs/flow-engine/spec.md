# flow-engine Delta

## ADDED Requirements

### Requirement: A flow can be tried against an object without effect

`POST /api/flows/{id}/dry-run` SHALL walk the flow's graph through the engine against the named object and answer the
steps reached, in order, each with `nodeId`, `type`, `label`, `status`, `itemsIn`, `itemsOut` and a `preview`, plus the
end `status`. It SHALL perform only steps that declare `IFlowSideEffectFree`; every other step SHALL be described and
not performed. It SHALL write no run, object, audit trail entry or message and SHALL deliver nothing. It SHALL be
refused without a session (401), without the `flow.update` right (403), for a flow the caller cannot see (404) and for
an object the caller cannot read (404).

#### Scenario: Branch and message

- **GIVEN** a flow that switches on `json.amount > 1000` to a send-email step, else to a set-fields step
- **WHEN** it is tried against an object with amount 5000
- **THEN** the steps are the switch and the send-email, the email's preview names its recipients and subject
- **AND** no mail is sent and no run is stored

### Requirement: A node can say what it would do

A node MAY implement `IFlowNodePreview::preview(items, config, context)` and SHALL answer an array with any of
`summary` (one sentence), `changes` (`[{field, value}]`) and `messages` (`[{channel, to, subject}]`), computing it
without side effects. object-write SHALL preview its operation and fields; send-email, send-notification and
send-talk-message SHALL preview their composed recipients and text.

#### Scenario: Object write

- **GIVEN** an object-write step setting `status` to `closed`
- **THEN** its preview has the change `{field: status, value: closed}` and the object is not written
