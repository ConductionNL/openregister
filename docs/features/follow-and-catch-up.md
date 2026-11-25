# Follow and catch up on records

## Overview

OpenRegister's own screens use the per-user follow and read state that
[Object Read State](object-read-state.md) and
[Favourites & Recently Opened](favourites-and-recent.md) describe. Following a
record and seeing what changed since you last opened it work the same way here
as in the apps built on OpenRegister.

## On a record's page

**Follow** sits in the header, next to the record's title.

- Click it to follow the record. Click it again to stop.
- While you follow, a bell switches change notifications on or off. You stay a
  follower either way, and the record stays on your Following list.
- The tooltip shows how many people follow the record. Some schemas have no
  notification rule that writes to followers. On those records the tooltip says
  so: following adds the record to your Following list and sends no change
  notifications.
- The record's owner and administrators can also add or remove colleagues as
  followers.
- If a change does not go through, the toggle switches back and shows the
  server's message. If the record was removed, or your access to it was taken
  away, the page reloads.

Once the record has shown on screen, it counts as read. A page that fails to
load marks nothing.

The Files, Syncs, Emails, Events, Contacts and Deck tabs each show a badge with
the number of unread items they hold.

**Mark as unread** in the actions menu puts the record back on your unread
list and takes you back to the list.

## On the Tables page

The quick filters add three lenses to the list. Each one combines with every
other filter on the page:

| Filter | Shows | Query |
| --- | --- | --- |
| Following | records you follow, with or without notifications | `_watching=true` |
| Recent | records you opened, most recent first | `_recent=true` |
| Unread | records changed since you last opened them | `_unread=true` |

While Recent is on, you cannot sort by column: the lens sets the order.

Unread rows show in bold, with an unread dot.

## For developers

- The record page reads `@self.watching`, `@self.watchNotify`,
  `@self.watcherCount`, `@self.can.manage` and `@self.unreadCounts`. A record
  opened from a list row, which carries no `@self.can`, is read once more with
  `_extend=@self.can`.
- Whether a schema notifies followers comes from its
  `x-openregister-notifications` rules: at least one rule must list the
  `{"watchers": true}` recipient.
- The helpers live in `src/views/object/recordFollow.js` and
  `src/views/search/personalLensQuery.js`.
