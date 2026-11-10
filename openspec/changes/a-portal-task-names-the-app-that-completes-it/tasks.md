# Tasks: a-portal-task-names-the-app-that-completes-it

- [x] 1.1 `PortalTaskService::COMPLETE_VIA` + `completeVia(Task)`: whole `{app, action}` or null (`tests/Unit/Service/Portal/PortalTaskServiceTest.php::testOnlyAWholeCompleteViaCounts`)
- [x] 1.2 `PortalTaskService::complete(viaApp:)` refuses a task naming another app after authorization, before storing (`testATaskNamingItsAppIsCompletedByThatAppOnly`)
- [x] 1.3 `TaskCompleteViaAppException` carries the route (`tests/Unit/Exception/TaskCompleteViaAppExceptionTest.php`)
- [x] 1.4 `PortalTaskController::complete` answers 409 `complete-via-app` with `completeVia` (`tests/Unit/Controller/PortalTaskControllerTest.php::testACompletionPastTheNamedAppNamesTheAction`)
- [ ] 2.1 Live: a dossiq checklist task is refused at the seam and completes through dossiq's portal action (live pass, decision 139)
