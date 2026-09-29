# flow-engine

## ADDED Requirements

### Requirement: A condition reads an allowlisted value source through integriq

A condition SHALL accept a `{"source": "<prefix>:<key>"}` node wherever it
takes an operand, in both the JSONLogic and the JSON AST dialect. The node
SHALL be resolved through integriq's `ExpressionValueSourceRegistry` and never
by reading the environment. When the registry refuses the reference, or
integriq is not installed, the condition SHALL NOT hold, and the log line SHALL
name the reference and SHALL NOT contain its value. A calculation (computed
value) SHALL refuse a `source` node, because its result is stored.

#### Scenario: a transition condition compares with an allowlisted variable

- **GIVEN** integriq resolves `env:INTAKE_REGION` to `north`
- **WHEN** a condition `{"eq": [{"prop": "object.region"}, {"source": "env:INTAKE_REGION"}]}` is evaluated for an object whose region is `north`
- **THEN** the condition MUST hold

#### Scenario: a refused reference fails closed

- **GIVEN** integriq refuses `env:NOT_LISTED`
- **WHEN** a condition reading `{"source": "env:NOT_LISTED"}` is evaluated
- **THEN** the condition MUST NOT hold
- **AND** the log MUST name `env:NOT_LISTED` and MUST NOT contain a value

#### Scenario: without integriq nothing resolves

- **GIVEN** integriq is not installed
- **WHEN** a condition reading `{"source": "env:INTAKE_REGION"}` is evaluated
- **THEN** the condition MUST NOT hold

#### Scenario: a calculation cannot read a value source

- **WHEN** a calculation containing `{"source": "env:INTAKE_REGION"}` is evaluated
- **THEN** the evaluation MUST be refused with a message naming the reference
