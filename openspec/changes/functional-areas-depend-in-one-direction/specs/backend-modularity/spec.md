## ADDED Requirements

### Requirement: Areas reference only lower areas

A PHP file in one functional area of `sigrid.components.yaml` MUST NOT reference a file of an area with a higher
rank in the order of design.md (hydra ADR-119 Decision 8). It MAY reference a lower area only through that area's contracts in
`lib/Kernel/Contracts/<Area>/` or through Kernel types. `ComponentDependencyRulesTest` MUST fail on a reference
that is not in its recorded list of exceptions, and the list MUST only shrink.

#### Scenario: an upward reference fails the test
- **GIVEN** a file in Core
- **WHEN** it names a class of Processes
- **THEN** `ComponentDependencyRulesTest` fails and names both files and both areas

#### Scenario: a call through a lower contract passes
- **GIVEN** a file in Processes
- **WHEN** it names a contract in `lib/Kernel/Contracts/Core/`
- **THEN** the test passes

### Requirement: A contract is called and implemented by the right ranks

A contract in `lib/Kernel/Contracts/<Area>/` MUST be implemented only by that area or a higher one, and MUST be
called only by areas of a higher rank than its owner. The test MUST fail otherwise, so a cycle between two
areas cannot pass through the Kernel.

#### Scenario: a hidden cycle through the Kernel fails
- **GIVEN** Processes calls a contract owned by Integrations
- **WHEN** Integrations calls a contract owned by Processes
- **THEN** the test fails on the second call, because Integrations ranks below Processes

### Requirement: The modularity numbers can only go down

`tools/sig/rate_component_views.py --check` MUST compare, in the functional view, the exposed lines per area,
the Kernel size and the violation degree with `tools/sig/modularity-baseline.json`. It MUST fail when any of
them is higher. A change that lowers a number MUST update the baseline in the same change.

#### Scenario: a feature adds a cross-area call
- **GIVEN** the baseline records 67,182 exposed lines for Core
- **WHEN** a change makes another area reference a concrete Core class that no other area used
- **THEN** the check fails and names the area, the old and the new number

#### Scenario: a refactor lowers a number
- **GIVEN** a change that removes the last outside reference to a concrete class
- **WHEN** the check runs without an updated baseline
- **THEN** it fails and asks for the baseline to be lowered to the new number

### Requirement: Two readings agree

deptrac MUST check the same order with `tools/sig/deptrac/areas.yaml` and a baseline that only shrinks. A
violation that one reading reports and the other does not MUST be explained in the analysis page or fixed.

#### Scenario: deptrac sees a reference through a parent class
- **GIVEN** a class whose parent references a higher area
- **WHEN** deptrac reports it and the scorer's reader does not
- **THEN** the parent class is fixed, which clears both readings
