# example-sets Delta: example-set-flow-placeholder

## ADDED Requirements

### Requirement: A change may quote a flow of its fragment

A fragment's change, or a row of an engine part, MAY contain `{{flow:<key>.uuid}}`, where `<key>` is a flow the same
fragment declares. The installer SHALL write the fragment's flows after its objects and before its changes, and fill the
placeholder with the written flow's uuid. The check SHALL accept the placeholder for a declared flow before it is
written, and SHALL refuse the set when the key names no flow of the fragment.

#### Scenario: The flows a case type allows

- **GIVEN** a fragment declares the flow `zienswijze-derde` and a change on the Woo case type that sets
  `startableFlows` to `["{{flow:zienswijze-derde.uuid}}"]`
- **WHEN** the set is installed
- **THEN** the case type lists the written flow's uuid

#### Scenario: An unknown flow

- **GIVEN** a change quotes `{{flow:nope.uuid}}` and the fragment declares no flow `nope`
- **THEN** the check refuses the set with "cannot resolve"
