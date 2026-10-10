# Design: merge-follow-and-favourites

## D-1: one table, the watchers table

Both tables had identical columns (`id`, `user_id`, `object_uuid`, `register`, `schema`, `created`) and the same unique key on (`user_id`, `object_uuid`). The watchers table survives because its semantics (visible to editors, read by the notification dispatcher, pruned on lost read) are the ones the merged feature keeps. The favourite's one extra property, silence, becomes the column `notify`.

`notify` is `BOOLEAN`, `notnull` false (Nextcloud's rule for booleans), default true. A row with a null `notify` reads as true, so nothing written before the column existed loses its notifications.

## D-2: the migration, and the union rule

Two steps, because Nextcloud runs `postSchemaChange` after `changeSchema` of the same step, and the copy must happen before the drop.

1. `Version1Date20261009130000`: `changeSchema` adds `notify`; `postSchemaChange` copies `openregister_favourites` into `openregister_watchers` in pages of 500, writing `notify` false, skipping any (user, object) that already has a watcher row. The skip is the union rule: following and starring the same object means you were already notified, and keep being notified.
2. `Version1Date20261009130100`: drops `openregister_favourites` when present.

Both are idempotent: a rerun finds the column, finds the rows, finds no table.

## D-3: deprecated aliases for one release

dossiq (`favouriteApi.js`, the `_favourite` chip) and nextcloud-vue (`CnFavouriteToggle`, the `favourite` lens) call the old surface today, and they release on their own schedule. Removing the routes in the same release would break whichever of them updates second. So for one release:

| Old | Now answers |
|---|---|
| `_favourite=true` | the `_watching=true` lens, unchanged otherwise (both given: one restriction) |
| `@self.favourite` | the value of `@self.watching` |
| `PUT .../favourite` | a follow with `notify` false when the caller does not follow yet; an existing follow is left as it is |
| `DELETE .../favourite` | unfollow |

The favourite routes answer with `Deprecation: true` and `Link: <.../watch>; rel="successor-version"`. Rejected: keeping the favourite routes for ever as "follow silently". Two verbs for one state is the confusion this change removes.

## D-4: `_watching` in the query

The lens used to load every uuid the caller follows and put them on `_ids`. That list grows with every follow, and with favourites moving in it grows a lot. It is now `_watchingFor` (the caller's uid), which the mapper turns into a correlated `EXISTS` against `openregister_watchers`, the same shape `_favouriteFor` had. Anonymous callers still get the literal id no object carries, so the lens never widens to the whole register.

## D-5: the notify switch lives on the watch verb

`PUT .../watch` is already "make it so", so the switch is a property of that state rather than a new route: `{"notify": false}` follows quietly, `{"notify": true}` follows loudly, no body keeps what is there (or `true` for a new follow). `@self.watchNotify` tells the caller their own setting; editors reading `GET .../watchers` see who follows and since when, not each person's switch (ADR-005: a person's preference is theirs).

## D-6: assignment follows, decided in OpenRegister

Assignment is generic in OpenRegister: a schema may mark one property `x-openregister-role: assignee` (`SemanticRoleHandler::ROLES`). So the rule lives here rather than in every leaf app: `AssigneeFollowListener` handles `ObjectCreatedEvent` and `ObjectUpdatedEvent`, reads the role from the schema, and when the value changed to a uid that `IUserManager` knows and that may read the object (the same fail-closed read check a mention uses), calls `WatcherService::followAssigned()`, which upserts the row with `notify` true. A group id or an external party reference in the property is not a Nextcloud user and is skipped. The previous assignee keeps following: losing the case does not mean losing interest in it, and unfollowing is one click.

`followAssigned()` is public so an app whose assignment is not an object property (a task, a step) can call it.

## D-7: the consistency probe

`orphan-favourites` named `openregister_object_favourites`, which no migration ever created, so the probe failed on every instance. With the favourites table gone the probe moves to the rows that survive: `orphan-follows` on `openregister_watchers`.
