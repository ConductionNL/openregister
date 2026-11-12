---
kind: code
depends_on: [insert-race-follow-ups]
---

# Proposal: save-over-soft-deleted-object

## Summary

A save that names a uuid looks the object up as the caller. When that
finds nothing, `ObjectService` looks the uuid up once more, past the read
rule, to judge a stored row the caller cannot see: the draft rule and, since
#5404, the update rule against that row. That second lookup left
soft-deleted rows out, so a save over a soft-deleted object was judged as a
plain create, while the write landed on the stored row. The lookup now
includes soft-deleted rows, so such a save is judged against the stored
object like any other row the caller cannot see.

The change also adds a test from the #5404 review: an insert that wins its
identifier, nested in an updating listener, leaves the outer update announced
as an update.
