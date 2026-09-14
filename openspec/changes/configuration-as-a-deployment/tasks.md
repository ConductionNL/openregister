# Tasks: configuration-as-a-deployment

## 1. Drafts

- [ ] 1.1 A draft value beside the live value, keyed the same way (D-1).
- [ ] 1.2 A draft set with an author, and an optional requirement that the approver differs.
- [ ] 1.3 Every settings domain handler accepts a draft write.

## 2. Deployment and rollback

- [ ] 2.1 A deployment applying a draft set in one transaction, with name, author, approver, time and changed values (D-2).
- [ ] 2.2 A deployment that cannot apply every value applies none and names the refusal (D-2).
- [ ] 2.3 A rollback as a new deployment naming what it restores; history stays append-only (D-3).

## 3. The explainer

- [ ] 3.1 Effective value, layer and last deployment for any setting (D-4).
- [ ] 3.2 Layers ordered instance, register, bundle, subject.
- [ ] 3.3 A value predating the first deployment is answered as such, never as unknown.

## 4. Bundles and inheritance

- [ ] 4.1 A named bundle of permissions, notification rules and lifecycle settings, bound to many schemas (D-5).
- [ ] 4.2 A subject override recorded and listed as an exception.
- [ ] 4.3 Integration configuration inherited from instance or register, overridable, explained.
- [ ] 4.4 A transition or permission matrix copied onto another role or schema as a draft (D-6).

## 5. Seeding

- [ ] 5.1 The repair-time seed available as an administered action on a running instance, writing drafts.

## 6. Tests

- [ ] 6.1 `tests/e2e/ci/configuration-deployment.spec.ts`: draft, review refusal, deploy, explain, roll back.
- [ ] 6.2 Unit tests: the all-or-nothing apply, the append-only history, the four layers of the explainer, the bundle binding and its override, the copied matrix landing as a draft.
- [ ] 6.3 A regression test that an instance never drafting writes straight through.
- [ ] 6.4 `openspec validate configuration-as-a-deployment --strict`.

## 7. Hand over and findings

- [ ] 7.1 Hand the deployment lifecycle to the dossiq lane for `CaseTypePublishService`, with the eleven candidate ids.
- [ ] 7.2 Raise with the buildiq lane that `app-delta-override`, which the build plan names as this cluster's vehicle, returns zero hits in openregister's openspec tree.
- [ ] 7.3 Tell the D15 lane that C-configuration-19, the second environment, stays blocked until CT-7 fixes the export stub.
