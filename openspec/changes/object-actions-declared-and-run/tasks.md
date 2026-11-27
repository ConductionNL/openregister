# Tasks: object-actions-declared-and-run

Ruben accepted all six recommendations in `proposal.md` (21 Oct 2026); the design is written to them. Each numbered
section is one mergeable build (design D-11).

## 0. Decisions

- [x] 0.1 Ruben answers questions 1-6 in `proposal.md`; the design sections each answer names are amended to match.

## 1. Declaration and validation

- [x] 1.1 `ObjectActionDefinition` (value object) generalising `MacroActionBinding`: keys, kind, body, defaults; `macro: true` read as `flow`.
- [x] 1.2 `ObjectActionValidator` generalising `MacroActionValidator`: one body, transition exists, inputs are a JSON Schema object, `when` is JSONLogic, surfaces vocabulary, `bulk` with `@object` defaults refused.
- [x] 1.3 `ObjectActionDefinitions::forSchema()`: register-level inheritance, resolved at read, schema entry replaces whole.
- [x] 1.4 `elevate` validation: no wildcard, needs an authorization grant, admin saver only.
- [x] 1.5 Unit tests for each refusal and for the unchanged macro declaration.

## 2. List and run

- [x] 2.1 `GET /api/objects/{register}/{schema}/{id}/actions`: declared plus `TransitionEngine::availableActions()`, rights, `when`, inputs translated to JSON Schema; one entry per transition named by a declared action.
- [x] 2.2 `GET /api/object-actions/{register}/{schema}`: bulk-surface actions.
- [x] 2.3 `ObjectActionRunner` (from `MacroActionResolver`): 404/403/409/422 order, input defaults (`@now`, `@user`, `@object.*`), `transition` / `flow` / `steps` dispatch, `context.inputs`, `writeOnly` handling.
- [x] 2.4 `steps` lowering in `FlowDefinitionBuilder`, stored per content hash with a system owner, refusal of branching and suspending nodes.
- [x] 2.5 Async: 202 with run link; sync suspended answers 202 `suspended`.
- [x] 2.6 Selection over 100 hands off to bulk action `openregister:run-action` (`lib/BulkAction/RunActionAction.php`).
- [x] 2.7 Elevation: retry-as-system for listed `(register, schema, verb)` only, audited with `elevatedBy`, `triggeredBy`, `runAs`.
- [x] 2.8 `available-actions` keeps its shape, adds a `Deprecation` header naming the new route.
- [x] 2.9 Routes in `appinfo/routes.php` with auth attributes; `ObjectActionsControllerTest` extended.

## 3. Nodes

- [x] 3.1 `openregister.transition` node with kind and category; unit tests for success and refusal.
- [x] 3.2 `openregister.link-object` node, including `handoff` mode and the `not-available` failure.

## 4. Audit and history

- [x] 4.1 `action.run` entry with `declaredAction`, `kind`, `run`, `inputsDigest`, `changes`, `outcome`.
- [x] 4.2 `GET /api/objects/{register}/{schema}/{id}/actions/history` reading `action.run` and `action.macro`.

## 5. Docs and consumers (recorded here, built elsewhere)

- [x] 5.1 `docs/features/` page for object actions; remove the stale schema-hooks / n8n section of `docs/features/workflow-automation.md`. (main has since split the automation docs: `flows.md` is the built-in engine, `workflow-automation.md` the external-tools page that keeps schema hooks and lifecycles, so that section stays; object actions are linked from `flows.md`, `workflow-automation.md` and the features README.)
- [ ] 5.2 nextcloud-vue change: `useObjectActions`, manifest `type: object-action`, `actionsFrom: "openregister"`, input-schema dialog, disabled-with-reason, `CnMacrosWidget` over the same list.
- [ ] 5.3 dossiq change: `publish`, `withdraw-publication`, case acts, claim/release, task effects to steps (design D-9).
- [ ] 5.4 pipelinq change: `hand-over`, `assign-to-me`, article `publish`, remove `contracts/{id}/transition`.
- [ ] 5.5 learniq change: `publish` (store), `sign` (issue credential), matrix rules as `action:learniq.*`.

## 6. Tests

- [ ] 6.1 `tests/e2e/ci/object-actions.spec.ts`: list, run with inputs through a dialog, precondition reason, history line (needs the nextcloud-vue half).
- [x] 6.2 Newman collection for the five routes, including the refusal order.
  - 2026-11-25: `tests/integration/openregister-object-actions.postman_collection.json`, 25 requests and 36 assertions green on a sandbox Nextcloud 34 on MariaDB; with main's code 19 assertions fail. Its first run found two defects, fixed in the same PR: both run routes named their parameter `{action}`, which Nextcloud's router keeps for the controller method, so the key reached the controller as null and every run answered 400 with an empty body (now `{key}`, same URL shape, with `RouteParametersAvoidRouterKeysTest`); and inline steps end at an End node, which stops the run cleanly, while the action path asked the run's status, so every steps action answered 422 "The run ended stopped." (now the run's outcome, as `FlowRun::getOutcome()` reads it). Seen, not changed: an entry is a grantable key only with `name` and `description` (`DeclaredActions::isDeclared()`), which the documented `label` shape lacks, so a documented action named in `authorization` is refused at save; the docs example's `set-fields` step uses `fields` (the node reads `set`) and changes only the item, so it writes nothing; a refused declaration (`DeclaredActionCheck`) answers 500, not 422; the 409 carries its code in `error`, not `code`.
