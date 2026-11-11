# Tasks: example-set-refresh-trusted-writes

- [x] `ExampleSetStore::writeRules`; `ExampleSetRewriter` keeps kept fields, drops lenses, skips append-only rows
- [x] Store writes via `runAsSystem`; update marks declared state (`LifecycleActionContext`); `LifecycleValidationListener` skips the transition check for it in the system scope
- [x] Installer moves the `#references` record along after a rewrite; `ExampleSetProof` compares JSON text decoded
- [x] Tests (failing first): refresh with kept field, lens, append-only row, references record; store system scope and write rules; listener; proof
