## ADDED Requirements

### Requirement: OpenRegister ships a flow store (REQ-FS-001)

OpenRegister SHALL ship a `flows` register and a `flow` schema, imported
idempotently by a repair step on install and upgrade, so the default flow store
the resolver reads exists without an admin creating it. The `flow` schema SHALL
carry the fields a flow needs: name, enabled, trigger (with optional
triggerRegister/triggerSchema), nodes and edges.

#### Scenario: The flow store exists after install

- **GIVEN** OpenRegister is installed or upgraded
- **WHEN** the repair steps run
- **THEN** a `flows` register and a `flow` schema exist

#### Scenario: A flow authored in the store runs

- **GIVEN** a flow object in the flow store
- **WHEN** it is run through the flow engine
- **THEN** it executes and returns its result

@e2e exclude covered by tests/e2e/api-direct/flow-engine.spec.ts (creates a flow
in the store via the API and runs it through /api/flow-runs/test); the repair
step itself is live-verified on 8080
