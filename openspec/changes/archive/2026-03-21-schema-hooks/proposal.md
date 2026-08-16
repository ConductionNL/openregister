# Schema Hooks

## Problem
Schema hooks enable per-schema configuration of workflow callbacks that fire on object lifecycle events, allowing external systems to validate, enrich, transform, or reject data before or after persistence. Hooks use CloudEvents 1.0 structured content mode for payloads, support synchronous (request-response) and asynchronous (fire-and-forget) delivery modes, and provide configurable failure behavior (reject, allow, flag, queue) so administrators can balance data integrity against availability. The hook system is engine-agnostic through the `WorkflowEngineInterface` abstraction, currently supporting n8n and Windmill adapters, and integrates deeply with Nextcloud's PSR-14 event dispatcher via `StoppableEventInterface` for pre-mutation rejection.

## Proposed Solution
Implement Schema Hooks following the detailed specification. Key requirements include:
- Requirement: Hook Configuration on Schema
- Requirement: Hook Lifecycle Events
- Requirement: CloudEvents Wire Format
- Requirement: Sync Hook Response Format
- Requirement: Hook Execution Order

## Scope
This change covers all requirements defined in the schema-hooks specification.

## Success Criteria
- Schema stores hook configuration
- Valid event values
- Schema with multiple hooks on the same event
- Disabled hook is skipped
- Hook configuration persists across schema updates
