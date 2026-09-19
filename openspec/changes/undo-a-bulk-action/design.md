# Design: undo-a-bulk-action

## D-1: the inverse is a job, not a rollback

A transaction rollback is unavailable: the members were written one by one,
minutes or hours ago, and other people have written since. So the inverse
is an ordinary bulk job whose selection is the members of the original and
whose per-member write is the recorded prior value. That keeps one
execution path, one ceiling, one preview and one audit shape, and it makes
the reversal itself reversible.

## D-2: what is stored, and what is not

The per-member outcome stores only the properties the action touched, with
their prior values, not a snapshot of the object. An action that rewrites
many properties on many members declares a smaller ceiling or declares
itself irreversible. The bound is explicit because an unbounded undo buffer
is a second copy of the register.

## D-3: a later edit wins over an undo

If a member changed after the original job wrote it, the prior value is no
longer the value to restore. Those members are reported as not reversible,
by name, and skipped. The alternative, restoring anyway, silently destroys
work that somebody did deliberately, which is the failure this change
exists to prevent, pointed the other way.

## D-4: irreversible is declared, and said early

Destruction, a dispatched notification and an e-depot transfer cannot be
undone. The declaration sits on the action, the preview reports it, and the
reversal route refuses it rather than appearing to work.

## D-5: reuse analysis (ADR-012)

- The job, the selection, the ceiling, the preview and the progress
  reporting from `bulk-action-jobs`: reused whole.
- `ObjectService` for every write: reused, so RBAC, tenancy and the audit
  trail are unchanged.
- No new queue, no new job runner, no second audit store.
