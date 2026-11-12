---
kind: code
depends_on: [insert-race-follow-ups]
---

# Proposal: create-over-taken-identifier-runs-update-path

## Summary

A create dispatches `ObjectCreatingEvent` before it claims its identifier.
When a creating hook refused it, the refusal was thrown at once. But if the
identifier was already taken, the write was never a create: since #5395 it
is carried out as an update of the stored row, judged by the update guards.
So a create-shaped write over an existing row could be refused by a rule
that only applies to new objects. The clearest case is the initial-state
check from #5400: a client that re-posts an existing object in a later state.

Now the creating refusal is held until the write knows what it is. When the
caller did not ask to fail on a taken identifier and a live row exists, the
write goes through the update path: `ObjectUpdatingEvent` with the stored
row as the old object, and the update guards decide. Otherwise the creating
refusal is thrown, as before.

## What stays the same

- A refused create over a soft-deleted object stays refused. Writing over it
  would bring the object back without the create-only rules (quota, an exam
  attempt gate and the like).

- A refused create of a free identifier is refused with the same message.
- A claim (`failIfExists: true`) that a creating hook refused is refused with
  the creating refusal, and nothing is written.
- Data a creating hook sets is written.

## What changes besides

The table for the register and schema is now ensured before a refused create
is thrown. That is the same idempotent call every insert makes.
