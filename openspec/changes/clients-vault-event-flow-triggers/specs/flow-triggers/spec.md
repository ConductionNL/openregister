## ADDED Requirements

### Requirement: Other apps contribute triggers to the event catalog

OpenRegister SHALL dispatch `RegisterFlowTriggersEvent` once per request when the
flow event catalog is first read. A listener SHALL add a trigger with
`addTrigger(id, label, group)`. `getCatalog()`, and so
`GET /api/flow/event-catalog`, SHALL list the contributed triggers after
OpenRegister's own, and `knownTriggerIds()` SHALL include their ids.

A contribution with an empty id, label or group, or with an id or legacy alias
the catalog already holds, SHALL be refused and logged; when two contributions
offer the same id the first SHALL win. A listener that throws SHALL be logged and
SHALL NOT remove the triggers added before it.

#### Scenario: A vault trigger shows in the palette

- **GIVEN** an app that adds `keepiq.share.granted` labelled "A share is granted" in group `Vault`
- **WHEN** the event catalog is read
- **THEN** the entry is listed after OpenRegister's own triggers
- **AND** `keepiq.share.granted` is a known trigger id

#### Scenario: A contribution cannot shadow a built-in trigger

- **GIVEN** an app that adds `object.created` and `created`
- **WHEN** the event catalog is read
- **THEN** both are refused and logged, and `object.created` keeps its own label

#### Scenario: A failing contributor does not empty the catalog

- **GIVEN** one listener that adds a trigger and a second that throws
- **WHEN** the event catalog is read
- **THEN** the first listener's trigger is listed and the failure is logged
