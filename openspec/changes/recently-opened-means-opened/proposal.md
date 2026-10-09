---
kind: code
depends_on: [read-history-on-audit-trail]
---
# Proposal: recently-opened-means-opened

## Summary

`read-history-on-audit-trail` (openregister#4514) answers `_recent=true` from the audit trail's `read` rows. It left two gaps, and this change closes both.

1. **A lookup counts as opening.** Every audited `ObjectService::find()` writes a `read` row, so code that reads an object while serving a person (a permission guard, a relation lookup, a sub-resource such as the logs or the relations tab, an agent tool) put that object in the person's recent list. The audit trail must keep recording those reads; only the recent list must stop counting them.
2. **Cross-table searches ignore the lens's order and moment.** A search over several schemas or registers returned the restricted set in arbitrary order and without `@self.viewedAt`, and the UNION path did not apply the `_ids` restriction at all.

## What changes

- The audit cause vocabulary (`WriteCause`, REQ-RCN-001) gains a seventh value, `lookup`: a read the code made on a person's behalf, not the person opening the object. `WriteCause::asLookup()` opens that frame only when the acting cause is `person`, so a read inside an import, a rule or a scheduled job keeps its own cause.
- The read history (`AuditTrailMapper::findLatestReadsByUser()`) counts a `read` row only when its cause is `person`, or empty for rows written before causes existed. Nothing is removed from the audit trail: a lookup is still a `read` row, with its cause saying why.
- Every internal `ObjectService::find()` call that is not the person opening the object runs inside `WriteCause::asLookup()`. The sites and the decision for each are listed in design D2.
- The UNION arm of a cross-table search applies `_ids` (uuid or slug), as the single-table path does.
- `MagicMapper::searchAcrossMultipleTables()` and the global `_ids` path order a `_recent` page by the read history (unless `_order` is given), page it after ordering, and set `@self.viewedAt` on every object.
- The cross-table list response (`ObjectsController::crossTableSearch()`) carries `@self.lenses.recent`, like the single-schema list.

## Out of scope

- `_audit: false` is not added anywhere. Every read the audit trail recorded before this change it still records.
- The AVG processing log is untouched.
- Leaf apps that call `ObjectService::find()` for their own internal lookups keep counting as opened until they wrap the call in `WriteCause::asLookup()`.

## Related

- Stacks on openregister#4514 (`read-history-on-audit-trail`) and merges after it.
- openregister#4516 (`merge-follow-and-favourites`) also amends `record-star-follow-and-unread-on-screen`; this change touches only its `recordObjectView` line.
