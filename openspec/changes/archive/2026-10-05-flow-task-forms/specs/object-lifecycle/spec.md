## ADDED Requirements

### Requirement: A transition MAY declare `inputs[]` bounding the payload it accepts

A schema's `x-openregister-lifecycle.transitions[<action>]` MAY declare an
`inputs` array whose entries name a property of that schema and whether it is
required: `inputs: [{"field": "<propertyName>", "required": true|false}]`.

OpenRegister MUST enforce that declaration as an ALLOWLIST when a transition
is applied with a payload:

- A payload key the transition does not declare MUST be REJECTED. A
  transition that declares no `inputs` therefore accepts NO payload at all —
  which is the existing behaviour of every transition in the fleet and MUST
  remain so, so that opting in is explicit and no schema changes behaviour by
  this requirement being written down.
- A declared `required` input that is absent from the payload, or supplied as
  an empty string, MUST be REJECTED.
- Accepted values MUST be merged into the object write that carries the
  transition, so ordinary save-path schema validation and read-only
  enforcement apply to them exactly as to any other object write. OpenRegister
  MUST NOT validate an accepted value only against the `inputs` declaration:
  the declaration says which fields may be supplied, and the schema says what
  a legal value is.
- The accepted values and the lifecycle field change MUST land in ONE save, so
  a caller cannot observe an object whose values were written while its state
  change was refused, or the reverse.

A rejection MUST carry the offending field names in a machine-readable form
alongside the human message, and MUST distinguish an undeclared key from a
missing required input. A malformed payload is a client error and MUST be
reported as one, distinctly from a transition that is refused from the current
state and from one the caller is not authorized to take.

Declaring `inputs` MUST NOT change which transitions are available, who may
take them, or what any existing declared guard, authorization gate or
`actions[]` entry does.

#### Scenario: A transition with no declared inputs rejects a payload
- **GIVEN** a schema whose `approve` transition declares no `inputs`
- **WHEN** the transition is applied with a payload containing one field
- **THEN** the call MUST be rejected naming that field as undeclared
- **AND** the object's lifecycle field MUST be unchanged

#### Scenario: A declared required input is enforced
- **GIVEN** a `reject` transition declaring `inputs: [{"field": "reason", "required": true}]`
- **WHEN** the transition is applied with an empty payload
- **THEN** the call MUST be rejected naming `reason` as a missing required input
- **AND** the response MUST carry that field name machine-readably

#### Scenario: An accepted value is still validated by the schema
- **GIVEN** a `reject` transition declaring `reason` as an input, where the
  schema constrains `reason` to an enumerated set
- **WHEN** the transition is applied with a `reason` outside that set
- **THEN** the save-path validation MUST refuse the write
- **AND** the object's lifecycle field MUST be unchanged

#### Scenario: An accepted value cannot overwrite a read-only property
- **GIVEN** a transition declaring an input naming a property the schema marks
  read-only
- **WHEN** the transition is applied supplying that property
- **THEN** the read-only enforcement on the save path MUST apply exactly as it
  does to any other object write

### Requirement: The available-actions response MUST publish each action's declared inputs

Every action returned by the available-actions endpoint MUST carry the
declared `inputs` for that transition — the field names and their required
flags — so a client can present the payload the transition expects without
reading the schema.

An action whose transition declares no inputs MUST carry an EMPTY inputs list
rather than omitting the key. Absent and empty MUST NOT be the same value on
this response: empty is the positive statement "this transition accepts no
payload", which is exactly what the allowlist enforces, and a client must be
able to read it as such rather than infer it from silence.

This MUST hold for actions derived from a static transition map and for
actions derived at runtime from a graph block, so a client's handling of the
response does not have to know which mode a schema uses.

The endpoint's existing per-action keys MUST be unchanged, and the existing
read-permission check that gates the response MUST be unchanged: publishing
what a transition accepts MUST NOT be reachable by a caller who may not read
the object.

#### Scenario: A declaring transition publishes its fields
- **GIVEN** a schema whose `reject` transition declares two inputs, one required
- **AND** an object in a state from which `reject` is available
- **WHEN** the available-actions endpoint is called for that object
- **THEN** the `reject` action MUST carry both field names with their required flags

#### Scenario: A non-declaring transition publishes an empty list
- **GIVEN** a transition declaring no `inputs`
- **WHEN** the available-actions endpoint is called
- **THEN** that action MUST carry an empty inputs list
- **AND** the key MUST be present

#### Scenario: A caller without read permission still learns nothing
- **GIVEN** a user without read permission on an object
- **WHEN** they call the available-actions endpoint for it
- **THEN** the call MUST be refused
- **AND** no field name from any transition MUST appear in the response
