---
kind: code
depends_on: [insert-race-runs-update-guard]
---

# Proposal: insert-race-follow-ups

## Summary

Three follow-ups to `insert-race-runs-update-guard` (openregister#5395), from its review.

1. **The event after the write.** A create that finds its identifier taken is written as an update of the stored row, and since #5395 the update guard runs first. Afterwards it still announced `ObjectCreatedEvent`, so a listener on creations treated an existing object as new, and a listener on updates never heard of the change. It now announces `ObjectUpdatedEvent`, with the stored row as the old object. This revises #5395's proposal, which kept the created event on purpose.
2. **The permission it is judged by.** A save that names a uuid looks the object up as the caller. When the object is stored but hidden from the caller, the lookup found nothing, so the save was judged by the schema's create rule. It then landed on the stored row. With RBAC on, a save over a stored row the caller cannot read must now pass both the create rule on the incoming data and the update rule against that row: its owner and the row itself. Passing only one is not enough, so a caller who may update but not read cannot get the hidden content back through PATCH. With RBAC off, nothing changes.
3. **A seed object written twice.** `ImportHandler` inserted every seed object through the object entity mapper and then again through the routing mapper when one was set. It was an `if/else` that lost its `else` in a quality fix. The seed object is now inserted once: through the routing mapper when set, else the object entity mapper.

## What stays the same

- A create that wins its identifier announces `ObjectCreatedEvent`, as before, deferred when the instance defers it.
- `SystemOperationContext` still withholds the lifecycle events.
- A truly concurrent writer that lands the row after the permission check is judged by the update guards (#5395). It is not judged by the update permission, because the mapper does not know whether the caller switched RBAC off.
