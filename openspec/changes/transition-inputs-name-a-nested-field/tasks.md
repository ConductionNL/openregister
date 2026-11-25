# Tasks

- [x] 1.1 `TransitionInputPaths`: unfold, undeclared, missing and merge over declared paths
  - `lib/Service/Lifecycle/TransitionInputPaths.php`; test `tests/Unit/Service/Lifecycle/TransitionInputPathsTest.php`
- [x] 1.2 `TransitionEngine::acceptInputs()` reads the declaration as paths; the static transition write merges through `TransitionInputPaths::merge()`
  - tests `TransitionEngineInputsTest::testDottedInputAcceptsTheNestedPayload`, `testDottedInputRefusesAMissingOrUndeclaredChild`
- [ ] 2.1 Archive this change with its spec synced into `openspec/specs/object-lifecycle`.
