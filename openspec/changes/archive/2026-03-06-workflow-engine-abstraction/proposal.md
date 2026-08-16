# Proposal: workflow-engine-abstraction

## Summary
Engine-agnostic interface for OpenRegister to interact with n8n, Windmill, and future workflow engines. Defines a PHP `WorkflowEngineInterface` with per-engine adapters so that hooks, imports, and automation can target any supported engine without coupling to a specific one.

## Motivation
OpenRegister needs to trigger external workflow engines for validation, enrichment, notifications, and automation. Currently n8n runs as a Nextcloud ExApp (FastAPI proxy to n8n at :5678) and Windmill exists as a separate ExApp. Rather than coupling to either engine, OpenRegister should define a shared interface with per-engine adapters.

Multiple engines can be active simultaneously. Each hook on a schema specifies which engine it uses, so a single schema can have hooks targeting different engines (e.g., hook 1 uses n8n for validation, hook 2 uses Windmill for enrichment). This is the foundation layer that Schema Hooks and Workflow-in-Import specs build upon.

## Affected Projects
- [x] Project: `openregister` -- Interface, adapters, registry service, API endpoints, engine entity
- [x] Project: `n8n-nextcloud` -- Adapter implementation target; may need ExApp metadata for auto-discovery
- [ ] Project: `windmill` (future ExApp) -- Adapter implementation target

## Scope

### In Scope
- `WorkflowEngineInterface` PHP interface with methods for deploy, execute, activate, deactivate, delete, list, health check
- `WorkflowResult` value object for structured execution responses (approved/rejected/modified/error)
- `WorkflowEngine` entity stored in OpenRegister configuration (engine type, base URL, auth config, timeout)
- `N8nAdapter` implementing the interface against n8n's REST API (including ExApp proxy routing)
- `WindmillAdapter` implementing the interface against Windmill's REST API
- `WorkflowEngineRegistry` service for managing registered engines and resolving adapters by engine type
- REST API endpoints at `/api/engines/` for engine CRUD and health checks
- ExApp auto-discovery: detect installed workflow engine ExApps and offer them as configurable engines

### Out of Scope
- How workflows are triggered (see Schema Hooks spec)
- Import format for workflow definitions (see Workflow-in-Import spec)
- Workflow UI/editing (use each engine's native UI)
- Workflow template library or marketplace

## Approach
1. Define `WorkflowEngineInterface` and `WorkflowResult` as the abstraction layer
2. Create a `WorkflowEngine` entity/mapper for persisting engine configurations (engine type, base URL, credentials, enabled flag, timeout)
3. Implement `N8nAdapter` that translates the interface to n8n's REST API, routing through the ExApp proxy when n8n runs as a Nextcloud ExApp
4. Implement `WindmillAdapter` that translates the interface to Windmill's REST API
5. Create `WorkflowEngineRegistry` service that manages registered engines, resolves the correct adapter by engine type, and provides auto-discovery of installed ExApps
6. Expose `/api/engines/` REST endpoints for CRUD, health checks, and listing available engine types

## Cross-Project Dependencies
- **n8n-nextcloud**: Must expose its base URL and proxy path for the n8n adapter to route API calls through the ExApp framework
- **Windmill ExApp**: Must be installed and configured for the Windmill adapter to function (optional -- adapter gracefully handles absence)
- **Nextcloud App API**: Used for ExApp auto-discovery (`OCA\AppAPI` classes)

## Rollback Strategy
Remove the interface, adapters, registry service, engine entity/mapper, and API routes. No changes to existing OpenRegister entities or database schema are required. If using a new database table (`openregister_workflow_engines`), drop the migration. Existing functionality is unaffected since this is purely additive.

## Capabilities
Full requirements are defined in the existing spec at `openspec/specs/workflow-engine-abstraction/spec.md`. Key capabilities:
- Engine Registry with multi-engine support
- Engine Configuration Entity (name, type, URL, auth, timeout)
- WorkflowEngineInterface (deploy, execute, activate, deactivate, delete, list, health check)
- WorkflowResult (approved/rejected/modified/error with errors array and metadata)
- n8n Adapter with ExApp proxy routing
- Windmill Adapter with workspace-scoped API calls
- Engine Auto-Discovery from installed ExApps
