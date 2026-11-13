# Tasks: flow-powerful-steps-need-a-right

## 1. Declaring rights

- [ ] 1.1 `IFlowNodeRequiresRight`; implement it on `SendEmailNode`, `SendNotificationNode`, `SendTalkMessageNode` and `ObjectWriteNode` (delete only). Verify: unit test per node for the right it returns, including object-write with and without delete.
- [ ] 1.2 `FlowNodeRightsGuard::rightFor()` with the administrator's map winning over the node's declaration, including lifting with `null`. Verify: `tests/Unit/Service/Flow/FlowNodeRightsGuardTest.php`.

## 2. Enforcing

- [ ] 2.1 `assertMayAuthor()` over the new and the stored document, called from `importBpmn`, `create`, `update`, `publish`, `draft` and `adopt`, answering 403 naming type and right. Verify: `FlowControllerTest` saves a flow with an e-mail step through each of the six paths as a non-administrator without the right (403) and with it (success).
- [ ] 2.2 `requiresRight` in the palette and `locked` for the caller in `nodeCatalog()`. Verify: `FlowNodeRegistryTest` and a controller test for a locked entry.

## 3. Administering and publishing

- [ ] 3.1 `GenericActionAuthService::addMissing()` and a repair step adding declared node rights as `["admin"]` without overwriting. Verify: repair test on an existing matrix with a customised `flow.create`.
- [ ] 3.2 `GET` and `PUT /api/settings/action-rights` for administrators, with the node map and a refusal for unknown actions. Verify: `ActionRightsControllerTest` for 200, 403 for a non-administrator, 400 naming an unknown action.
- [ ] 3.3 `actions` on `GET /api/permissions`. Verify: `PermissionsControllerTest` asserts a node right with its node types.
- [ ] 3.4 `ActionRights.vue` settings section with texts in en and nl. Verify: component test for granting a right to a group.

## 4. Tests and docs

- [ ] 4.1 Add `tests/e2e/ci/flow-node-rights.spec.ts`: as a non-administrator, see the e-mail step locked, fail to save a flow with it (403 naming the right), have an administrator grant `flow.node.send-email` to the user's group on the settings screen, then save successfully. (live pass, decision 139)
- [ ] 4.2 Document node rights, the settings screen and the upgrade effect in `docs/`, with a screenshot of the settings section.

Acceptance:

- No save path accepts a flow containing a step whose right the caller lacks.
- An administrator's existing matrix entries are unchanged after upgrade.
