# Design: admin-operations-console

## D-1. A run is a row, written by a wrapper, not by each job

Asking fifteen jobs to report themselves gives fifteen answers and three
that forget. The console reads one table, written by a wrapper around the
job execution: start, end, outcome, failure. A job that is not wrapped is
absent from the list, and the console says which jobs those are rather
than pretending the list is complete.

## D-2. Run now is an authorised act, and it never doubles a run

Starting a job by hand is how an administrator clears a stuck queue, and
it is also how two copies of a termijn job run at once. The console takes
the job's own lock; a job already running is refused with the run it would
have collided with.

## D-3. The alert has a threshold, because one failure is not an incident

A notification per failed run trains people to ignore notifications. The
threshold is a count over a period, both administered, which is what
Freescout ships. The alert names the job and its first failure, so the
reader has somewhere to start.

## D-4. A maintenance action is a job, so it is observable

Rebuilding the index, clearing the cache and running a repair are long
operations that can fail. Running them as jobs puts them on the same list
with the same outcome and the same failure, instead of as a button that
returns 200 and tells nobody what happened.

## D-5. The check reads and the repair writes, and they are two acts

Forgejo's doctor separates the check from the fix, and the separation is
the point: an administrator sees what is wrong before anything changes.
The repair names the objects it will touch and needs its own
authorisation.

## D-6. Maintenance mode refuses, and never locks the administrator out

Closing the instance is only safe when the person who closed it can open
it again. The mode refuses reads and writes with the administered message
and leaves the administration surface reachable.

## D-7. The support bundle is redacted at the source

A bundle assembled from the live configuration carries credentials unless
something removes them. The redaction happens where the bundle is built,
against the same rules the logger uses, so one answer covers both.

## D-8. kind

Code, in OpenRegister.
