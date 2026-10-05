# Tasks: transition-as-system

## 1. Engine

- [x] 1.1 `TransitionEngine::transitionAsSystem(objectId, action, app, data)`; an empty `app` is refused with `InvalidArgumentException` before anything is read (D-1).
- [x] 1.2 The system path reads the subject without RBAC and the organisation filter, skips the `update` check, and saves (static and graph mode) and re-reads (provider mode) without them (D-2).
- [x] 1.3 An info log line names the app, the real caller, the object and the action.

## 2. Audit

- [x] 2.1 `LifecycleActionContext` system marks and `LifecycleWriteBoundary::runningAsSystem()`, released in a `finally` (D-4).
- [x] 2.2 `AuditTrailMapper::buildAuditTrail()` adds `transitionAsSystem: {app}` to the change set; the user stays the real caller (D-4).

## 3. Tests

- [x] 3.1 `TransitionEngineAsSystemTest` against the real TransitionEngine and the real LifecycleValidationListener: the normal path refuses a caller without rights; the system path lets the same caller through without calling `hasPermission`; the save carries the real caller and the named action; the guard runs with the real caller and its refusal still refuses; the mark is scoped to the write and released on refusal; the log line; an empty app is refused; the payload cannot ask for the system path; no controller calls it; the audit row names the real caller and the app, and an ordinary row carries no mark.
- [x] 3.2 `openspec validate transition-as-system --strict`.
