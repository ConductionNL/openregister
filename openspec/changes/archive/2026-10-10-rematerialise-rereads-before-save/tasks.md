# Tasks: rematerialise-rereads-before-save

- [x] 1.1 The command re-reads each changed row (as the system) before saving it, drops values already materialised, counts a row with nothing left as touched without a save, and saves the current data otherwise (O11).
- [x] 2.1 `tests/Unit/Command/RematerialiseStaleSnapshotTest.php`: two enrolments of one course, the first save materialises the sibling, the REAL `ValidateObject` readOnly rule checked against the row as stored at save time; red on development with the live message ("save failed on ...: Cannot modify readOnly properties: completedLessonCount", failed 1), green after.
- [ ] 3.1 Live: on the dev instance clear completedLessonCount on a few enrolments of one course, run `occ openregister:rematerialise-calculations learniq enrolment`, expect failed 0 and exit 0 (needs the live instance). (live pass, decision 139)
