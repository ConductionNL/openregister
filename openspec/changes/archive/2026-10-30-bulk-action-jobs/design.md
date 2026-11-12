# Design: bulk-action-jobs

## D-1. The preview is the same code path as the commit

A preview produced by a second implementation is a promise, not a
rehearsal. The executor takes `commit: false`, walks the same selection,
runs the same guards and the same access checks, and returns the outcome
it would have written. The only difference is that the write is not made.
That is the one design property that makes a dry run worth reading.

## D-2. A selection is a set of ids or a query, and the job says which

"Select all" means the page in one product and the whole result set in
another, and the difference is four hundred cases. The job stores either
an explicit id list or the query with its filters, plus the count at
creation. On commit, a query-backed job re-resolves and reports the delta,
so a selection that grew is a visible fact instead of a surprise.

## D-3. Refused is not skipped

An object the actor may not write is refused, with the rule that refused
it. An object the action does not apply to is skipped, with the reason.
Collapsing the two hides a permission problem inside a business outcome,
and the operator reads "12 skipped" and moves on.

## D-4. Cancel stops between objects, never inside one

A bulk job is not one transaction. Cancelling leaves the committed
objects committed, stops before the next one, and reports the boundary.
Promising a rollback across four hundred objects is a promise the database
would have to hold open for minutes, and it is the wrong trade for this
act. The honest contract is: you know exactly where it stopped.

## D-5. Idempotent by member, so a retry is safe

Each member row carries its outcome. A retried job skips members already
applied. Without that, a job that failed at object 300 of 400 is
unretryable, and the operator does the remaining hundred by hand.

## D-6. Homogeneity is declared per action

"Refuse a bulk attribute change across versions" is right for an attribute
write and wrong for a reassignment. The guard belongs to the action, so an
action declares which guards it needs and the engine enforces them.

## D-7. The reason is required where the act is distributive

A bulk reassignment with no recorded reason is an audit finding waiting to
happen, which is the candidate's own clause. The action declares whether a
justification is required; where it is, the job cannot be committed
without one, and the text lands in the audit entry of every member.

## D-8. kind

Code, in OpenRegister. The leaf apps declare actions and render progress.
