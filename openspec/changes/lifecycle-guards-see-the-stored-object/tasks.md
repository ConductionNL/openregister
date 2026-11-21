# Tasks: lifecycle-guards-see-the-stored-object

- [x] 1.1 `LifecycleGuardContext` and `LifecycleTransitionGuardInterface`; `LifecycleValidationListener::runGuard()` calls `checkTransition()` for a guard that implements it.
- [x] 1.2 `LifecycleInitialStateListener` refuses a create whose lifecycle value is not the resolved initial state, fails closed on an unresolvable initial, and lets `SystemOperationContext` through.
- [x] 1.3 Tests: a guard receiving the stored object, a payload that cannot fool it, a payload guard unchanged; a create in a later state, in the initial state, as the system, through the `property` alias, against a dynamic initial and an unresolvable one.
- [x] 1.4 `createStates`: listener, annotation validator, round-trip test through save, export and update.
- [x] 1.5 `historic=true` on the register import: admin-only, rows keep their state through `KeepLifecycleStatesScope` (no elevation, ADR-099).
- [x] 1.6 The create-that-becomes-an-update order (design D-3b), after #5404. Landed as change `create-over-taken-identifier-runs-update-path` (archived 2026-11-12): `MagicMapper::insertObjectIn()` holds the creating refusal until the write knows it is a create; `MagicMapperRefusedCreateComposedTest` covers it.
- [ ] 2.1 App lanes move the guards in design D-4 to `checkTransition()` and read ownership from `$context->stored`.
