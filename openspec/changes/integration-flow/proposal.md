# Integration: Flow (Automation)

## Problem

NC Flow (workflowengine) triggers actions on events but has no object-level visibility — users don't see "which automations are wired to this object/schema" or "what fired recently." OR has its own workflow engine; the integration bridges NC Flow for cases where system-wide triggers are needed.

## Context

- **Backend:** greenfield — wrap `workflowengine` (NC core)
- **Required NC app:** `workflowengine` (NC core app, always present but may be disabled)
- **Storage:** `link-table` (flow rules linked to schema or object) + read-time aggregation from NC Flow events
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`FlowService` + `FlowController` + `FlowProvider` + `CnFlowTab` + `CnFlowCard`. Tab lists flow rules scoped to schema/object plus recent fire events. Integrates with OR's existing workflow engine for cross-visibility.

## Scope

**In scope:** Backend service reading NC Flow rules + events, link table for schema/object scoping, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Flow rule authoring (NC Flow admin UI owns); custom flow operation types; replacing OR's workflow engine.

## Acceptance criteria

- [ ] Flow tab appears when workflowengine enabled + schema has `flow` in linkedTypes
- [ ] Tab shows linked flow rules with last-fire timestamp
- [ ] "Recent events" panel shows fires within last N days
- [ ] Widget renders on all 4 surfaces (workflow-focused)
- [ ] Reference-property `referenceType: 'flow'` renders rule chip
- [ ] Parity gate passes; nl+en done
