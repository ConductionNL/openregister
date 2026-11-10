# Design: access-owner-and-condition-scopes

Read at openregister development 555af7212, and buildiq development 974af86
(`src/composables/useOrAccessCapabilities.js`,
`src/components/schema-editor/AccessEditor.vue`).

## Context

- OpenRegister's native rule shape is a list per action of group ids or
  conditional rules `{ group | user, match }`
  (`lib/Db/MagicMapper/MagicRbacHandler.php:1371-1401`), with `$userId`,
  `$organisation` and `$now` resolved in `match` (`:13-21`).
- The owner of a row is always admitted: "The owner-admits conditions, which
  apply whatever the scope says" (`MagicRbacHandler.php:570-580`,
  `t._owner = :userId`).
- The authorization block is read in two places: `MagicRbacHandler` for list
  queries and `PermissionHandler` for single objects
  (`lib/Service/Object/PermissionHandler.php:595`, `:637`, `:2862`).
- `@creator` and `authorization.conditions` appear nowhere in `lib/`.
- Only `UrnCapability` and `IntegrationsCapability` are registered
  (`lib/AppInfo/Application.php:974-979`).
- Buildiq writes `authorization.<op>: ["@creator"]` for own records, and
  `authorization.conditions.<op>: { field, operator: "equals", value }` for a
  condition (`AccessEditor.vue:163-215`). It feature-detects through
  `getCapabilities().openregister.authorization.scopes`.

## D-1: normalise on read, never rewrite the stored block

`AuthorizationBlock::normalise(array $authorization): array` returns the
native shape:

- a `@creator` entry is dropped from the list; if it was the only entry, the
  list becomes the explicit "no group" list, so only the owner rule admits;
- each `conditions.<op>` entry becomes a conditional rule
  `{ group: "authenticated", match: { <field>: <value> } }` appended to that
  action's list, with `@user.uid` mapped to `$userId`;
- the `conditions` key is removed from the result.

Both `MagicRbacHandler` and `PermissionHandler` call it before reading the
block. The stored block is not changed, so buildiq reads back its own shape.

## D-2: an empty list after `@creator` must mean "owner only"

The open change `an-empty-rule-list-means-one-thing` settles what an empty
list means. This change depends on its answer: an action whose only entry was
`@creator` must admit the owner and nobody else. If that change lands with
"empty means open", `normalise()` writes a sentinel deny rule instead, and the
test in task 1.1 proves the outcome either way.

## D-3: the capability states what is enforced

`AuthorizationCapability::getCapabilities()` returns
`['openregister' => ['authorization' => ['scopes' => ['group', 'creator', 'condition']]]]`.
It is registered only in the same release as D-1, so the capability never
promises a kind the engine ignores.

## Risks

- A condition on a field that is not a column of the magic table would fail
  in SQL. `normalise()` keeps unknown fields, and `buildMatchConditionsSql()`
  already refuses a field the schema does not declare; the test covers it.
