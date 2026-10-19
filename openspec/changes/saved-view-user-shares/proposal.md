---
kind: code
---

# Proposal: saved-view-user-shares

## Summary

A view can be shared with groups (`sharedWith: [{group, mode}]`). Ruben,
19 Oct 2026: saved filters are sharable with **users and groups**. This adds a
second entry kind, `{user, mode}`, to the same list.

## Change

- `ViewShareResolver::accessFor()` matches a `{user}` entry against the caller
  id as it matches a `{group}` entry against the caller's groups. Widest mode
  wins, owner still wins over both.
- `ViewShareResolver::validateShares()` accepts an entry with exactly one of
  `user` or `group`, refuses one that names both or neither, refuses a user
  that does not exist (`IUserManager::userExists`) and a duplicate user.
- `ViewMapper::findAllFor()` needs no query change: it already loads every view
  with a non-null `shared_with` and filters in PHP through `accessFor()`.
- Nothing is migrated. Existing `{group, mode}` entries keep their meaning;
  the column stays the same JSON list.

## Decision needed (why this is handed back before building)

The brief asks for a plan first when stored data changes shape. This adds an
entry kind to a stored list; no existing view changes or is lost. Options:

1. Build as above (recommended): additive, no migration.
2. Store users in a separate column. More schema, no gain.

Also to decide: may a user share with a user outside their own groups?
Nextcloud's sharee API already limits the picker by the instance's sharing
settings (`shareapi_only_share_with_group_members`); the server check
proposed here only checks the user exists, matching how groups are checked
today.
