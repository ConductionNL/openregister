## ADDED Requirements

### Requirement: Sibling apps register leaves through a typed collect-event

A sibling Nextcloud app SHALL contribute a leaf to OpenRegister by listening for
`RegisterLeafProvidersEvent` and calling its register method, the same idiom used
by `RegisterMcpToolProvidersEvent` for MCP tool providers and by the flow-node
registry.

The event SHALL be dispatched once, lazily, when the leaf catalogue is first
read in a request. There SHALL be no other server-side seam for a sibling app to
contribute a leaf.

#### Scenario: An announced leaf reaches the catalogue

- **GIVEN** an app listening for the event and registering a leaf descriptor
- **WHEN** the leaf catalogue is built
- **THEN** that app's leaf is present in the catalogue

#### Scenario: The built-in leaves still register

- **GIVEN** the five built-in leaves that register in OpenRegister boot
- **WHEN** the leaf catalogue is built
- **THEN** the built-in leaves are present alongside any announced leaves

### Requirement: A leaf descriptor declares capability metadata not components

A contributed leaf descriptor MUST carry a stable kebab-case id, a label, an
icon, an optional required app id, the render surfaces it targets, an optional
reference-type marker, an optional required-permission string, and a non-empty
set of kinds.

The descriptor MUST NOT carry Vue components; render components are supplied on
the JS layer under the same id. The id on the descriptor MUST equal the id used
in the JS registration.

#### Scenario: A descriptor without a kind is rejected

- **GIVEN** a leaf descriptor whose kinds set is empty
- **WHEN** the app registers it on the event
- **THEN** the registration is rejected and the rest of the catalogue is unaffected

#### Scenario: A descriptor carries availability metadata

- **GIVEN** a descriptor declaring a required app that is not installed
- **WHEN** the catalogue reports the leaf
- **THEN** the leaf is reported as present but not currently usable

### Requirement: A leaf declares which kinds it offers

A leaf descriptor MUST declare its kinds as a subset of render-surface,
data-provider, and agent-runner, with at least one present.

A leaf declaring the data-provider kind SHALL contribute an integration provider
instance on the same registration. The agent-runner kind SHALL be reserved by
this change and defined by a separate change; a descriptor MAY declare it but
this change assigns it no behaviour.

#### Scenario: A data-provider leaf contributes a provider

- **GIVEN** a descriptor whose kinds include data-provider
- **WHEN** the app registers it
- **THEN** an integration provider is required on the same registration and is added to the registry

#### Scenario: A render-only leaf contributes no provider

- **GIVEN** a descriptor whose only kind is render-surface
- **WHEN** the app registers it
- **THEN** no integration provider is required and the descriptor is accepted

### Requirement: A broken listener costs only its own leaf

A failure raised while collecting an announced leaf SHALL be logged and
swallowed so that the rest of the catalogue is still built.

An app with a throwing listener SHALL cost its own leaf and nothing else; the
alternative is one bad app removing leaves from the instance.

#### Scenario: A throwing listener does not break discovery

- **GIVEN** a listener that throws during collection
- **WHEN** the leaf catalogue is built
- **THEN** the built-in leaves and every other announced leaf are still present

@e2e exclude registration is backend-only — covered by PHPUnit
