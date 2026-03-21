# Workflow Engine Abstraction

## Problem
Provides an engine-agnostic interface for OpenRegister to interact with workflow engines (n8n, Windmill, and future engines), enabling the system to deploy, execute, monitor, and manage workflows without coupling to any specific engine's API. This is the foundation layer that other specs (Schema Hooks, Workflow-in-Import, Workflow Integration) build upon: every hook execution, import-time workflow deployment, and event-driven automation flows through the `WorkflowEngineInterface` and `WorkflowEngineRegistry` defined here. By abstracting engine specifics behind adapters, OpenRegister can support multiple simultaneous engines, allow engine migration without data loss, and extend to new engines via a single interface implementation.

## Proposed Solution
Implement Workflow Engine Abstraction following the detailed specification. Key requirements include:
- Requirement: Engine Interface Definition
- Requirement: n8n Adapter Implementation
- Requirement: Windmill Adapter Implementation
- Requirement: Engine Registration and Discovery
- Requirement: Workflow Execution API (Sync and Async)

## Scope
This change covers all requirements defined in the workflow-engine-abstraction specification.

## Success Criteria
- Interface defines complete workflow lifecycle methods
- Deploy a workflow returns engine-specific ID
- Update an existing workflow preserves engine ID
- Get workflow retrieves full definition from engine
- Interface supports type-safe return values
