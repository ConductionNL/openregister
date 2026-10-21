# Tasks: object-actions-declared-and-run

Spec only. Nothing is built until Ruben has answered the open questions in `proposal.md`. Each numbered section is
one mergeable build (design D-11).

## 0. Decisions

- [ ] 0.1 Ruben answers questions 1-6 in `proposal.md`; the design sections each answer names are amended to match.

## 1. Declaration and validation

- [ ] 1.1 `ObjectActionDefinition` (value object) generalising `MacroActionBinding`: keys, kind, body, defaults; `macro: true` read as `flow`.
- [ ] 1.2 `ObjectActionValidator` generalising `MacroActionValidator`: one body, transition exists, inputs are a JSON Schema object, `when` is JSONLogic, surfaces vocabulary, `bulk` with `@object` defaults refused.
- [ ] 1.3 `ObjectActionDefinitions::forSchema()`: register-level inheritance, resolved at read, schema entry replaces whole.
- [ ] 1.4 `elevate` validation: no wildcard, needs an authorization grant, admin saver only.
- [ ] 1.5 Unit tests for each refusal and for the unchanged macro declaration.

## 2. List and run

- [ ] 2.1 `GET /api/objects/{register}/{schema}/{id}/actions`: declared plus `TransitionEngine::availableActions()`, rights, `when`, inputs translated to JSON Schema; one entry per transition named by a declared action.
- [ ] 2.2 `GET /api/object-actions/{register}/{schema}`: bulk-surface actions.
- [ ] 2.3 `ObjectActionRunner` (from `MacroActionResolver`): 404/403/409/422 order, input defaults (`@now`, `@user`, `@object.*`), `transition` / `flow` / `steps` dispatch, `context.inputs`, `writeOnly` handling.
- [ ] 2.4 `steps` lowering in `FlowDefinitionBuilder`, stored per content hash with a system owner, refusal of branching and suspending nodes.
- [ ] 2.5 Async: 202 with run link; sync suspended answers 202 `suspended`.
- [ ] 2.6 Selection over 100 hands off to bulk action `openregister:run-action` (`lib/BulkAction/RunActionAction.php`).
- [ ] 2.7 Elevation: retry-as-system for listed `(register, schema, verb)` only, audited with `elevatedBy`, `triggeredBy`, `runAs`.
- [ ] 2.8 `available-actions` keeps its shape, adds a `Deprecation` header naming the new route.
- [ ] 2.9 Routes in `appinfo/routes.php` with auth attributes; `ObjectActionsControllerTest` extended.

## 3. Nodes

- [ ] 3.1 `openregister.transition` node with kind and category; unit tests for success and refusal.
- [ ] 3.2 `openregister.link-object` node, including `handoff` mode and the `not-available` failure.

## 4. Audit and history

- [ ] 4.1 `action.run` entry with `declaredAction`, `kind`, `run`, `inputsDigest`, `changes`, `outcome`.
- [ ] 4.2 `GET /api/objects/{register}/{schema}/{id}/actions/history` reading `action.run` and `action.macro`.

## 5. Docs and consumers (recorded here, built elsewhere)

- [ ] 5.1 `docs/features/` page for object actions; remove the stale schema-hooks / n8n section of `docs/features/workflow-automation.md`.
- [ ] 5.2 nextcloud-vue change: `useObjectActions`, manifest `type: object-action`, `actionsFrom: "openregister"`, input-schema dialog, disabled-with-reason, `CnMacrosWidget` over the same list.
- [ ] 5.3 dossiq change: `publish`, `withdraw-publication`, case acts, claim/release, task effects to steps (design D-9).
- [ ] 5.4 pipelinq change: `hand-over`, `assign-to-me`, article `publish`, remove `contracts/{id}/transition`.
- [ ] 5.5 learniq change: `publish` (store), `sign` (issue credential), matrix rules as `action:learniq.*`.

## 6. Tests

- [ ] 6.1 `tests/e2e/ci/object-actions.spec.ts`: list, run with inputs through a dialog, precondition reason, history line (needs the nextcloud-vue half).
- [ ] 6.2 Newman collection for the five routes, including the refusal order.
