# Tasks: task-subject-identifier

- [x] 1.1 Test: the subject context carries the subject's `identifier`, null for an object without a usable one (`TaskInboxServiceTest::testSubjectContextCarriesTheSubjectsIdentifier`, red on development).
- [x] 1.2 `TaskInboxService::contextRow` adds `identifier`.
- [x] 1.3 Test: `GET /api/flow-tasks/{uuid}` carries the subject context (`TaskControllerTest::testShowCarriesTheSubjectContext`, red on development).
- [x] 1.4 `show` and the verb responses use `TaskInboxService::enrich`.
- [x] 1.5 Live: `/api/flow-tasks` rows on :8080 carry `subject.identifier` for case tasks.
  - 2026-11-25: on a sandbox Nextcloud 34 on MariaDB (not the shared :8080): a task created on a case with `identifier` ZAAK-… carries `subject.identifier` in its create answer, its `GET /api/flow-tasks` row and `GET /api/flow-tasks/{uuid}`; a task on a case without one carries null. Pinned in `tests/integration/openregister-task-subject-identifier.postman_collection.json` (14 requests, 18 assertions, 0 failed).
