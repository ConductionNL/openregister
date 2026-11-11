---
kind: code
---

# Proposal: saved-view-delete-owner-only

## Summary

`DELETE /api/views/{id}` deletes any **public** view for any signed-in user.
`ViewsController::destroy()` calls `ViewService::delete()`, which loads the view
through `ViewService::find($id, $owner)`, and `find()` lets a caller through
when `owner === caller` **or `isPublic === true`**. A read check is used as the
delete guard. The spec already says a reader may not delete
("Write on a share changes the query, never the audience"); the code lets a
public view's reader do it.

## Change

`ViewService::delete()` loads the view by id and refuses, as the 404 of a
missing view, every caller who neither owns it nor administers the instance,
before the mapper's delete runs. Same rule as `ViewShareResolver::mayAdminister()`,
which update and patch already use. No schema or endpoint change.

Found by the saved-filters lane (screens round 6, 19 Oct 2026) while planning
delete for nextcloud-vue's saved-filter menu.
