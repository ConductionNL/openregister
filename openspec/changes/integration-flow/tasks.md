# Tasks: Integration — Flow

## Backend

- [ ] `FlowLink` entity + mapper + migration (schema/object → flow rule id)
- [ ] `FlowService` — read NC Flow rules via workflowengine Manager, read fire events, CRUD on links
- [ ] `FlowController` sub-resource endpoints
- [x] `FlowProvider` — id='flow', label='Automation', icon='RobotOutline', group='workflow', requiredApp='workflowengine', storage='link-table'
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnFlowTab.vue` — two sections (NC Flow + OR workflow rules), recent-events panel, link/unlink, "Open in NC settings" link-out
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnFlowCard.vue`:
  - `user-dashboard`: recent fires affecting user's objects
  - `app-dashboard`: scoped
  - `detail-page`: linked rules + recent events panel
  - `single-entity`: rule name + last-fire chip
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/flow.js` — register with `referenceType: 'flow'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: link a flow rule to a schema, verify tab display; recent-events panel populates after fire
- [ ] Hide test; reference-property test
