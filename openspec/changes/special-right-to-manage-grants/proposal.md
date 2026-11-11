---
kind: code
---

# Proposal: special-right-to-manage-grants

## Summary

A group can hold the right to manage the grants on records it does not own:
list them, add one, take one away, and name the record's owning group. The
right is a special right on the organisation, next to `object_publish`,
`agent_use`, `dashboard_view` and `llm_use`. It is called `grant_manage`.

It exists for a service identity that keeps grants in step with a rule. dossiq's
background service account reconciles who may read a case's private records on
upgrade and after a case type changes. Until now only an administrator, the
record's owner or its owning group could write a grant, so that walk borrowed an
administrator's account (decision 222 ended that, decision 234 chose a special
right as the way).

## What a special right was before this change

Read from the code on `development` at c021ddb06:

- Declared in `Organisation::getDefaultAuthorization()` (`lib/Db/Organisation.php`),
  as four top-level keys of the organisation's `authorization` block, each a
  list of group ids.
- Assigned in the organisation editor's "Special Rights" tab
  (`src/modals/organisation/EditOrganisation.vue`), which sends the whole block
  to `PUT /api/organisations/{uuid}`. `OrganisationController::update()` lets
  any member of the organisation do that (`hasAccessToOrganisation()`).
- Stored in the organisation row's `authorization` JSON column.
- Checked nowhere. `docs/Features/organisations.md` shows a
  `hasSpecialRight()` call that does not exist in `lib/`, and no code path reads
  one of the four keys to decide anything.

So this change adds the first special right that is enforced, and the reader
the other four never had.

## What changes

- `OrganisationSpecialRights` (new) answers whether a caller holds a special
  right on one record. It reads the organisation the record belongs to.
- `ObjectSharingService::listGrants()`, `grant()` and `revoke()` and
  `ObjectOwnershipService::setOwnerGroup()` admit a holder of `grant_manage`
  beside the owner, the owning group and an administrator.
  `ObjectScopeResolver::admitsUnconditionally()` is not changed, because it also
  decides who reads and changes the record.
- An entry may be narrowed to a register and to schemas. This is new: the four
  existing rights are a flat list of groups for the whole organisation. A flat
  group id keeps that meaning.
- A holder admitted by the special right alone may not grant to itself or to a
  group it is in, and may not name one of its own groups as the owning group.
- `PUT /api/organisations/{uuid}` refuses a change to `grant_manage` from
  anybody but an administrator or the organisation's owner.
- `OrganisationSpecialRights::give()` lets code shipped with an app give its
  own service group the right when the app is installed.

## What does not change

- The right opens neither a read nor a write of the record's contents.
- The record's scope, a public link and an e-mail invitation stay with the
  owner and administrators.
- "Assign a record to somebody else" stays administrator only.
- The four existing special rights stay unenforced. Any member can still
  rewrite the rest of the organisation's authorization block, including the
  create, read, update and delete lists that ARE enforced
  (`MultiTenancyTrait::hasRbacPermission()`). That is older than this change
  and is reported in the pull request, not fixed here.

## Not verified

No live instance was used. Core's share manager runs its own checks when a
share is created by an account that does not own the folder; the unit tests
double the share manager, so whether core accepts the service account as the
sharer is a live check for the consuming app.
