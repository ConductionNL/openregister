# Proposal: an aggregate resolved as the system counts every row on the native path

## Why

The live check of 5 Oct (lane 16, defect O14) added a published lesson to a learniq course and ran `occ openregister:rematerialise-calculations learniq enrolment`. It reported "Touched 0": every enrolment kept `totalPublishedLessonCount` 0, and so did the enrolments of a course with three published lessons. `AggregationRunner::runAdhocByRef(learniq, lesson, count, bypassRbac: true)`, the call `AggregateReferenceResolver` makes, answered 0 under the command line even without a filter, and 3 for the same call as admin.

`runAdhoc()` honoured `bypassRbac` only for the read gate. It called the native SQL path without `rowRbac`, so the caller's row-level read predicate was still applied; with no user (occ, cron, a system listener) that predicate is `1 = 0`. `run()` already passes `rowRbac: $bypassRbac === false`. The earlier change calculations-resolve-references-regardless-of-saver made the resolver ask as the system, but the native path never saw it.

## What changes

- `runAdhoc()` passes `rowRbac: $bypassRbac === false` to the native path, as `run()` does.
- `runAdhoc()` neither reads nor writes the ad-hoc cache for an internal-system call: the cache key is scoped by the caller (uid and organisation), not by `bypassRbac`, so a figure over every row would otherwise be served to the same caller's own, narrower question.

## Impact

- `lib/Service/Aggregation/AggregationRunner.php`, `runAdhoc()` only.
- Every materialised aggregate (`x-openregister-aggregate-refs`) saved without a user now resolves to the real figure; rows already holding 0 are corrected by the next save or a rematerialise run.
- Not in scope (noted): the PHP fallback of `runAdhoc()` and `run()`'s cache key do not distinguish `bypassRbac` either; the fallback is reached only by shapes the native path refuses.
