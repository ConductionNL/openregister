# example-sets Delta: example-set-gone-declarations-are-let-go

## ADDED Requirements

### Requirement: The record lets go of a held entry the set no longer declares

When a fragment is installed, the record SHALL let go of every change and every engine row (task, view, watcher, party, note, read state, flow and the other parts) of that fragment's app that the fragment no longer declares. The proof and remove SHALL leave what such an entry wrote alone, and the install report SHALL name each entry under "Left as it is". An entry of an app whose fragment is not in the plan SHALL stay in the record.

#### Scenario: A change moved into the object's declaration
- **GIVEN** a held change that set a ticket's `slaDeadline`, and a fragment that no longer declares that change
- **WHEN** the ticket's deadline now holds another value and the set is refreshed
- **THEN** the install reports nothing lost, names the change as left, and remove does not put the old deadline back

#### Scenario: A read state replaced by another one
- **GIVEN** a held read state that the fragment no longer declares, and that is gone from the instance
- **WHEN** the set is installed
- **THEN** the install does not report it missing, and names it as left

#### Scenario: An app outside the plan
- **GIVEN** a held change of an app whose fragment is not in the plan
- **WHEN** the set is installed
- **THEN** the record keeps the change and the report names nothing as left

### Requirement: A field that reads back null is not set

A held change SHALL count a field that reads back as null as not set, the same as a field the object did not have. On `--refresh`, a held change whose field was not set before it wrote it, and reads back as null, SHALL write the field again.

#### Scenario: A field the schema gained after the change wrote it
- **GIVEN** a held change that wrote `contactRef` where the contract had none
- **WHEN** `contactRef` reads back as null and the set is refreshed
- **THEN** `contactRef` is written again, and the change reports refreshed
