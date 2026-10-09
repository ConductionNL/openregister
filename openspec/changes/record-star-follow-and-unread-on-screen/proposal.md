---
kind: code
depends_on: []
---
# Proposal: record-star-follow-and-unread-on-screen

## Summary

> **Amended 2026-10-09 by `merge-follow-and-favourites`.** Ruben merged the star into Follow: there is no star any more, one Follow control carries a notifications switch, and the quick filters are Following, Recent and Unread. The change keeps its name so its tracking issue keeps matching; the text below is the amended scope.

OpenRegister knows, per user, which records they starred, which they follow and which changed since they last looked. Three archived changes built that state and its query lenses. Nothing on screen uses it. This change puts one Follow control (with a notifications switch) on the record page, marks a record read when its page opens, badges the record's tabs with what is new, and gives the Tables page a follow column, an unread marker and three quick filters: Following, Recent, Unread.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | rec-favourites | Mark records as favourites and find the ones you opened recently. | partial |
| openregister | rec-follow | Follow a record and be notified when it changes. | partial |
| openregister | rec-unread | See which records are new or changed since you last looked. | partial |

Delivered changes, backend only: `favourites-and-recent`, `object-watchers` and `object-read-state` (all archived 2026-10-05). Spec round part 2 re-rated the three rows to specified with the note "backend built, no screen".

## What is there

- Star: `PUT`/`DELETE /api/objects/{register}/{schema}/{id}/favourite` (`appinfo/routes.php:183,189`, `lib/Controller/ObjectFavouriteController.php`); `@self.favourite` on reads and lists.
- Recent: the detail read records a view (`lib/Controller/ObjectsController.php:3035` `recordObjectView`).
- Follow: `PUT`/`DELETE .../watch` (`appinfo/routes.php:142`, `lib/Controller/ObjectWatchersController.php:98,129`), `GET .../watchers` (`:160`); `@self.watching` and `@self.watcherCount`; a schema notification rule with `{"watchers": true}` notifies followers (`lib/Service/Notification/NotificationRecipientResolver.php:160`).
- Unread: `GET`/`PUT`/`DELETE .../read-state` (`appinfo/routes.php:205-217`, `lib/Controller/ObjectReadStateController.php:96,142,194`); `@self.unread` on reads and lists; `@self.unreadCounts` per sub-resource on the detail read only (`ObjectsController.php:3048`, `:6774`).
- Lenses `_favourite`, `_recent`, `_watching`, `_unread` (`lib/Service/Object/SearchQueryHandler.php:88,197,254`).

## What is missing

No page in OpenRegister's `src/` or in nextcloud-vue calls any of these routes or reads these markers (git grep, 2026-10-07). Opening a record does not mark it read: only `PUT .../read-state` does, and nothing sends it.

## What changes

- Record page (`src/views/object/ObjectDetails.vue`): one Follow control beside the title, with a notifications on/off switch, a follower count, a "Mark as unread" action, a `PUT .../read-state` when the page has rendered the record, and a count badge on the Files tab and the other tabs that `@self.unreadCounts` names.
- Tables page (`src/views/search/SearchIndex.vue`): a follow column the user can click, unread rows drawn in bold with a dot, and quick filters Following, Recent and Unread that pass the lenses `_watching`, `_recent` and `_unread`.
- Both pages use nextcloud-vue components (cross-repo, design D-1), so every leaf app's `CnDetailPage` and `CnIndexPage` get the same affordances.
- `@self.can` gains `manage` next to `update` (design D-6), so the followers popover shows the "Add a colleague" picker only to a caller the watchers endpoint will admit. Asked for by nextcloud-vue PR #1374 (`record-favourite-and-follow`, design D3).

## ADRs

- ADR-022: the state is OpenRegister's; the pages only read and toggle it.
- ADR-005: every marker is resolved for the current user and never shown for another.
- hydra ADR-031: who is notified is the schema's notification rule; Follow does not add its own channel.

## Impact

- Extends `object-interactions` and `object-read-state`.
- Affected code: `src/views/object/ObjectDetails.vue`, `src/views/search/SearchIndex.vue`, the object store in `src/store/modules/`. One backend addition: `manage` in `@self.can` (`lib/Service/Object/RenderObject.php`).
- Backwards compatible: nothing changes for a user who never follows or opens a record.
- Size: M.

## Out of scope

- A cross-app "my favourites" or "following" dashboard widget: a nextcloud-vue widget over the same lenses, asked for by the leaf apps, not by this row.
- Notification preferences per user.
