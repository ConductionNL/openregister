# system-operation-context

## ADDED Requirements

### Requirement: A system write declares itself and the declaration is verified (REQ-SWD-001)

A code-initiated write that must run as the system SHALL declare itself by
running through `SystemOperationContext::assertSystem()`, naming what is
being written.

The elevation SHALL be verified rather than assumed, and verified on both
sides of the operation: the scope SHALL be live when the operation starts
and still live when it returns. An elevation that never applied is the
ordinary failure; one that stopped applying part-way is the dangerous one,
because the write has already happened and a check made only up front would
call it elevated.

When the scope was not in effect while the operation ran, the call SHALL
throw `SystemContextUnavailableException`. It SHALL NOT fall back to
performing the write as the acting principal. A fallback returns the same
value the elevated call would have returned, so the caller cannot tell the
two apart, and the write records the wrong actor with nothing thrown and
nothing logged.

#### Scenario: a declared write runs elevated

- **GIVEN** a write declared through `assertSystem()`
- **WHEN** it runs
- **THEN** the system-operation scope SHALL be active for the whole operation
- **AND** the operation's return value SHALL be handed back unchanged

#### Scenario: a write that did not elevate is refused

- **GIVEN** a declared write whose elevation did not take effect
- **WHEN** it runs
- **THEN** `SystemContextUnavailableException` SHALL be thrown
- **AND** the message SHALL name what was being written

#### Scenario: the operation's own failure is not an elevation failure

- **GIVEN** a declared write whose operation throws
- **WHEN** it runs
- **THEN** the operation's own exception SHALL travel to the caller untouched

### Requirement: The declared scope is bounded and nests (REQ-SWD-002)

The scope SHALL end with the operation, whether it returns or throws, so a
declared write cannot leave the request elevated behind it.

Declared writes SHALL nest: an inner scope closing SHALL NOT end the scope
an outer one opened.

#### Scenario: the scope closes when the write is done

- **GIVEN** a declared write that returns normally
- **WHEN** it has returned
- **THEN** no system-operation scope SHALL be active

#### Scenario: the scope closes when the write throws

- **GIVEN** a declared write whose operation throws
- **WHEN** the exception has left the call
- **THEN** no system-operation scope SHALL be active

#### Scenario: declared writes nest

- **GIVEN** a declared write that performs another declared write
- **WHEN** the inner one has returned
- **THEN** the outer scope SHALL still be active
