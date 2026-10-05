---
kind: code
depends_on: [favourites-and-recent]
---

# Proposal: object-watchers

## Summary

Let a person follow an object they do not own. A watcher is a per-user,
per-object subscription stored outside the object, exposed as `@self.watching`,
a `_watching=true` lens on the object query, and a `watchers` recipient kind
the notification engine resolves. Eight of eight non-Dutch systems in the
ledger have it and dossiq is the only `no`.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 13.18 | Watchers on a case, separate from the assignee | no | S |

Second of the register's five to build first: "the cheapest row with the
widest failure".

## Why

The register's note: "A teamleider who wants to follow a sensitive case
without owning it." The best competitor, verbatim from the `best` column:
"GLPI observers and Zammad My Subscribed Tickets; eight of eight non-Dutch
systems (`_round4/compare/promoted-rows-batch3.md`)".

The register's `why`: "following an object you do not own is a per-user,
per-object subscription the notification engine reads". `favourites-and-recent`
already puts per-user, per-object state beside the object
(`openregister_favourites`), and `x-openregister-notifications` already
resolves recipient blocks (`groups`, `users`, `dynamic`). A watcher is the
same storage shape and one more recipient kind.

## What changes

- `PUT` and `DELETE /api/objects/{register}/{schema}/{id}/watch`, allowed
  for anyone who may read the object, stored in `openregister_watchers`
  outside the object, its audit trail and its versions.
- `@self.watching` on object reads and lists for the current user, and
  `@self.watcherCount` for a user with `update`.
- `GET .../watchers` lists the watchers of an object for a user with
  `update`; a user with `manage` may add or remove another user.
- A `_watching=true` lens on the object query.
- A recipient block `{"watchers": true}` in `x-openregister-notifications`,
  resolved by the dispatcher to the object's watchers, honouring per-user
  preferences and RBAC (a watcher who lost read on the object receives
  nothing and is dropped from the list on the next dispatch).
- Deleting the object deletes its watcher rows.

## Consumers

- dossiq: a Follow action on the case and a Followed lens on My work; the
  case schema's notification rules add `{"watchers": true}` to their
  recipients. Specified in dossiq by the dossiq lane (register row 13.18).
- zaakafhandelapp, decidiq (follow a decision), pipelinq (follow a lead),
  keepiq, humaniq: the same action and lens with no code.

## ADRs

- ADR-031: the recipient kind is declared on the schema, not coded per app.
- ADR-022: one subscription primitive, consumed.
- openregister ADR-010 (permission verbs): watching needs `read`; listing
  watchers needs `update`; editing them needs `manage`.

## Impact

- Extends: `object-interactions` (delta, beside favourites) and
  `notificatie-engine` requirement "Schemas MAY declare notifications via
  `x-openregister-notifications` with a normative channel block format"
  (the recipient block gains one kind).
- Affected code: one table and migration, `lib/Service/Interaction/WatcherService.php`,
  `ObjectsController` (routes, marker, lens), the notification recipient
  resolver, deletion cleanup.
- Backwards compatible: the object payload is unchanged; the marker sits in
  `@self`.
- Size: S.
