---
kind: code
---

# Proposal: flow-powerful-steps-need-a-right

## Summary

An administrator decides who may build automations that use powerful steps.
A step type can require a named right, either because the app that ships it
says so (sending e-mail, calling an external source, running an agent) or
because the administrator marks it. A person without that right can still build
flows, but cannot save, import, publish or adopt a flow that contains such a
step, and the refusal names the step and the right. The palette shows those
steps as locked for them. The administrator grants the rights to groups on one
settings screen, and the rights appear in the published permission catalogue
beside every other grantable permission.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| planninq | int-automation-guard | Restrict who may build automations that use powerful actions. | partial |

Row `int-automation-guard` in planninq's matrix, owned here because
`built.owner` is ConductionNL/openregister: planninq's Flows pages
(`src/manifest.json:57`, `:223-236` in planninq, per the packet) run on Open
Register's flow endpoints.

Demand rows:

- changelog, https://confluence.atlassian.com/jirasoftware/jira-software-11-3-x-release-notes-1689288832.html

Competitor yes cells, quoted from the packet:

- Jira Software Data Center 11: "11.3 release notes 'Starting from Jira 11.3.3,
  you can use automation restrictions to decide who can create, edit, enable,
  or disable automation rules that use specific components'; 11.3.3 released 5
  March 2026 (read 2026-09-26)". Evidence:
  https://confluence.atlassian.com/jirasoftware/jira-software-11-3-x-release-notes-1689288832.html

## Why

Flow rights are per verb, not per step:

- `FlowController::denyUnless()` checks one named right per endpoint
  (`lib/Controller/FlowController.php:167-186`): `flow.create` on create and
  import (`:657`, `:773`), `flow.update` on update, publish, draft, deprecate
  and adopt (`:903`, `:1159`, `:1210`, `:1245`, `:1297`), `flow.delete` (`:934`),
  `flow.run` (`:961`) and `flow.read` (`:599`, `:1054`, `:1089`, `:1130`). The
  rights are seeded `@authenticated` (`lib/actions.seed.json`).
- The palette is split only by Nextcloud's workflow scope: administrators get
  `SCOPE_ADMIN`, everyone else `SCOPE_USER` (`FlowController::nodeCatalog()`,
  `:254-270`), decided by each node's `isAvailableForScope()`
  (`lib/Service/Flow/IFlowNode.php:118`). The built-in messaging and object nodes
  answer yes to both scopes (for example
  `lib/Service/Flow/Nodes/SendEmailNode.php:113-115`), so any author can put an
  e-mail step in a flow, and an administrator cannot narrow that to a group.
- The rights live in the ADR-023 action matrix (`OpenRegisterActionAuthService`,
  `lib/Service/OpenRegisterActionAuthService.php`), which is seeded only when it
  is empty (`lib/AppHost/Repair/GenericInitializeActions.php:85-120`) and has no
  read or write endpoint in Open Register: the only writer is that repair step.
  So `actions.seed.json`'s own promise, "Admins narrow these under Admin
  Settings", has no screen behind it at this sha, and an action added to the
  seed after install never reaches an existing instance.
- The permission catalogue (`GET /api/permissions`,
  `lib/Controller/PermissionsController.php:110-116`) publishes object verbs
  only, so none of these rights is discoverable there.

## What changes

- A node may declare the right it needs through a new optional interface; an
  administrator may also mark any node type as needing a right. The
  administrator's mark wins.
- Saving a flow (create, BPMN import, update, draft, publish, adopt) that
  contains a step whose right the caller lacks is refused with 403 naming the
  step type and the right. Editing a stored flow that already contains such a
  step is refused the same way, so nobody can change a powerful flow they could
  not have built.
- The node catalogue marks such steps `locked` with the right they need, for the
  caller who lacks it.
- Declared node rights are added to the action matrix when absent, never
  overwriting an administrator's choice, seeded to administrators.
- An administrator reads and edits Open Register's action matrix through
  `GET` and `PUT /api/settings/action-rights` and a new settings section.
- `GET /api/permissions` gains an `actions` list: every action right with its
  app, description and, for node rights, the node types that require it.
- The built-in powerful nodes declare rights: `flow.node.send-email`,
  `flow.node.send-notification`, `flow.node.send-talk-message`, and
  `flow.node.object-delete` for a delete operation of the object-write node.

## Consumers

- planninq (int-automation-guard): its Flows and FlowDetail pages show locked
  steps and the refusal. No planninq code is needed for the check.
- integriq and hermiq: their contributed nodes (`openconnector.source-call`,
  the agent step) can declare a right in their own repositories.

## ADRs

- hydra ADR-023 (action authorization): node rights are actions in the same
  matrix as `flow.create`, granted to groups by an administrator.
- hydra ADR-065: one engine, so one place the check runs.
- hydra ADR-005 (security): the check runs on the backend on every save path,
  fails closed, and does not trust the palette.
- openregister ADR-010 (permission verbs): the catalogue stays the one published
  answer to "what can be granted here".

## Impact

- Extends `flow-engine` (the requirement "Creating, editing and running a flow
  are named rights").
- Affected code: a new `lib/Service/Flow/IFlowNodeRequiresRight.php`, a new
  `lib/Service/Flow/FlowNodeRightsGuard.php`, `FlowController` (the six save
  paths and `nodeCatalog()`), `FlowNodeRegistry::palette()`,
  `FlowNodePreflight` (step types of a document), the built-in nodes named
  above, `GenericActionAuthService` (merge of absent actions), a new
  `ActionRightsController`, `PermissionsController::index()`, a new
  `src/views/settings/sections/ActionRights.vue`.
- Backwards compatibility: the new built-in node rights are seeded to
  administrators, so after upgrade a non-administrator can no longer save a
  flow that sends e-mail until an administrator grants the right. That is the
  point, and the release notes say it. Stored flows keep running; only saving
  them is gated.
- Size: M.

## Out of scope

- Who may run a flow. `flow.run` stays as it is; a stored flow with a powerful
  step runs for anyone who may run it, as in Jira.
- Flows shipped in an app's configuration import. They are the app's, installed
  in a system context, and are not an author's act.
- Declaring rights for nodes in other repositories (integriq, hermiq); each app
  adds the interface to its own nodes.
