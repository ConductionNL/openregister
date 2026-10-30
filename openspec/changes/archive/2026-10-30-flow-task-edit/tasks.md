# Tasks: flow-task-edit

### Task 1: A task's own fields can be edited by the people working it
- **spec_ref**: `openspec/changes/flow-task-edit/specs/flow-tasks/spec.md#requirement-a-task-s-own-fields-can-be-edited-by-the-people-working-it`
- **files**: `appinfo/routes.php`, `lib/Controller/TaskController.php`, `lib/Service/Task/TaskService.php`, `lib/Service/Task/TaskBuilder.php`, `lib/Service/Task/TaskAuthorizationService.php`
- [x] Implement: route `task#edit`, `TaskController::edit`, `TaskService::edit`, `TaskBuilder::applyEdit`, verb `edit`
- [x] Test: `tests/Unit/Service/Task/TaskEditTest.php`, `tests/Unit/Controller/TaskControllerTest.php`
