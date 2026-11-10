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

## Decision (Ruben, 19 Oct 2026)

Option 1, additive `{user, mode}` entries, no migration. The picker follows
Nextcloud's own sharing settings through the sharee API, the same as files;
the server checks that the user exists, as it checks groups.

Record templates share the same validator and `accessFor()`, so they accept
user shares too.
