# Tasks: sequence-fields-are-set-by-the-sequence-service-only

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 3. -->

- [x] 1.1 `tests/Unit/Service/Task/TaskServiceTest.php`: `create()` refuses each sequence field for a user and an administrator, naming it, with no insert; a `null` field passes; `import()` still carries the fields. Fails on development.
- [x] 1.2 `TaskService::create()` refuses the fields through `refuseSequenceFields()` (D-1 to D-3).
- [x] 1.3 Spec delta with scenarios.
