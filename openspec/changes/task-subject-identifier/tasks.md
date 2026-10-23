# Tasks: task-subject-identifier

- [x] 1.1 Test: the subject context carries the subject's `identifier`, null for an object without a usable one (`TaskInboxServiceTest::testSubjectContextCarriesTheSubjectsIdentifier`, red on development).
- [x] 1.2 `TaskInboxService::contextRow` adds `identifier`.
- [ ] 1.3 Live: `/api/flow-tasks` rows on :8080 carry `subject.identifier` for case tasks.
