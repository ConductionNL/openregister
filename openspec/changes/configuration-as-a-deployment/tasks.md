# Tasks: configuration-as-a-deployment

Shipped in two parts. The first PR is this file's sections 1, 2, 3, 6 and 7
minus the four boxes named below; it is complete on its own, because a draft
that can be previewed, deployed and rolled back is a working lifecycle whether
or not bundles exist yet. Sections 4 and 5, and task 1.3, continue on
`feat/configuration-bundles-and-seeding`.

## 1. Drafts

- [x] 1.1 A draft value beside the live value, keyed the same way (D-1).
- [x] 1.2 A draft set with an author, and an optional requirement that the approver differs.
- [x] 1.3 Every settings domain handler accepts a draft write. All ten `update*`
  methods on `SettingsService` ask `SettingsDraftGate` first. With drafting off,
  which is the default, the gate answers null and the facade writes straight
  through. The gate stages the payload merged over the live value rather than a
  normalised blob, so the handlers' defaults stay in one place; the control test
  runs the real handler and pins that the two paths read back the same.

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

- [x] 4.1 A named bundle of permissions, notification rules and lifecycle
  settings, bound to many schemas (D-5). The values are rows at the bundle layer
  of `openregister_config_values`, which already existed. The only new table is
  `openregister_config_bindings`, and its unique index is on the subject: a
  subject follows one bundle, because the chain has one bundle slot.
- [x] 4.2 A subject override recorded and listed as an exception.
  `bindingsOf()` names every bound subject that answers one of the bundle's keys
  for itself, and the key it answers. The override wins by precedence, which the
  same test asserts through the explainer.
- [x] 4.3 Integration configuration inherited from instance or register,
  overridable, explained. The explainer resolves a subject's bundle from its
  binding, so a caller naming only the schema gets the whole chain. A caller
  that had to pass the bundle could pass the wrong one and get a confident
  answer about a bundle the subject never followed.
- [x] 4.4 A transition or permission matrix copied onto another role or schema
  as a draft (D-6). `copyMatrix()` drafts every key under a prefix onto the
  target and writes no live value. A prefix not ending in a dot is refused,
  because "permission" would also take "permissions_legacy" and a copy that
  takes more than it was asked for cannot be reviewed against what was asked.

## 5. Seeding

- [ ] 5.1 The repair-time seed available as an administered action on a running instance, writing drafts. **Part two**, with 1.3: both are the settings surface adopting the lifecycle rather than the lifecycle itself.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/configuration-deployment.spec.ts`: draft, refusal, deploy, explain, roll back. Four scenarios carry an e2e anchor; two carry an `@e2e exclude` naming the unit test that asserts them and the reason the HTTP door does not exist.
- [x] 6.2 Unit tests: the all-or-nothing apply, the append-only history, the four layers of the explainer, the stale-draft refusal and the reserved keys. 70 tests. Two mutation checks recorded in the PR body.
- [x] 6.3 A regression test that an instance never drafting writes straight
  through. `tests/Unit/Service/SettingsStraightThroughTest.php` runs all ten
  update methods twice, with no gate wired and with a gate whose drafting is
  off, and a third time with drafting on so a method that forgot to ask the gate
  is visible. Two mutation checks in the PR body.
- [x] 6.4 `openspec validate configuration-as-a-deployment --strict`.

## 7. Hand over and findings

- [x] 7.1 Hand the deployment lifecycle to the dossiq lane for `CaseTypePublishService`, with the eleven candidate ids. The contract is in the PR body.
- [x] 7.2 Raise with the buildiq lane that `app-delta-override`, which the build plan names as this cluster's vehicle, names no spec and no change in openregister's openspec tree. Re-checked on this branch: the only three hits are the proposals raising the finding, and `openspec/specs/` and `openspec/changes/` hold no directory of that name.
- [x] 7.3 Tell the D15 lane that C-configuration-19, the second environment, stays blocked until CT-7 fixes the export stub.
