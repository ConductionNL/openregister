## ADDED Requirements

### Requirement: An action posture MUST resolve against the declaring app

When a store declares `installAuth: "action:<name>"`, the engine MUST ask the
declaring app's own ADR-023 authorization matrix whether the signed-in user may
perform `<name>`, and MUST install only when it answers yes.

The engine MUST NOT decide an action posture itself. Assuming `admin` would
turn "the operators who hold this action" into "instance administrators", which
is the capability loss the key exists to prevent.

An administrator MUST NOT bypass the matrix. Otherwise the declaration is
decorative, and an app could never gate an install more tightly than instance
admin.

#### Scenario: The leaf matrix permits

- **WHEN** a store declares `"installAuth": "action:catalog.instantiate"`
- **AND** the declaring app's matrix permits the signed-in user
- **THEN** the install proceeds

#### Scenario: The leaf matrix declines

- **WHEN** the declaring app's matrix declines
- **THEN** the response is 403
- **AND** no component is written

#### Scenario: An administrator is still asked

- **WHEN** the caller is an instance administrator
- **AND** the declaring app's matrix declines
- **THEN** the response is 403

#### Scenario: Anonymous never reaches the matrix

- **WHEN** an anonymous caller posts an install against an action posture
- **THEN** the response is 403
- **AND** the matrix is not consulted

### Requirement: An unresolvable authorizer MUST refuse, never permit

Every failure to decide MUST be a refusal, and MUST be logged at ERROR with the
name that could not be resolved.

The lookup is duck-typed by convention, which is the shape that has repeatedly
produced silent no-ops in this fleet. A no-op here is an install that skipped
its authorization check and then reported success, so the absence of an answer
is treated as "no", never as "no objection".

#### Scenario: The service is not in the container

- **WHEN** `OCA\<Studly>\Service\ActionAuthService` cannot be resolved
- **THEN** the install is refused
- **AND** an ERROR is logged naming the action

#### Scenario: The service has no can() method

- **WHEN** the class resolves but exposes no `can()`
- **THEN** the install is refused

This is the shape a RENAME produces: the class still resolves and the method is
gone, so a check that only tested existence would sail past it.

#### Scenario: The matrix throws

- **WHEN** `can()` throws
- **THEN** the install is refused

ADR-023's own `requireAction()` throws to DENY, so anything propagating out of
the matrix must not be read as consent.
