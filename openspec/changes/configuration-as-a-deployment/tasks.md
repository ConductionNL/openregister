# Tasks: configuration-as-a-deployment

Shipped in two parts. The first PR is this file's sections 1, 2, 3, 6 and 7
minus the four boxes named below; it is complete on its own, because a draft
that can be previewed, deployed and rolled back is a working lifecycle whether
or not bundles exist yet. Sections 4 and 5, and task 1.3, continue on
`feat/configuration-bundles-and-seeding`.

## 1. Drafts

- [x] 1.1 A draft value beside the live value, keyed the same way (D-1).
- [x] 1.2 A draft set with an author, and an optional requirement that the approver differs.
- [ ] 1.3 Every settings domain handler accepts a draft write. **Part two.** The
  facade (`SettingsService`) has ten `update*` methods delegating to six
  handlers, and routing each through the draft gate changes the behaviour of
  every existing settings endpoint. It belongs in its own PR with its own
  regression test that an instance which never drafts writes straight through
  (6.3), not appended to the PR that introduces the lifecycle.

## 2. Deployment and rollback

- [x] 2.1 A deployment applying a draft set in one transaction, with name, author, approver, time and changed values (D-2).
- [x] 2.2 A deployment that cannot apply every value applies none and names the refusal (D-2).
- [x] 2.3 A rollback as a new deployment naming what it restores; history stays append-only (D-3).

## 3. The explainer

- [x] 3.1 Effective value, layer and last deployment for any setting (D-4).
- [x] 3.2 Layers ordered instance, register, bundle, subject.
- [x] 3.3 A value predating the first deployment is answered as such, never as unknown.

## 4. Bundles and inheritance

**Part two.** The layered value store, the layer vocabulary and the explainer's
chain all carry the bundle layer already, so a bundle is a binding to write
rather than a model to add.

- [ ] 4.1 A named bundle of permissions, notification rules and lifecycle settings, bound to many schemas (D-5).
- [ ] 4.2 A subject override recorded and listed as an exception.
- [ ] 4.3 Integration configuration inherited from instance or register, overridable, explained.
- [ ] 4.4 A transition or permission matrix copied onto another role or schema as a draft (D-6).

## 5. Seeding

- [ ] 5.1 The repair-time seed available as an administered action on a running instance, writing drafts. **Part two**, with 1.3: both are the settings surface adopting the lifecycle rather than the lifecycle itself.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/configuration-deployment.spec.ts`: draft, refusal, deploy, explain, roll back. Four scenarios carry an e2e anchor; two carry an `@e2e exclude` naming the unit test that asserts them and the reason the HTTP door does not exist.
- [x] 6.2 Unit tests: the all-or-nothing apply, the append-only history, the four layers of the explainer, the stale-draft refusal and the reserved keys. 70 tests. Two mutation checks recorded in the PR body.
- [ ] 6.3 A regression test that an instance never drafting writes straight through. **Part two**, with 1.3: there is no draft write path through the settings facade yet, so the regression has nothing to regress against.
- [x] 6.4 `openspec validate configuration-as-a-deployment --strict`.

## 7. Hand over and findings

- [x] 7.1 Hand the deployment lifecycle to the dossiq lane for `CaseTypePublishService`, with the eleven candidate ids. The contract is in the PR body.
- [x] 7.2 Raise with the buildiq lane that `app-delta-override`, which the build plan names as this cluster's vehicle, names no spec and no change in openregister's openspec tree. Re-checked on this branch: the only three hits are the proposals raising the finding, and `openspec/specs/` and `openspec/changes/` hold no directory of that name.
- [x] 7.3 Tell the D15 lane that C-configuration-19, the second environment, stays blocked until CT-7 fixes the export stub.
