# A view update is judged by what changed

## Why

`Controller/ViewsController::update()` applies no access check. It reads the
caller's uid to prove they are logged in, validates that a name and a query are
present, and saves. Any authenticated user who can reach the route can rename
someone else's view, change its owner, make it public, and change who it is
shared with.

The guard for that already exists and is never called.
`refuseForbiddenViewFields()` sits at line 167 of the same file, resolves the
caller's access through `Service/Rbac/ViewShareResolver`, and returns a 403
naming the fields it refuses. Nothing in `lib/` calls it. It is the orphan-auth
shape: a guard nobody calls is indistinguishable from no guard, and it reads as
coverage to the next person who greps.

Wiring it as it stands would break ordinary editing. `ViewShareResolver`
declares `WRITABLE_BY_MEMBER = ['query', 'presentation', 'alert']`, and
`src/modals/view/EditView.vue` sends `name`, `description`, `isPublic`,
`isDefault` and `query` on every save. `refusedFields()` judges the fields
present in the body, so a `write` member who changed only the query would be
refused on four fields they did not touch.

Ruben chose the fix on 2026-09-19: **the endpoint judges which fields actually
changed** and enforces only on those. The modal is not taught to send less. A
client that sends the whole object is a normal client, and an authorization
rule that depends on a client sending a minimal body is a rule the next client
breaks.

## What changes

- **`update()` calls the guard.** The endpoint stops saving unchecked.
- **The guard compares the body against the stored view** and judges only the
  fields whose value differs. A field sent unchanged is not a change and is not
  refused.
- **The comparison is by value, not by presence.** A `sharedWith` list
  reordered but otherwise equal is not a change. A `query` object with the same
  keys in a different order is not a change.
- **The refusal keeps naming the fields**, because the message a member needs
  is which field was refused, not that something was.
- **An unreadable view denies.** That behaviour is already in the guard and
  this change keeps it.

## Capabilities

### Modified capabilities

- `saved-search-views`: the update endpoint gains a field-level access rule
  judged on the difference.

## Impact

- Affected specs: `saved-search-views` (delta).
- Affected code: `Controller/ViewsController::update()`,
  `Controller/ViewsController::refuseForbiddenViewFields()`,
  `Service/Rbac/ViewShareResolver::refusedFields()`.
- Frontend: none. `EditView.vue` keeps sending the whole object.
- Until this lands, a write member can rename a view, change its owner, make it
  public and change who it is shared with. That is the exposure this closes.

## Next step

Work `tasks.md`, starting with the diff helper in `ViewShareResolver`.
