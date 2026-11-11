# Tasks: flow-task-subject-authorization

- [x] 1.1 `TaskSubjectAccessGuard` asks `ObjectService::find()` with
  `_rbac: true, _multitenancy: true` for the anchor and every relation, and
  refuses with `TaskSubjectNotFoundException` when a read does not answer.
  A missing object service refuses rather than skips.
- [x] 1.2 `TaskService::create()` runs the guard before anything else, for
  non-administrators only. `import()` is untouched.
- [x] 1.3 `TaskController::respondWith()` answers 404 with the guard's
  message, which is the object endpoint's own wording.
- [x] 1.4 The absence of a task DELETE verb is recorded as a decision in
  `appinfo/routes.php`, beside the verbs that do exist.
- [x] 2.1 Unit tests: the unrelated principal is refused and nothing is
  written, the entitled principal still succeeds, a relation is checked like
  the anchor, a standalone task is unaffected, and an absent object service
  denies. Mutation-checked by inverting the guard's condition.
