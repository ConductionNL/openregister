# Design: flow-powerful-steps-need-a-right

Read at openregister development c53dd0685c.

## D-1: a node says which right it needs

A new optional interface `OCA\OpenRegister\Service\Flow\IFlowNodeRequiresRight`
beside `IFlowNodeTaxonomy` (`lib/Service/Flow/IFlowNodeTaxonomy.php:70`):

```php
public function requiredRight(array $config): ?string;
public function requiredRightDescription(): string;
```

It takes the step's config because one node can be ordinary in one use and
powerful in another: the object-write node needs `flow.node.object-delete` only
for `operation: delete`. Null means no right beyond the flow rights. A right is
a dot-separated action name starting with `flow.node.`, so it cannot shadow
`flow.create` or an app's other actions.

The built-in nodes that implement it: `SendEmailNode`, `SendNotificationNode`,
`SendTalkMessageNode` (all `lib/Service/Flow/Nodes/`), and `ObjectWriteNode` for
delete. Other apps' nodes opt in in their own repositories.

## D-2: an administrator can mark any node type

App config `flow_node_rights` (a map of node type to right, or to `null` to lift
a node's own declaration) lets an administrator restrict a node another app
ships without waiting for that app. `FlowNodeRightsGuard::rightFor(string $type,
array $config): ?string` reads the administrator's map first and the node's
declaration second. The map is written through the same settings endpoint as
the matrix (D-5).

## D-3: the guard on every author save path

`FlowNodeRightsGuard::assertMayAuthor(IUser $user, array $document, ?array $stored)`
collects the step types and configs of the new document and, when the flow
already exists, of the stored one (read through `FlowNodePreflight`, which
already walks a document's step types, `lib/Service/Flow/FlowNodePreflight.php:339`),
resolves each right with D-2, and checks it with `FlowAccess::may()`
(`lib/Service/Flow/FlowAccess.php:92-94`). The first missing right throws a
`FlowNodeRightException`, answered 403 with the node type, the step's label and
the right, in the same response shape `denyUnless()` uses
(`lib/Controller/FlowController.php:167-186`).

It runs after `denyUnless()` in `importBpmn` (`:656`), `create` (`:772`),
`update` (`:902`), `publish` (`:1158`), `draft` (`:1209`) and `adopt`
(`:1296`). The stored document counts because editing a powerful flow you could
not have built is how the restriction would otherwise be walked round: change a
label, keep the e-mail step, and the flow is now "yours". Administrators pass,
as they do in `FlowAccess`. Configuration imports do not go through
`FlowController` and are not checked (see Out of scope).

## D-4: the palette tells the author before they try

`FlowNodeRegistry::palette()` (`lib/Service/Flow/FlowNodeRegistry.php:223`)
adds `requiresRight` (the right or null) to every entry, and
`FlowController::nodeCatalog()` (`:254-270`) adds `locked: true` for the caller
who lacks it. A locked step stays in the list so a person opening an existing
flow sees what it contains; the canvas (nc-vue) greys it, which is nc-vue's.

## D-5: the matrix becomes reachable, and grows without overwriting

- `GenericActionAuthService` (`lib/AppHost/Service/GenericActionAuthService.php`)
  gains `addMissing(array $actions)`, which adds entries that are absent and
  never touches an existing one. A repair step calls it with every right the
  registered nodes declare, seeded `["admin"]`. `GenericInitializeActions`
  keeps seeding only an empty matrix (`:85-120`); this closes the gap that a
  right added after install never appears.
- A new `ActionRightsController` answers `GET /api/settings/action-rights`
  (every action, its groups, its description and, for node rights, the node
  types that need it) and `PUT` on the same path (groups per action, and the
  administrator's node map of D-2), administrator only, with
  `#[AuthorizedAdminSetting]` semantics. An unknown action on `PUT` is refused
  naming it.
- A new `src/views/settings/sections/ActionRights.vue` lists the rights by
  area, with a group picker per right (`NcSelect` with `inputLabel`).

## D-6: the catalogue publishes them

`PermissionsController::index()` (`lib/Controller/PermissionsController.php:110-116`)
adds `actions`: for each action in the matrix, `{action, app, description,
nodeTypes}`. Object verbs stay under `permissions`; an action is a different
kind of grant (to a person, not on an object), and mixing them would let an
action name reach an authorization block, which `PermissionCatalogue::assertGrantable()`
would then refuse.

## Declarative-vs-imperative decision

Imperative, on the flow save path. The restriction is an authorization rule on
an author's act (ADR-023), not business logic on a schema, and ADR-031 does not
cover who may author a flow.

## Risks

- Security (hydra ADR-005): the palette is advice; the guard on every save path
  is the control. A test saves a flow with an e-mail step through each of the
  six endpoints as a user without the right and expects 403 each time.
- Upgrade: seeding the built-in node rights to administrators narrows what
  non-administrators could do yesterday. This is the one place the change
  deliberately differs from the `$why-flows-are-open-by-default` reasoning in
  `lib/actions.seed.json`, and the release notes name the four rights and the
  screen to grant them.
- Stored flows: a flow saved before the upgrade by a non-administrator keeps
  running; only a later edit is refused. The settings screen can show which
  flows contain which restricted step so an administrator can review them; that
  list is read-only and bounded to 200 flows per page.
