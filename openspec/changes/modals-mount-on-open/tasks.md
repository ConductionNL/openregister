# Tasks

## 1. Mount on open

- [x] 1.1 `Modals.vue`: the 15 always-mounted modals mount only while their
      navigation key is set, as async components.
- [x] 1.2 `objectUsageGuard`: initialise the dialog in `mounted()` when it
      mounts open (DeleteSchema, DeleteSchemaProperty).

## 2. Verification

- [x] 2.1 Unit tests: `Modals.spec.js` (no modal mounted when nothing is open;
      each modal only on its own key; unmounts on close),
      `objectUsageGuard.spec.js`.
- [x] 2.2 Live on the sandbox reference stack: bytes and timings before and
      after, in the PR body.
