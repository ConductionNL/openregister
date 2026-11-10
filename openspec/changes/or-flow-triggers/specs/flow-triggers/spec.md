## ADDED Requirements

### Requirement: Flows and subjects are resolved through contributed resolvers (REQ-FT-001)

The engine SHALL NOT hard-code where flows are stored. An app that owns flows
SHALL contribute an `IFlowResolver` through `RegisterFlowResolversEvent`. The
registry SHALL ask each resolver in turn and take the first non-null answer.

A resolver that throws SHALL NOT stop the others from being asked.

#### Scenario: A flow resolves from its owning resolver

- **GIVEN** two resolvers, each owning a different flow
- **WHEN** a flow id is resolved
- **THEN** the owning resolver's document is returned, and an unknown id is null

#### Scenario: A throwing resolver does not block the rest

- **GIVEN** a resolver that throws and one that answers
- **WHEN** flows are listed for an event
- **THEN** the answering resolver's flows are still returned

### Requirement: The worker executes a queued run (REQ-FT-002)

`FlowRunWorker` SHALL resolve a run's flow and subject and execute it. A flow no
resolver owns SHALL fail the run with a clear reason, not loop; a named subject
that no longer exists SHALL fail it likewise; a run with no subject SHALL still
receive a marking carrier so it can execute.

### Requirement: An event queues a run for every wired flow (REQ-FT-003)

`FlowTriggerService::fire()` SHALL ask every resolver which flows are wired to
the event on the given register/schema, and queue one run each. It SHALL NOT
execute them — a trigger fires inside the dispatch of the action that caused it.

It SHALL NOT throw into the caller: a failure to queue must not break the user
action that fired the event.

Flow ids SHALL be de-duplicated across resolvers.

#### Scenario: Firing queues one run per wired flow

- **GIVEN** two flows wired to `object.created` on a register/schema
- **WHEN** the event fires
- **THEN** two runs are queued, none executed inline

#### Scenario: A queue failure is swallowed

- **GIVEN** queueing throws
- **WHEN** the event fires
- **THEN** no exception escapes and zero runs are reported

### Requirement: Object lifecycle is a native trigger (REQ-FT-004)

Object create, update and delete SHALL fire `object.created`, `object.updated`
and `object.deleted` triggers carrying the object's uuid, register and schema,
attributed to the acting user.

@e2e exclude trigger wiring is backend-only — covered by PHPUnit and a live
queue-to-execute check
