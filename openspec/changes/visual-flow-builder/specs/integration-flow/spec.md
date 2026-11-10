# integration-flow

## ADDED Requirements

### Requirement: Nextcloud Flow operations as builder blocks

The system SHALL let an OpenRegister flow invoke a registered Nextcloud Flow
(`workflowengine`) operation as one of its actions. A read endpoint
(`GET /api/flow/nc-operations`) SHALL list the registered operations (id, display
name, icon), and an action of type `nc-flow-operation` SHALL, at execution time,
invoke the selected operation for the flow's object. Operations the current user is
not permitted to run SHALL be omitted or fail closed.

#### Scenario: A flow runs a Nextcloud Flow operation
- **WHEN** a flow contains an `nc-flow-operation` action naming a registered operation and the flow fires
- **THEN** that Nextcloud Flow operation is invoked with the flow's object as its subject.

### Requirement: OpenRegister flows exposed as a Nextcloud Flow operation

Each OpenRegister flow SHALL be invokable from native Nextcloud Flow. The app SHALL
register an `OCP\WorkflowEngine\IOperation` that appears in the Nextcloud Flow admin
UI; when a native Flow rule whose checks match selects this operation, it SHALL run
the referenced OpenRegister flow, mapping the rule's entity to an OpenRegister
object. This makes the two engines composable in both directions.

#### Scenario: A native Flow rule triggers an OpenRegister flow
- **WHEN** a Nextcloud Flow rule with the OpenRegister-flow operation matches an event
- **THEN** the referenced OpenRegister flow executes against the object resolved from the rule's entity.

#### Scenario: Bidirectional composition
- **WHEN** an OpenRegister flow includes an `nc-flow-operation` block **and** a Nextcloud Flow rule targets an OpenRegister flow
- **THEN** both invocations succeed independently, so automations authored in either system can call the other.
