# Design: transition-as-system

## D-1. A method, not a flag

`transition()` is what `TransitionController` calls with the request's
action and payload. A `_rbac: false` parameter on it would be one refactor
away from being wired to a request field. A separate method cannot be
selected by anything a client sends, and its name says what it does at
every call site. The payload of `transition()` is unchanged: an unknown
key such as `_rbac` or `asSystem` is still refused by the inputs allowlist,
and the read check runs before that.

## D-2. Skip exactly OpenRegister's own checks

Three points apply the caller's rights in a transition today: the subject
find (`ObjectService::find` with RBAC and the organisation filter), the
`update` check (`PermissionHandler::hasPermission`), and the save
(`ObjectService::saveObject`). The system path turns off those three, plus
the provider-mode re-read, and nothing else. The organisation filter goes
with RBAC because a row saved as the system, like learniq's draft, need
not carry the caller's organisation, and "as the system" means the same
in `SystemOperationContext`.

## D-3. What the transition declares still runs, with the real caller

The guard, the per-transition `authorization`, the `condition` and the
`actions[]` run in listeners on the save and read the session user. The
session is not changed (no `runAs`, no `SystemOperationContext`), so they
see the real caller and refuse exactly as before. A provider-mode
transition hands the provider the real caller too; the provider performs
its own write and its own checks.

## D-4. The audit row names the real caller and the app

The audit row's user comes from the session, so it is the real caller
without any change. `LifecycleActionContext` (request-scoped, shared by
DI) gains a per-uuid stack of system marks, set by
`LifecycleWriteBoundary::runningAsSystem()` around the write and released
in a `finally`. `AuditTrailMapper::buildAuditTrail()` reads it through the
container, fail-soft like the automatic-transition marker, and adds
`transitionAsSystem: {"app": ...}` before the row is sealed. An automatic
transition that follows from the move is drained after the mark is
released, so it is recorded as automatic, not as a system move.
