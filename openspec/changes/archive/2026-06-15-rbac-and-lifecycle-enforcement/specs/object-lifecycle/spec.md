## ADDED Requirements

### Requirement: Declarative per-transition authorization gate

OpenRegister SHALL enforce a declarative `authorization` list on a lifecycle
transition: when the matched transition declares a non-empty `authorization`
list of Nextcloud group ids and/or `{ "role": "<name>" }` entries, the caller
MUST satisfy the list on the `saveObject()` path for the transition to be
applied, otherwise the update SHALL be rejected with the structured error code
`lifecycle-transition-unauthorized` and the object data SHALL NOT be mutated.

Enforcement SHALL be fail-closed: an empty `authorization` list authorizes
nobody; an anonymous or unresolvable caller is denied; the `admin` group is
always authorized; a literal string entry matches a caller's Nextcloud group
membership; a `{ "role": "<name>" }` entry expands to the Nextcloud group ids
assigned to that role on the schema's `authorization.roles` map and matches the
same way. The authorization check SHALL run BEFORE any `requires` guard.

A transition WITHOUT an `authorization` key SHALL behave exactly as before
(additive); the gate SHALL only be evaluated when the key is present.

#### Scenario: Member of an authorized group may perform the transition
- **GIVEN** a transition declaring `"authorization": ["vergunningverleners"]`
- **AND** the caller belongs to the `vergunningverleners` Nextcloud group
- **WHEN** the caller saves the object with the transition's target lifecycle value
- **THEN** the transition is applied and no authorization error is raised

#### Scenario: Non-member is rejected fail-closed
- **GIVEN** a transition declaring `"authorization": ["vergunningverleners"]`
- **AND** the caller does NOT belong to that group and is not `admin`
- **WHEN** the caller attempts the transition via saveObject
- **THEN** the update is rejected with code `lifecycle-transition-unauthorized`
- **AND** the lifecycle field is not changed

#### Scenario: Anonymous caller is denied
- **GIVEN** a transition declaring a non-empty `authorization` list
- **AND** there is no authenticated user
- **WHEN** the transition is attempted
- **THEN** the update is rejected with code `lifecycle-transition-unauthorized`

#### Scenario: Named role expands to assigned groups
- **GIVEN** a transition declaring `"authorization": [{ "role": "handler" }]`
- **AND** the schema's `authorization.roles.handler` lists `["vergunningverleners"]`
- **AND** the caller belongs to `vergunningverleners`
- **WHEN** the transition is attempted
- **THEN** the transition is applied

#### Scenario: A transition without authorization is unaffected
- **GIVEN** a transition with no `authorization` key
- **WHEN** an otherwise-valid transition is attempted by any authenticated caller
- **THEN** no authorization gate is evaluated and the transition proceeds

### Requirement: Lifecycle annotation accepts property alias and string from

The `x-openregister-lifecycle` annotation SHALL accept `property` as an additive
alias for `field` (with `field` taking precedence when both are present), and a
transition's `from` MAY be a single state string in addition to an array of
state strings. These shapes SHALL be accepted by both schema-save validation and
runtime transition enforcement, and SHALL NOT change behavior for schemas already
authored with `field` and array `from`.

#### Scenario: property alias drives enforcement
- **GIVEN** an annotation authored with `"property": "lifecycle"` and no `field`
- **WHEN** an illegal transition is attempted on save
- **THEN** it is rejected with code `lifecycle-invalid-transition`

#### Scenario: string from is honored
- **GIVEN** a transition declaring `"from": "concept"` (a string, not an array)
- **WHEN** an object in state `concept` transitions to that transition's target
- **THEN** the transition is accepted as a valid `from` match
