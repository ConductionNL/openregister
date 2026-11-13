# Design: a-conflicting-save-shows-the-other-value

## D-1: the refusal carries the answer

The version number in a 409 is only useful to a machine that will retry.
A person needs the other value. So the body carries three readings per
conflicting property: sent, read, stored. A client can then render a choice
without a second request, and without a race between the refusal and the
reload.

## D-2: only the properties that actually conflict

A property the caller did not touch is not a conflict, even when the stored
object changed. Reporting the whole object as conflicting is how people
learn to click through the dialog. The body lists the intersection of what
the caller changed and what somebody else changed.

## D-3: the assertion belongs to the save path, not to the verb

PATCH, PUT and any other write reach the same save pipeline, so the version
assertion sits there. A write with no expected version keeps today's
behaviour, so nothing that works now starts failing; a write that sends one
gets the guarantee.

## D-4: a refusal is not a read

Field-level security is evaluated on the conflict body. A property the
caller may not read is named as conflicting with no values attached. An
error path that discloses more than the read path is a security bug that
looks like a feature.

## D-5: reuse analysis (ADR-012)

- The version and etag already computed by the object read: reused.
- The field-level filter of `row-field-level-security`: reused on the
  conflict body.
- The audit writer: reused for the refusal entry.
- No new locking, no new sync store. `run-scoped-object-locking` remains the
  answer where a hard lock is wanted; these two are complementary and the
  proposal says which is the lighter design.
