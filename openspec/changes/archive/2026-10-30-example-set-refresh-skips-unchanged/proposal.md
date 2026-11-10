# Proposal: example-set-refresh-skips-unchanged

## Why

`example-set:install --refresh` rewrites every object the set holds, also when nothing it declares has changed. On the Zuiddrecht demo that is 1,402 objects and about 18 minutes per run (0.77 s per object), and the daily refresh job repeats it every day. Each write adds an audit-trail row, queues a text-extraction job and fires every app's update listeners, so an unchanged demo still produces 1,402 audit rows and 1,402 background jobs per refresh. While the job runs it holds the set lock, and a manual install in that hour is refused.

## What changes

- Before a kept object is rewritten, the update the refresh would send (after the schema's write rules: kept fields carry their stored value, lenses are left out) is compared with the stored row. When every field it would send already holds that value, the object is not written: no update, no audit row, no text-extraction job.
- The comparison is against the stored row, not against a hash of the declaration kept from an earlier run, so a value changed on the instance since the last refresh is still written back.
- An object whose values differ is written exactly as before.
- The owner hand-over and the record of what the run wrote are unchanged for a skipped object, so the proof still checks it.
- The report counts a skipped object as `unchanged` next to `refreshed`, and the command prints both.

## Out of scope

- The `--check` pass, which validates every declared object; it writes nothing.
- Engine rows, views, tasks, notes and the other parts the refresh writes again; they are few.
