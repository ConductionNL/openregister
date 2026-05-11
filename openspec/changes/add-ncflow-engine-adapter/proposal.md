# Add NC Flow Engine Adapter

## Why

OR's `workflow-engine-abstraction` supports two engines today: n8n and Windmill. Both are
ExApps — they must be separately installed, configured with external credentials, and are
absent from a default Nextcloud installation. This leaves no workflow engine available for
installations that have not provisioned an ExApp, which is the common case for smaller
deployments and fresh installs.

Nextcloud Flow (`workflowengine`) ships in NC core and is available on every Nextcloud
instance without any additional installation step. It is a rule-driven event-action engine
with a well-defined PHP API (`OCP\WorkflowEngine\IManager`, `IRuleMatcher`, `IOperation`,
`ICheck`). Adding an `NCFlowAdapter` gives OR a zero-install fallback engine for
event-driven workflows — simple rules that fire on object create/update/delete events (for
example: "when a procest case is created, send a notification to the manager group").

This change also closes a design gap: OR's own documentation encourages using what
Nextcloud already ships. NC Flow is the canonical in-NC automation primitive; today OR
does not speak its language at all.

ExApps (n8n, Windmill) remain the right choice for complex multi-step DAGs and scheduled
workflows. NC Flow is the right choice for simple event-driven rules that do not require
an external process.

## What

- Implement `NCFlowAdapter` against `WorkflowEngineInterface` (the existing abstraction)
- Translate `WorkflowEngineInterface` semantics into NC Flow's rule-based model (see
  design.md for the full interface mapping)
- Conditional registration: the adapter registers with `WorkflowEngineRegistry` only when
  `\OCP\WorkflowEngine\IManager` is resolvable from the DI container
- Synthesized webhook URL: OR exposes an endpoint that dispatches a
  `WorkflowTriggerEvent`; NC Flow rules match on that event class, making the webhook
  contract honourable
- Ship 3 example workflow-definition fixtures (notification rule, file-tagging rule,
  custom-event rule) for tests and seed data

## Capabilities

### New Capabilities

- `workflow-engine-ncflow-adapter`: `NCFlowAdapter` implementing `WorkflowEngineInterface`
  against Nextcloud's built-in `workflowengine`. Provides a zero-install workflow engine
  available on every NC instance; registers conditionally (only when NC Flow IManager is
  present); translates OR's deploy/execute/list/health semantics to NC Flow rule CRUD and
  event dispatch; synthesizes webhook URLs via OR's own API layer. Ships 3 example
  workflow-definition payloads as fixtures.

## Affected Repos

openregister only.

## References

- Existing abstraction:
  `openspec/changes/archive/2026-03-06-workflow-engine-abstraction/`
- NC Flow integration visibility change (parallel, out of scope):
  `openspec/changes/integration-flow/`
- ADR-022 (RBAC/auth model)
- ADR-019 (external integration patterns)
- ADR-031 (optional integration registration)

## Out of Scope

- Visual rule editor for NC Flow (Nextcloud's own Settings UI ships this)
- Complex multi-step DAG support (use n8n or Windmill for these)
- Scheduled (cron) workflows — NC Flow does not support cron natively; OR's background
  job scheduler owns this concern
- Parity with n8n's webhook management API (n8n manages webhook URLs in its own DB;
  NC Flow webhooks are synthesized by OR)
- The `integration-flow` visibility tab (parallel change, separate concern)
