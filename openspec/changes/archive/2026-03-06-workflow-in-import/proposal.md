# Proposal: workflow-in-import

## Summary

Extend the OpenRegister JSON import file format to include a `workflows` section, enabling single-file deployment of schemas, business logic (workflow definitions), hook configuration, and seed data. This creates an "infrastructure as code" model for business logic -- a single import file can set up an entire domain with its validation rules, notifications, and automations.

## Motivation

OpenRegister already supports importing schemas and objects via JSON. However, business logic (workflows) must be deployed separately to their engines (n8n, Windmill) and then manually wired to schema hooks. This creates a multi-step deployment process that is error-prone and hard to reproduce across environments.

By embedding workflow definitions in the import file, teams can:

1. **Deploy complete domains from one file** -- schemas, validation workflows, notification workflows, automations, and seed data all in a single JSON import
2. **Version business logic alongside data models** -- workflow definitions travel with the schemas they operate on
3. **Reproduce environments reliably** -- import the same file into dev, staging, and production for consistent behaviour
4. **Track workflow versions** -- hash-based comparison enables idempotent re-imports and version tracking

## Affected Projects

- [ ] Project: `openregister` -- extend JSON import pipeline to parse, deploy, and track workflows; extend export to include workflows

## Scope

### In Scope

- `workflows` section in the JSON import format with engine-specific workflow definitions
- Workflow deployment to engines via the Workflow Engine Abstraction during import
- `attachTo` configuration to wire workflows as schema hooks during import
- Import processing order: schemas -> workflows -> hooks -> objects
- `DeployedWorkflow` entity for tracking deployed workflows (name, engine, hash, version)
- Hash-based idempotency: skip re-deploy when workflow definition is unchanged
- Version increment when workflow definition changes on re-import
- Workflow-only import (no schemas or objects required)
- Import summary includes workflow deployment results (created, updated, unchanged, failed)
- Export includes workflows section with full engine-specific definitions fetched from the engine

### Out of Scope

- Excel/CSV import of workflow definitions (JSON only -- workflow definitions are inherently structured)
- Workflow editing UI in OpenRegister (use the engine's native UI)
- Workflow execution monitoring (use the engine's native dashboard)

## Approach

Extend the existing `ImportService` in OpenRegister to recognise and process a `workflows` array in the import JSON. Each workflow entry contains the engine identifier, the engine-native workflow definition, and an optional `attachTo` block specifying which schema hook to wire.

The import pipeline processes in strict order: schemas first (so hook targets exist), then workflows (deployed to engines), then hook wiring (connecting deployed workflows to schemas), then objects (which now pass through the active hooks).

A new `DeployedWorkflow` entity tracks each deployed workflow with a SHA-256 hash of its definition. On re-import, the hash is compared -- unchanged workflows are skipped, changed workflows are updated in the engine and their version is incremented.

## Cross-Project Dependencies

- **Workflow Engine Abstraction** (`workflow-engine-abstraction` change) -- provides the `WorkflowEngineInterface` with `deployWorkflow()` and `updateWorkflow()` methods used during import
- **Schema Hooks** (`schema-hooks` change) -- provides the hook configuration format and registration API used by the `attachTo` wiring step

Both dependencies must be implemented before workflow import can function. The import parser itself (JSON parsing, `DeployedWorkflow` entity) can be built in parallel.

## Rollback Strategy

- The `workflows` section is optional -- existing import files without it continue to work unchanged
- If the feature needs to be rolled back, the import pipeline simply ignores the `workflows` key
- Already-deployed workflows remain in their engines and can be managed through the engine's native UI
- `DeployedWorkflow` tracking records are inert metadata and can be cleaned up at leisure

## Open Questions

None -- scope is confirmed.
