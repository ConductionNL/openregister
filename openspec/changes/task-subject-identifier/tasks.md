# Tasks: task-subject-identifier

- [x] 1.1 Test: the subject context carries the subject's `identifier`, null for an object without a usable one (`TaskInboxServiceTest::testSubjectContextCarriesTheSubjectsIdentifier`, red on development).
- [x] 1.2 `TaskInboxService::contextRow` adds `identifier`.
- [x] 1.3 Test: `GET /api/flow-tasks/{uuid}` carries the subject context (`TaskControllerTest::testShowCarriesTheSubjectContext`, red on development).
- [x] 1.4 `show` and the verb responses use `TaskInboxService::enrich`.
- [ ] 1.5 Live: `/api/flow-tasks` rows on :8080 carry `subject.identifier` for case tasks.
