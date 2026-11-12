# Tasks: configuration-as-a-deployment

Shipped in two parts. The first PR is this file's sections 1, 2, 3, 6 and 7
minus the four boxes named below; it is complete on its own, because a draft
that can be previewed, deployed and rolled back is a working lifecycle whether
or not bundles exist yet. Sections 4 and 5, and task 1.3, continue on
`feat/configuration-bundles-and-seeding`.

## 1. Drafts

- [x] 1.1 A draft value beside the live value, keyed the same way (D-1).
- [x] 1.2 A draft set with an author, and an optional requirement that the approver differs.
- [ ] 1.3 Every settings domain handler accepts a draft write. **Part two.** A new `lib/Service/ConfigurationDeployment/SettingsDraftGate.php` sits in front of the ten `update*` methods of `lib/Service/SettingsService.php` (and the six handlers under `lib/Service/Settings/` they delegate to). When the request names an open draft set (`draftSet` parameter on the settings routes), each key is written through `ConfigurationDraftService::draftValue()` instead of the handler, using the keys `ConfigurationKeyRegistry` knows. Without a draft set the gate calls the handler unchanged. Its regression test is 6.3.

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

- [ ] 4.1 A named bundle of permissions, notification rules and lifecycle settings, bound to many schemas (D-5): entity `lib/Db/ConfigurationBundle.php` with mapper and a migration (name, description, bound schema ids). Its values are written in `ConfigurationLayer::BUNDLE` with the bundle uuid as layer ref, only through a draft set. Routes `configurationBundle#index`, `#create`, `#update` and `#bind` under `/api/configuration/bundles` in `appinfo/routes.php`, admin only.
- [ ] 4.2 A subject override recorded and listed as an exception: a draft value in `ConfigurationLayer::SUBJECT` for a schema bound to a bundle stores the bundle uuid it overrides. `ConfigurationExplainer::explain()` reports it as `exceptionTo`, and `GET /api/configuration/bundles/{id}/exceptions` lists the overrides of one bundle.
- [ ] 4.3 Integration configuration inherited from instance or register, overridable, explained: keys under `integration.` in `ConfigurationKeyRegistry` resolve instance, then register, then subject in `ConfigurationExplainer::chainFor()`, and the explanation names the layer that won.
- [ ] 4.4 A transition or permission matrix copied onto another role or schema as a draft (D-6): `POST /api/configuration/draft-sets/{id}/copy` with `{from, to[], kind: transition|permission}` reads the source's `authorization` or `x-openregister-lifecycle` block and writes one draft per target in the set, so the copy is previewed and deployed like any other change.

## 5. Seeding

- [ ] 5.1 The repair-time seed available as an administered action on a running instance, writing drafts. **Part two**, with 1.3. A `lib/Service/ConfigurationDeployment/SeedActionProvider.php` interface lets a leaf app expose the payload its repair seeder writes (dossiq's `lib/Repair/Seed.php` first). `POST /api/configuration/seed/{provider}` (admin only) opens a draft set named after the provider and writes the payload through `draftValue()`, so the seed is previewed and deployed, never written live.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/configuration-deployment.spec.ts`: draft, refusal, deploy, explain, roll back. Four scenarios carry an e2e anchor; two carry an `@e2e exclude` naming the unit test that asserts them and the reason the HTTP door does not exist.
- [x] 6.2 Unit tests: the all-or-nothing apply, the append-only history, the four layers of the explainer, the stale-draft refusal and the reserved keys. 70 tests. Two mutation checks recorded in the PR body.
- [ ] 6.3 A regression test that an instance never drafting writes straight through. **Part two**, with 1.3: `tests/Unit/Service/ConfigurationDeployment/SettingsDraftGateTest.php` calls each of the ten `SettingsService::update*` methods without a draft set and asserts the handler wrote the value to `IAppConfig`. A second case with a draft set asserts nothing was written live and one draft per key exists.
- [x] 6.4 `openspec validate configuration-as-a-deployment --strict`.

## 7. Hand over and findings

- [x] 7.1 Hand the deployment lifecycle to the dossiq lane for `CaseTypePublishService`, with the eleven candidate ids. The contract is in the PR body.
- [x] 7.2 Raise with the buildiq lane that `app-delta-override`, which the build plan names as this cluster's vehicle, names no spec and no change in openregister's openspec tree. Re-checked on this branch: the only three hits are the proposals raising the finding, and `openspec/specs/` and `openspec/changes/` hold no directory of that name.
- [x] 7.3 Tell the D15 lane that C-configuration-19, the second environment, stays blocked until CT-7 fixes the export stub.

## 8. Promotion between environments (row op-otap)

Blocked on CT-7, as D15 says: promotion moves configuration as a package, and the case type export still returns an empty package behind a 200. Spec: `openspec/specs/environment-otap/spec.md`, "Configuration promotion MUST transfer settings between OTAP environments".

- [ ] 8.1 `lib/Service/ConfigurationDeployment/PromotionService.php`: export the source organisation's schemas, mappings, sources, webhooks and endpoints through the existing configuration export, and refuse unless the source's `Organisation::environment` comes before the target's in development, test, acceptance, production order (400 naming the order).
- [ ] 8.2 Land the package in the target organisation as a draft set (`ConfigurationDraftService::openSet()` named "Promotion from {source}"), with uuids remapped. The target's preview, approval and deployment are then the ones from sections 1 and 2, and the rollback snapshot is the deployment's own undo record. Write a promotion entry in both organisations' audit trail.
- [ ] 8.3 Route `organisation#promote`, `POST /api/organisations/{uuid}/promote` with `targetOrganisation`, admin only. Red first: `tests/Unit/Service/ConfigurationDeployment/PromotionServiceTest.php` for the order refusal, the remapping and the draft landing.
