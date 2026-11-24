## ADDED Requirements

### Requirement: Areas reference only lower areas

A PHP file in one functional area of `sigrid.components.yaml` MUST NOT reference a file of an area with a higher
rank in the order of design.md (hydra ADR-119 Decision 8). It MAY reference a lower area only through that area's contracts in
`lib/Kernel/Contracts/<Area>/` or through Kernel types. `tools/sig/modularity_check.py --check` (the Modularity workflow and
`composer check:modularity`) MUST fail on a reference that is not in its recorded list, and the list MUST only
shrink.

#### Scenario: an upward reference fails the test
- **GIVEN** a file in Core
- **WHEN** it names a class of Processes
- **THEN** the modularity check fails and names both files

#### Scenario: a call through a lower contract passes
- **GIVEN** a file in Processes
- **WHEN** it names a contract in `lib/Kernel/Contracts/Core/`
- **THEN** the test passes

### Requirement: A contract is called and implemented by the right ranks

A contract in `lib/Kernel/Contracts/<Area>/` MUST count as part of its owning area in `sigrid.components.yaml`.
It MUST then be implemented only by that area or a higher one, and called only by areas of a higher rank than its
owner, because any other reference runs against the order and the modularity check fails. A cycle between two
areas therefore cannot pass through the Kernel.

#### Scenario: a hidden cycle through the Kernel fails
- **GIVEN** Processes calls a contract owned by Integrations
- **WHEN** Integrations calls a contract owned by Processes
- **THEN** the modularity check fails on the second call, because Integrations ranks below Processes

### Requirement: The modularity numbers can only go down

`tools/sig/modularity_check.py --check` MUST compare a measurement of the functional view with
`tools/sig/modularity-baseline.json` per file. It MUST fail on a file that becomes exposed to another area, a new
file in Kernel, a new reference against the order, or a violation degree more than 0.002 above the baseline. It
MUST NOT fail when a change only adds lines to a file, so two changes in flight do not collide. An entry that is
gone MUST be reported, and the change that removes it SHOULD lower the baseline.

#### Scenario: a feature adds a cross-area call
- **GIVEN** a concrete Core class that no other area references
- **WHEN** a change makes another area reference it
- **THEN** the check fails and names the newly exposed file

#### Scenario: a refactor lowers a number
- **GIVEN** a change that removes the last outside reference to a concrete class
- **WHEN** the check runs
- **THEN** it passes, reports the entry as gone, and `--write-baseline` records the lower numbers

### Requirement: Two readings agree

deptrac MUST check the same order with `tools/sig/deptrac/areas.yaml` and a baseline that only shrinks. A
violation that one reading reports and the other does not MUST be explained in the analysis page or fixed.

#### Scenario: deptrac sees a reference through a parent class
- **GIVEN** a class whose parent references a higher area
- **WHEN** deptrac reports it and the scorer's reader does not
- **THEN** the parent class is fixed, which clears both readings
