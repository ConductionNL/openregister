# Action Registry

## Problem
OpenRegister currently ties automated behavior to schemas via the `hooks` JSON property on the Schema entity. While this works for simple use cases, it creates several problems as the system scales:

1. **No reusability**: The same hook configuration (e.g., "validate BSN via n8n workflow X") must be duplicated across every schema that needs it. When the workflow ID changes, every schema must be updated manually.
2. **No discoverability**: There is no central place to see all configured automations across all schemas. Administrators must inspect each schema individually to understand what workflows are active.
3. **No composability**: Hooks cannot be shared, versioned, or composed independently of schemas. There is no way to build a library of reusable automation building blocks.
4. **No standalone triggers**: All hooks are schema-bound. There is no way to define actions that respond to non-object events (register changes, schema changes, source changes) or that operate on a schedule without being attached to a specific schema.
5. **Limited governance**: Without a first-class entity, there is no audit trail for action configuration changes, no RBAC on who can create/modify actions, and no lifecycle management (enable/disable/archive).

## Proposed Solution
Introduce an **Action** entity as a first-class Nextcloud database entity (`oc_openregister_actions`) that decouples automation definitions from schemas. Actions are reusable, discoverable, composable units of automated behavior that can be:

- **Bound to schemas** via a many-to-many relationship (replacing or augmenting inline `hooks`)
- **Bound to any event type** (object, register, schema, source, configuration lifecycle events)
- **Triggered on a schedule** (cron-based) independent of any event
- **Managed via CRUD API** with full audit trail, RBAC, and lifecycle states (draft/active/disabled/archived)
- **Versioned** so that changes to action definitions can be tracked and rolled back
- **Tested** via a dry-run endpoint that simulates execution without side effects

The Action entity wraps the existing `HookExecutor` and `WorkflowEngineRegistry` infrastructure, providing a management layer on top of the already-implemented event-driven architecture and workflow integration.
