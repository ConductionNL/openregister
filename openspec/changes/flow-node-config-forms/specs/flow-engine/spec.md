## MODIFIED Requirements

### Requirement: A node type declares its own form, and its own run-log actions

A node's behaviour is contributed by an app; so is everything an operator needs
in order to configure it and to understand what it did. The engine SHALL let a
node type declare both, through optional interfaces alongside `IFlowNode`:

- **A config form.** A declarative field list — key, label, type, help, and
  where a value comes from (a literal, or a lookup the providing app resolves).
  A type that declares none keeps the raw-JSON pane, which is the honest
  fallback rather than a typed pane over guessed keys.
- **Run-log actions.** Given one log entry, the links that entry earns: for an
  openconnector call node, the contract or the source it used; for an agent
  node, the session it created. Each is a label, an href and an icon.

The engine SHALL NOT hard-code any app's fields or links. The reason is the one
the catalogue already proves: `RegisterFlowNodesEvent` exists so an app can add
a node without OpenRegister knowing about it, and a form registry that only
OpenRegister could extend would put the form of every contributed node back
into the engine.

A form declaration SHALL be data, not markup. A node that shipped a component
would tie the engine's rendering to that app's build, and a canvas rendered in
one app would be asked to mount a component from another.

Actions SHALL be derived from a log entry rather than stored on it. An href
frozen into a log at write time is a link that rots when the target moves, and
a run log is kept for months.

**Every built-in node type SHALL implement `IFlowNodeConfigForm`.** The
optional interface stays optional for CONTRIBUTED types — an app may ship a
node before its form, and the raw pane covers the gap — but the engine's own
nineteen types set the floor. A built-in node on the raw pane is not a
fallback, it is the engine declining to describe keys it defined itself.

Every declared field SHALL name a key the node actually reads — one declared
through `IFlowNodeConfigKeys` — because a field over an ignored key looks like
it works and changes nothing. A node whose configuration is deliberately empty
(`openregister.trigger-manual`) SHALL declare an EMPTY form, so the editor can
say "this node takes no configuration" instead of offering an empty JSON
object to puzzle over.

A field whose value set is a live catalogue — the event list, registers,
schemas, flows — SHALL use `optionsFrom` with a reference the engine resolves
at render time, never an inline snapshot of today's list.

#### Scenario: A node type with no form declaration still configures

- **GIVEN** a CONTRIBUTED node type that declares no config form
- **WHEN** an operator edits a node of that type
- **THEN** the raw-JSON configuration pane MUST be offered
- @e2e exclude covered by the node editor's component tests

#### Scenario: A contributed node's form comes from its own app

- **GIVEN** an app contributing a node type that declares a form
- **WHEN** the editor renders that node's configuration
- **THEN** the fields MUST come from the declaration, and the engine MUST NOT
  carry any knowledge of that app's keys
- @e2e exclude covered by the registry tests

#### Scenario: A log entry offers the links its node earns

- **GIVEN** a run log entry written by an openconnector call node
- **WHEN** the entry is displayed
- **THEN** the actions that node type declares MUST be offered against it
- **AND** each action's href MUST be resolved at display time, not read from
  the stored entry
- @e2e exclude covered by the run-log component tests

#### Scenario: Every built-in type declares a form

- **GIVEN** the palette built from the engine's own registrations
- **WHEN** each entry whose type id starts with `openregister.` is inspected
- **THEN** every one MUST carry a form declaration
- **AND** the assertion enumerates the registry, not a hand-kept list, so a
  node added later without a form turns this red rather than silently joining
  the raw-pane set
- @e2e exclude engine-internal registry sweep — covered by `FlowNodeRegistryTest`

#### Scenario: A form field maps to a key the node reads

- **GIVEN** any built-in node's form declaration
- **WHEN** its fields are compared against that node's `configKeys()`
- **THEN** every field's `key` MUST appear in `configKeys()`
- @e2e exclude engine-internal — covered by a per-node unit test

#### Scenario: An event select is fed by the live catalogue

- **GIVEN** the form for `openregister.trigger-object`
- **WHEN** its `event` field is rendered
- **THEN** the options MUST come from the event catalogue via `optionsFrom`
- **AND** an event added to `EventCatalogService` MUST appear without the
  node's declaration changing
- @e2e exclude covered by the editor's component tests with a stubbed catalogue

## ADDED Requirements

### Requirement: The form pane and the JSON pane are two views of one config

The editor SHALL keep the raw-JSON pane available for every node, including
nodes with a complete form, and the two panes SHALL round-trip losslessly:

- a value written through the form SHALL appear in the JSON pane exactly as
  the node will read it;
- a key present in the config but not covered by a form field SHALL survive a
  form edit unchanged — a partial form edits what it names and touches nothing
  else;
- a value written through the JSON pane SHALL populate the corresponding form
  field on switching back.

This is what makes a partial form safe to ship and the JSON pane an honest
fallback rather than a divergent second editor. A form that drops unknown keys
on save would make "improve one node's form" a data-destroying change for
every flow already using that node's other keys.

The JSON pane SHALL validate that its content is a JSON object before it is
applied, and refuse otherwise, naming the parse error — a malformed config
saved raw would surface later as a preflight failure attributed to the node,
not to the edit that caused it.

#### Scenario: A key outside the form survives a form edit

- **GIVEN** a node whose config carries `{"page": 3}` while its form declares
  no `page` field
- **WHEN** the operator changes a form field and saves
- **THEN** the stored config MUST still carry `"page": 3` unchanged
- @e2e exclude covered by the node editor's component tests

#### Scenario: The two panes agree

- **GIVEN** an operator who sets a field through the form
- **WHEN** they switch to the JSON pane
- **THEN** it MUST show the value the form wrote
- **AND** editing it there and switching back MUST show the new value in the
  form field
- @e2e exclude covered by the node editor's component tests

#### Scenario: Malformed JSON is refused at the pane, not at preflight

- **GIVEN** the JSON pane containing text that does not parse as an object
- **WHEN** the operator applies it
- **THEN** the pane MUST refuse, naming the parse error
- **AND** the node's stored config MUST be unchanged
- @e2e exclude covered by the node editor's component tests
