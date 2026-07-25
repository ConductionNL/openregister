## ADDED Requirements

### Requirement: Registered leaves are discoverable without loading their JS

OpenRegister SHALL expose the registered leaf descriptors through its OCS
capabilities surface so that OpenRegister, admin UI, and manifest apps can
discover which leaves exist without loading any leaf app's JavaScript bundle.

The discovery surface MUST report each leaf's id, label, required app, targeted
surfaces, kinds, and current usability, derived from the descriptor and the
required-app installed state.

#### Scenario: A manifest app discovers an installed leaf

- **GIVEN** a sibling app that has registered a leaf
- **WHEN** a manifest app reads the OpenRegister capabilities surface
- **THEN** the leaf's id, surfaces, kinds, and usability are reported without that app's JS being loaded

#### Scenario: A leaf whose required app is disabled reports unusable

- **GIVEN** a registered leaf whose required app is installed but disabled
- **WHEN** the capabilities surface is read
- **THEN** the leaf is reported as present but not currently usable

### Requirement: A render-surface leaf keeps tab and widget parity across layers

A leaf declaring the render-surface kind MUST have a JavaScript registration
under the same id that supplies both a tab component and a widget component, so
the ADR-019 tab-and-widget parity contract holds across the server and client
layers.

The JavaScript registration SHALL reject a render-surface registration that is
missing either the tab or the widget, and the parity check SHALL correlate the
server descriptor id with the JavaScript registration id.

#### Scenario: A render leaf supplies both tab and widget

- **GIVEN** a descriptor declaring render-surface and a JS registration under the same id
- **WHEN** the JS registration is made
- **THEN** it is accepted only when it supplies both a tab and a widget component

#### Scenario: A data-only leaf needs no bespoke render pair

- **GIVEN** a descriptor whose only kind is data-provider
- **WHEN** the parity check runs
- **THEN** no bespoke tab-and-widget pair is required and the leaf renders through a generic list widget

### Requirement: Duplicate leaf ids follow first-wins

A duplicate leaf id SHALL follow the ADR-013 first-wins policy: the first
registration is kept and any later registration under the same id is ignored with
a logged warning, on both the server and the JavaScript layers.

Leaf ids SHOULD be namespaced by convention, such as an app-prefixed id, so that
boot and dispatch order does not cause an accidental collision between unrelated
apps.

#### Scenario: The second registration of an id is ignored

- **GIVEN** two apps registering a leaf under the same id
- **WHEN** the catalogue is built
- **THEN** the first registration is kept and the second is ignored with a logged warning

#### Scenario: Namespaced ids do not collide

- **GIVEN** two apps registering leaves under app-prefixed ids
- **WHEN** the catalogue is built
- **THEN** both leaves are present because their ids differ

@e2e exclude discovery and parity are backend-and-build-gate concerns — covered by PHPUnit and the parity gate
