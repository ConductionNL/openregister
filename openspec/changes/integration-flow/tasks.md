# Tasks: Integration — Flow

## Backend

- [x] `FlowLink` entity + mapper + migration (schema/object → flow rule id)
- [x] `FlowService` — read NC Flow rules via workflowengine Manager, read fire events, CRUD on links
- [x] `FlowController` sub-resource endpoints
- [x] `FlowProvider` — id='flow', label='Automation', icon='RobotOutline', group='workflow', requiredApp='workflowengine', storage='link-table'
- [x] DI-tag, routes, unit tests

## Frontend — Tab

- [x] `CnFlowTab.vue` — two sections (NC Flow + OR workflow rules), recent-events panel, link/unlink, "Open in NC settings" link-out (in @conduction/nextcloud-vue)
- [x] Barrel + tests (in @conduction/nextcloud-vue)

## Frontend — Widget

- [x] `CnFlowCard.vue`:
  - `user-dashboard`: recent fires affecting user's objects
  - `app-dashboard`: scoped
  - `detail-page`: linked rules + recent events panel
  - `single-entity`: rule name + last-fire chip
  (in @conduction/nextcloud-vue)
- [x] Barrel + surface tests (in @conduction/nextcloud-vue)

## Registration

- [x] `src/integrations/builtin/flow.js` — register with `referenceType: 'flow'`

## Quality

- [x] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: link a flow rule to a schema, verify tab display; recent-events panel populates after fire
- [ ] Hide test; reference-property test
