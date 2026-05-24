# Integration: Flow (Automation)

## Problem

NC Flow (workflowengine) triggers actions on events but has no object-level visibility — users don't see "which automations are wired to this object/schema" or "what fired recently." OR has its own workflow engine; the integration bridges NC Flow for cases where system-wide triggers are needed. Today this leaf is **stub** per the 2026-05-24 registry audit — `FlowProvider.php` is a 137-line copy-paste of the MarkerLookupTrait template with no `OCA\WorkflowEngine\…` imports and `getLinkedItems()` returns `[]`. This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like PipelinQ and Procest have no working integration path and reinvent Flow-style automation locally.

## Context

- **Audit bucket**: stub (2026-05-24)
- **Current backend**: 137-line MarkerLookupTrait template, no `OCA\WorkflowEngine\…` imports; `getLinkedItems()` returns `[]`
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget; backend returns `[]` so the tab is empty
- **Target NC class(es)**: `OCA\WorkflowEngine\Manager` + `OCP\WorkflowEngine\IManager`
- **Storage strategy**: `link-table` (flow rules linked to schema or object) + read-time aggregation from NC Flow events
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

`FlowService` + `FlowController` + `FlowProvider` + `CnFlowTab` + `CnFlowCard`. Tab lists flow rules scoped to schema/object plus recent fire events. Integrates with OR's existing workflow engine for cross-visibility. Provider imports `OCA\WorkflowEngine\Manager` and `OCP\WorkflowEngine\IManager` for rule discovery and falls back to `IntegrationHealth::missingApp('workflowengine')` when NC Flow is disabled.

## Scope

**In scope:** Backend service reading NC Flow rules + events, link table for schema/object scoping, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Flow rule authoring (NC Flow admin UI owns); custom flow operation types; replacing OR's workflow engine.

## Acceptance criteria

- [ ] Flow tab appears when workflowengine enabled + schema has `flow` in linkedTypes
- [ ] Tab shows linked flow rules with last-fire timestamp
- [ ] "Recent events" panel shows fires within last N days
- [ ] Widget renders on all 4 surfaces (workflow-focused)
- [ ] Reference-property `referenceType: 'flow'` renders rule chip
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\WorkflowEngine\Manager` + `OCP\WorkflowEngine\IManager` imports for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('workflowengine')` when NC Flow absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` keeps generic `leaf()` shell with notes — bespoke Tab + Widget components are OUT of this change's scope; file follow-up if needed
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
