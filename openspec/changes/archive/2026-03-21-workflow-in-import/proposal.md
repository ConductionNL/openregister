# Workflow in Import

## Problem
Extends the OpenRegister JSON configuration import pipeline to deploy workflow definitions to external engines (n8n, Windmill), wire them as schema hooks, track them for versioning and idempotent re-import, and include them in configuration exports -- all from a single import file. This specification bridges the `workflow-engine-abstraction` layer (engine adapters, `WorkflowEngineInterface`) with the `data-import-export` pipeline (`ImportHandler`, `ExportHandler`), enabling portable, self-contained register configurations that include both data structures and automation logic. It also ensures that workflows imported alongside schemas and objects participate in the `schema-hooks` lifecycle so that hooks are active before any objects in the same import are created.
---

## Proposed Solution
Implement Workflow in Import following the detailed specification. Key requirements include:
- Requirement: Extended Import Format
- Requirement: Workflow Import Processing Order
- Requirement: Workflow Deployment via Engine Adapters
- Requirement: Hash-Based Idempotent Versioning
- Requirement: DeployedWorkflow Entity Tracking

## Scope
This change covers all requirements defined in the workflow-in-import specification.

## Success Criteria
- Import file includes workflows section
- Import file without workflows section
- Workflow entry with attachTo
- Workflow entry without attachTo
- Workflow entry with incomplete attachTo
