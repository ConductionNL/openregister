---
kind: code
depends_on: []
---
# Proposal: merge-follow-and-favourites

## Summary

Following and starring are one feature from now on: **Volgen** (follow). OpenRegister keeps one table, `openregister_watchers`, and every row gains a `notify` switch. A follow with `notify` on is what a watcher was; a follow with `notify` off is what a favourite was. Existing favourites move in as follows with notifications off, the favourites table goes, and the favourite endpoints, lens and marker stay for one release as deprecated aliases. Being assigned an object (the property a schema marks with `x-openregister-role: assignee`) follows it with notifications on.

## Why

Ruben, reviewing the dossiq board DqMijnWerk on 2026-10-09: "Following cases is complicated; let us merge following and favourites into one feature." A user met a star and a bell on the same page, two lists ("Your favourites", "Cases you follow") and two chips, for what is one question: which records do I keep an eye on. The decisions:

- One feature, Volgen. The star disappears. Per followed object the user turns notifications on or off.
- Existing favourites become follows with notifications off.
- Who follows an object stays visible to whoever may update it (REQ-ACC-09 in dossiq; `GET .../watchers` here). A favourite's "yours alone" (`favourites-and-recent`, dossiq REQ-FAV-01) is retired.
- Being assigned a case means following it with notifications on, like an @mention does today. Unfollowing is always possible.

## What changes

- **One table.** `openregister_watchers` gains `notify` (boolean, default true). Migration `Version1Date20261009130000` adds it and copies every `openregister_favourites` row in with `notify` false; when the user already followed the object the existing row wins, so `notify` stays true. `Version1Date20261009130100` drops `openregister_favourites`. `ObjectFavourite` and `ObjectFavouriteMapper` are removed.
- **The notify switch.** `PUT .../watch` takes an optional body `{"notify": true|false}`. Without it a new follow notifies and an existing follow keeps its setting. Reads and lists carry `@self.watchNotify` for the caller, next to `@self.watching`. The watcher list for editors does not carry anybody's switch.
- **Notifications.** A rule recipient `{"watchers": true}` resolves to followers with `notify` on only.
- **The `_watching` lens** is a correlated `EXISTS` in the query, like `_unread` and `_favourite` before it, instead of a uuid list on `_ids` that grew with every follow.
- **Deprecated aliases, one release** (design D-3): `_favourite=true` answers exactly what `_watching=true` answers; `@self.favourite` mirrors `@self.watching`; `PUT .../favourite` follows with notifications off when the caller did not follow yet and leaves an existing follow alone; `DELETE .../favourite` unfollows. Both routes answer with a `Deprecation` header and a `Link` to `.../watch`. They are removed in the release after the one that ships this change.
- **Assignment follows.** When a create or update sets the property a schema marks `x-openregister-role: assignee` to a Nextcloud user who may read the object, that user follows it with `notify` on (an existing follow with `notify` off is switched on). A reassignment does not unfollow the previous assignee. `WatcherService::followAssigned()` is public for apps whose assignment does not live in an object property.
- **Consistency check.** The `orphan-favourites` probe queried `openregister_object_favourites`, a table that never existed. It becomes `orphan-follows` on `openregister_watchers`.

## Affected code

- `lib/Migration/Version1Date20261009130000.php`, `lib/Migration/Version1Date20261009130100.php` (new)
- `lib/Db/Watcher.php`, `lib/Db/WatcherMapper.php`; removed `lib/Db/ObjectFavourite.php`, `lib/Db/ObjectFavouriteMapper.php`
- `lib/Service/Interaction/WatcherService.php`, `lib/Service/Interaction/FavouriteService.php` (now a deprecated facade over the watcher rows)
- `lib/Controller/ObjectWatchersController.php`, `lib/Controller/ObjectFavouriteController.php`
- `lib/Listener/AssigneeFollowListener.php` (new), `lib/AppInfo/Application.php`
- `lib/Service/Object/RenderObject.php`, `lib/Db/ObjectEntity.php`
- `lib/Service/Object/SearchQueryHandler.php`, `lib/Db/MagicMapper/MagicSearchHandler.php`
- `lib/Service/Operations/ConsistencyCheckService.php`

## Cross-repo

- nextcloud-vue: one follow control with a notifications switch replaces `CnFavouriteToggle` and `CnFollowToggle`; one "Following" lens replaces Favourites and Following (minor release).
- dossiq: one chip "Volgend", the tile "Zaken die u volgt", the case schema marks `assignee` with `x-openregister-role: assignee`, REQ-CM-40, REQ-FAV-01/02 and REQ-ACC-09 amended.
- design-system: boards that draw a star or a favourites tile or chip move to the one follow control.
- The open change `record-star-follow-and-unread-on-screen` is amended in this PR: no star, one Follow control, quick filters Following, Recent and Unread.

## Out of scope, later candidates

- keepiq keeps its own private secret favourites (`vault-favourites-tags-and-last-used`): a secret is not an OpenRegister object and has no audience to notify.
- Task keeps its own watchers JSON on the task row. Moving it onto `openregister_watchers` is a separate change.
- Per-event notification preferences (which kinds of change a follower hears about).

## Impact

- **Breaking for nobody in this release**: every old route, lens and marker still answers. A favourite is now visible in `GET .../watchers` to editors, which is the decision, not a side effect; the proposal names it so the release note does.
- **Notifications**: a user whose favourites move in hears nothing new, because they arrive with `notify` off.
- Size: M.
