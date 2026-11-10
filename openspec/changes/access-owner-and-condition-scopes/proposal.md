---
kind: code
depends_on: []
---

# Proposal: access-owner-and-condition-scopes

## Summary

A maker in buildiq limits a table so that each user sees only the records they
created, or only the records whose `afdeling` matches a value. Buildiq's
schema designer already writes those rules; OpenRegister does not read them
and does not say it could. This change makes OpenRegister enforce the two
rule kinds buildiq writes and advertise them in the Nextcloud capabilities
document, so the designer offers them.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| buildiq | `acc-row-level` | Limit which records a user can see based on a rule, such as only their own. | partial |

Row `acc-row-level` sits in buildiq's matrix, `built.owner`
ConductionNL/buildiq, state `built` for buildiq's half. Its note, written by the
buildiq lane: "OpenRegister origin/development registers only UrnCapability
and IntegrationsCapability (lib/AppInfo/Application.php:974,979) and nothing
advertises 'authorization.scopes', so src/composables/useOrAccessCapabilities.js:42-50
always falls back to ['group'] and the 'only their own records' and condition
options never show." The sibling pass of 28 Sep 2026 handed the missing half
here.

Four competitors in that matrix rate the row `yes`: NocoBase, Budibase, Mendix
and Power Apps.

Buildiq's merged change `2026-07-11-data-scopes-authoring` (archived on buildiq
`development`) lists the three primitives it needs from OpenRegister under
"Upstream leaf requirements": a `@creator` sentinel in `authorization.<op>`
lists, condition-based scopes in `authorization.conditions.<op>`, and
"`openregister.authorization.scopes: ["group", "creator", "condition"]` in OR's
Nextcloud capabilities document".

## What changes

- `@creator` in an `authorization.<op>` list means "the object's owner". It
  admits no group; the owner is admitted by the owner rule that already
  applies to every object.
- `authorization.conditions.<op>` with `{ field, operator: "equals", value }`
  admits signed-in users for rows whose `field` equals `value`. A value of
  `@user.uid` means the caller's user id.
- Both are read in the one place the authorization block is interpreted, so
  list queries, single reads and writes agree.
- A capability `openregister.authorization.scopes` lists `group`, `creator`
  and `condition`.

## Out of scope

- Operators other than `equals` in conditions. Buildiq's editor writes only
  `equals`.
- Rewriting stored authorization blocks into OpenRegister's native
  `{ group, match }` shape. The stored block stays as buildiq wrote it, so the
  designer reads back what it saved.

## Impact

- New `lib/Service/Authorization/AuthorizationBlock.php` (normalisation).
- `lib/Db/MagicMapper/MagicRbacHandler.php` and
  `lib/Service/Object/PermissionHandler.php` (read the normalised block).
- New `lib/Capabilities/AuthorizationCapability.php`, registered in
  `lib/AppInfo/Application.php` beside `UrnCapability`.
