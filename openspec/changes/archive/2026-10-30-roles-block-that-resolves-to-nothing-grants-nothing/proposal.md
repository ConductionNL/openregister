# Proposal: roles-block-that-resolves-to-nothing-grants-nothing

## Why

Found live on 2026-10-11 on the dossiq review instance. dossiq's `case` schema
declared an `authorization` block holding `roles` only, written so the
notification dispatcher and the transition guard can find who holds a role. The
dossiq register declares no role definitions, so `PermissionHandler::expandRoles()`
logged "register has no role definitions", dropped the `roles` key and returned
`[]`. `hasGroupPermission()` reads `[]` as "no authorization configured", which is
default-open: a user in no group at all could read, change and delete every case.

It is the same mistake `stripMcpScope()` already names and fixed for a block
holding only `mcp` entries: an author who writes rules has opted the schema into
authorization, and reading an unresolvable block as absent turns a restriction
into a grant, silently.

## What changes

- `expandRoles()` answers a block that grants nobody (`read`, `create`,
  `update` and `delete` each `[]`) when the expansion leaves nothing: the
  register declares no role definitions, or every role name is unknown. The
  administrator and owner bypasses still apply.
- A block that keeps any other key after expansion is unchanged, because it is
  already non-empty and so already refuses an unlisted verb.

## Merge order

dossiq first (or together): ConductionNL/dossiq `case-access-by-handling-team`
gives the case schema its own read and update rules. Merged alone, this change
closes dossiq's case schema to everybody but administrators and owners.
Other fleet apps whose schemas carry `roles` only are listed in the PR body.

## Impact

- `lib/Service/Object/PermissionHandler.php`
- `tests/Unit/Service/Object/PermissionHandlerRoleHierarchyTest.php` (the two
  tests that asserted the open reading)
- `tests/Unit/Service/Object/PermissionHandlerHandlingTeamRuleTest.php` (new)
