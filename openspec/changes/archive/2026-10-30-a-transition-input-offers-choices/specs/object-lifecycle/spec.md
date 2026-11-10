# Object lifecycle

## ADDED Requirements

### Requirement: A transition input MAY say how its value is asked for

A declared or provider-published transition input MAY carry a `label` (the
words beside the field) and `options` (a list of `{value, label}` to pick
from). The available-actions response SHALL carry both through on that input,
in every lifecycle mode, so a provider can publish the choices that hold for
the record at hand.

An option without a non-empty text or integer `value` SHALL be dropped. An
option without a `label` SHALL be labelled by its value. An input that declares
neither key SHALL be published as `{field, required}` exactly as before.

Both keys are presentation only. They MUST NOT change which keys the transition
accepts, and the posted value SHALL be validated by whoever writes it.

#### Scenario: A provider publishes the choices for this record
- **GIVEN** a provider that answers an action whose input declares `label` and two `options`
- **WHEN** the available-actions endpoint is called
- **THEN** that input MUST carry the `label` and both options as `{value, label}`

#### Scenario: Malformed options are dropped
- **GIVEN** an input whose `options` include an entry without a value
- **WHEN** the available-actions endpoint is called
- **THEN** that entry MUST NOT be published
- **AND** an option without a label MUST be labelled by its value

#### Scenario: An input declaring neither key is unchanged
- **GIVEN** an input declaring only `field` and `required`
- **WHEN** the available-actions endpoint is called
- **THEN** that input MUST be published as `{field, required}` with no other key
