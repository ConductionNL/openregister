# Tasks: lifecycle-guards-see-the-stored-object

- [x] 1.1 `LifecycleGuardContext` and `LifecycleTransitionGuardInterface`; `LifecycleValidationListener::runGuard()` calls `checkTransition()` for a guard that implements it.
- [x] 1.2 `LifecycleInitialStateListener` refuses a create whose lifecycle value is not the resolved initial state, fails closed on an unresolvable initial, and lets `SystemOperationContext` through.
- [x] 1.3 Tests: a guard receiving the stored object, a payload that cannot fool it, a payload guard unchanged; a create in a later state, in the initial state, as the system, through the `property` alias, against a dynamic initial and an unresolvable one.
- [ ] 2.1 App lanes move the guards in design D-4 to `checkTransition()` and read ownership from `$context->stored`.
