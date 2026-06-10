# Tasks: Integration — Flow

## Backend

- [~] `FlowLink` entity + mapper + migration (schema/object → flow rule id) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `FlowService` — read NC Flow rules via workflowengine Manager, read fire events, CRUD on links — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `FlowController` sub-resource endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `FlowProvider` — id='flow', label='Automation', icon='RobotOutline', group='workflow', requiredApp='workflowengine', storage='link-table'
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnFlowTab.vue` — two sections (NC Flow + OR workflow rules), recent-events panel, link/unlink, "Open in NC settings" link-out — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnFlowCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: recent fires affecting user's objects
  - `app-dashboard`: scoped
  - `detail-page`: linked rules + recent events panel
  - `single-entity`: rule name + last-fire chip
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/flow.js` — register with `referenceType: 'flow'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: link a flow rule to a schema, verify tab display; recent-events panel populates after fire — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
