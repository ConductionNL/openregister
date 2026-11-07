# Tasks: aggregate-system-native-path

- [x] 1.1 `runAdhoc()` passes `rowRbac: $bypassRbac === false` to the native path and skips the ad-hoc cache for an internal-system call (O14).
- [x] 2.1 `tests/Unit/Service/Aggregation/AggregationRunnerReadPermissionTest.php`: over the real SQLite table and the real `MagicRbacHandler` predicate, a conditional reader asking `runAdhoc(..., bypassRbac: true)` and a call with no user through `runAdhocByRef(..., bypassRbac: true)` count every row; red on development (1/2 and empty), green after.
- [ ] 3.1 Live: add a published lesson to a learniq course, run `occ openregister:rematerialise-calculations learniq enrolment`, expect its enrolments to be touched with `totalPublishedLessonCount` equal to the course's published lessons, failed 0, exit 0 (needs the live instance).
