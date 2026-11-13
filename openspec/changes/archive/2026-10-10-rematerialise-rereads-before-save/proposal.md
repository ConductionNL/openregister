# Proposal: rematerialise re-reads a row before it saves it

## Why

The live pass of 5 Oct (lane 15, defect O11) ran `occ openregister:rematerialise-calculations learniq enrolment` on 205 rows after #4355. It reported "Touched 5, unchanged 181, failed 19" and exited 1, each failure "Cannot modify readOnly properties: completedLessonCount, totalPublishedLessonCount", while afterwards all 205 rows held both values and a second run reported touched 0, failed 0.

The command reads every row first and then, per changed row, re-saves the data from that first read. Saving one enrolment re-saves its siblings (the aggregate over lesson-completion of the same course), which materialises their values. When the command reaches such a sibling, its first-read data still says null while the stored row already holds the value, so the save tries to change a readOnly value back and is refused.

## What changes

- Before saving a changed row, the command reads the row again. Values an earlier save in the run already materialised are dropped from what it expects; a row with nothing left is counted touched without a save. The save that remains uses the data as stored now, not the first read.

## Impact

- `lib/Command/RematerialiseCalculationsCommand.php` only. One extra read per changed row.
- Not in scope: that one enrolment save re-saves 170+ siblings (noted in O11 as worth a look); that is the aggregate fan-out on save, not this command.
