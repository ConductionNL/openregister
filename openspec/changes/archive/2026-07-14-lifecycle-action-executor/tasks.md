## 1. Handler contract + built-in

- [x] 1.1 `lib/Lifecycle/LifecycleActionInterface.php` — public contract mirroring `LifecycleGuardInterface`: `execute(array $objectData, array $previousData, array $parameters, string $actionName): array` returning the (possibly self-mutated) payload.
- [x] 1.2 `lib/Lifecycle/Action/SetFieldsAction.php` — built-in for `set-fields` (params ARE the field map) and `set-field` (map under `set`); resolves the `@now` token; throws when no fields are declared (fail loud).

## 2. Registry (fail-loud anti-phantom guard)

- [x] 2.1 `lib/Service/Lifecycle/LifecycleActionRegistry.php` — mirrors `LifecycleGuardRegistry`: built-in name→FQCN map, OR container → server container fallback, per-request cache. Missing handler throws `RuntimeException`; resolved non-`LifecycleActionInterface` throws. Non-`final` (doubled in tests, per TransitionEngine rationale).

## 3. Executor

- [x] 3.1 `lib/Service/Lifecycle/LifecycleActionExecutor.php` — `run(array $actions, array $objectData, array $previousData, string $transition): array`: per action evaluate optional `condition`, resolve via registry, run, thread payload forward.
- [x] 3.2 Condition eval: `@self.<field>`/`@previous.<field>` `==`/`!=` `'literal'`; unparseable condition, malformed action, and missing `action` name all throw (fail loud, never silent skip).

## 4. Listener (list-form transition fix)

- [x] 4.1 `lib/Listener/LifecycleActionListener.php` on `ObjectUpdatingEvent`: return early on `isPropagationStopped()`; null old-object; absent `x-openregister-lifecycle`. Parse off `Schema::getConfiguration()`, `matchTransition()` from old/new lifecycle value (mirrors `LifecycleValidationListener::findTransitionByTarget`).
- [x] 4.2 When the matched transition declares `actions[]`, run the executor and apply self-mutations via `$newObject->setObject()` (mirrors `CalculationOnSaveListener`). Do NOT catch the executor's fail-loud exception — let it propagate to abort the save.
- [x] 4.3 Register in `Application.php` on `ObjectUpdatingEvent`, immediately after `ApprovalChainGateListener`, so actions run only for a legal, non-blocked transition. (Autowired — no explicit `registerService`, same as `LifecycleGuardRegistry`.)

## 5. Tests (fail-loud + list-form required)

- [x] 5.1 `tests/Unit/Lifecycle/Action/SetFieldsActionTest.php` — `set-fields` + `set-field` shapes, `@now` resolution, empty-field-map throws.
- [x] 5.2 `tests/Unit/Service/Lifecycle/LifecycleActionRegistryTest.php` — built-in resolves; app handler resolves; **missing handler FAILS LOUDLY**; wrong-type FAILS LOUDLY.
- [x] 5.3 `tests/Unit/Service/Lifecycle/LifecycleActionExecutorTest.php` — action runs + mutation threaded; false condition skips; `@previous` true condition runs; unparseable condition throws; missing handler propagates; missing action-name throws.
- [x] 5.4 `tests/Unit/Listener/LifecycleActionListenerTest.php` — **LIST-FORM transition (plain `ObjectUpdatingEvent`) runs the declared action and stamps the field** (LeaseContract-class bug); no-actions no-op; propagation-stopped skips; create (no old object) no-op; **missing handler FAILS LOUDLY**.

## 6. Verify

- [x] 6.1 PHPCS + PHPMD (baseline) + PHPStan clean on all new files; PHP 8.3 container fresh composer install.
- [x] 6.2 Full Unit suite: baseline 14705 (10 err / 16 fail) → 14724 (10 err / 16 fail), identical failing names, +19 new all green — zero regression.
