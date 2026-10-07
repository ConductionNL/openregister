# Design: record-star-follow-and-unread-on-screen

No design board draws OpenRegister's record page or Tables page; OpenRegister is not one of the canvas apps. Placement follows the zuiddrecht case page the leaf apps use (star and follow in the header, beside the title), so a user meets them in the same place in every app.

## D-1: the toggles and markers are nextcloud-vue components

The archived `favourites-and-recent` design (D-3) put `@self.favourite` on the read so "`CnDetailPage` can render a star from data it already has". The same holds for follow and unread. nextcloud-vue ships them; OpenRegister places them:

| Component (nextcloud-vue) | Reads | Writes |
|---|---|---|
| `CnFavouriteToggle` | `@self.favourite` | `PUT`/`DELETE .../favourite` |
| `CnFollowToggle` | `@self.watching`, `@self.watcherCount` | `PUT`/`DELETE .../watch` |
| `CnUnreadMarker` (row dot, bold) | `@self.unread` | none |
| tab badge on `CnDetailPage` tabs | `@self.unreadCounts` | none |
| `CnIndexPage` quick filters `favourite`, `recent`, `watching`, `unread` | none | adds the lens to the list query |
| `CnIndexPage` star column | `@self.favourite` | through `CnFavouriteToggle` |

OpenRegister's record page is a custom page (`ObjectDetails.vue`), not `CnDetailPage`, so it places `CnFavouriteToggle` and `CnFollowToggle` in its own header next to the `NcActions` menu, and passes each tab its count. Its Tables page already renders `CnIndexPage`, so the star column, marker and quick filters arrive by turning on the component's options.

## D-2: optimistic toggles, reverted on failure

A click flips the star or the Follow state at once and sends the request. A failure flips it back and shows the server's message. A 404 (the record went away or access was withdrawn) reloads the page.

## D-3: when a record counts as read

The page sends `PUT .../read-state` once, after the record's data has rendered, not on route entry: a user who bounces off a failing page has not seen the record. "Mark as unread" in the header menu sends `DELETE .../read-state` and leaves the page; the record then shows as unread in the list the user returns to. The per-tab counts are read from the detail response; opening a tab does not mark that tab's items read in this change (the backend tracks one moment per record, not per tab).

## D-4: Follow explains what it does

The Follow toggle carries a tooltip with the follower count. When the record's schema has no notification rule that targets watchers, the toggle still works (the Following filter uses it), and the tooltip says "You will see it under Following. This register sends no change notifications." A Follow that silently notifies no one would be a promise the page cannot keep.

## D-5: Recent

The Recent quick filter passes `_recent=true`, which orders by the user's last view. The column sort is disabled while it is on, because the lens owns the order.
