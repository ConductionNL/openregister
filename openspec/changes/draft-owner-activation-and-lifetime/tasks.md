# Tasks: draft-owner-activation-and-lifetime

## 1. The owner makes their own draft definitive

- [x] 1.1 `DraftActivation::activate()`: read the owner's draft, full validation, one promotion, save as `active` under the same id, `ObjectActivatedEvent`
  - Spec ref: specs/form-destination/spec.md, "A draft's owner MUST be able to make it definitive through the object API"
  - Test: DraftActivationTest (the save is judged by the real `DraftStatusPolicy`; validation is a real schema run)
- [x] 1.2 `DraftActivationRefusedException`: one status per reason, none that reads as success
  - Test: DraftActivationRefusedExceptionTest
- [x] 1.3 `ObjectDraftController::activate` and the route `POST /api/objects/{register}/{schema}/{id}/activate`; a plain `Controller`, the acting user is the owner, 404 for what the caller may not read
  - Test: ObjectDraftControllerTest (route registered, not an OCSController, every refusal keeps its status)
- [x] 1.4 The server-filled excusals move to `DraftStatusPolicy::serverFilledExcusals()`, shared by the form submit and the activation
  - Test: DraftStatusPolicyTest, FormSubmitServiceTest (unchanged, still green)
- [x] 1.5 Newman collection `tests/integration/openregister-draft-activation.postman_collection.json`, registered in `tests/newman/run-all.sh`: the delivered 403, 422, 409, 404 and 200 from a caller's side
- [ ] 1.6 Live: run that collection against an instance (live pass, decision 139); CI's Newman job runs it on the PR

## 2. A schema sets a draft lifetime

- [x] 2.1 Configuration key `draftLifetimeDays` declared in `Schema::validateConfigurationEntry()`; anything but a whole number of days, 1 or more, is dropped and reported
  - Spec ref: specs/form-destination/spec.md, "A schema MUST be able to set a draft lifetime"
  - Test: SchemaFormConfigurationTest
- [x] 2.2 The key survives an import, an export and a second import
  - Test: DraftLifetimeImportExportTest (real `ImportHandler` and `ExportHandler`; an undeclared key as control)
- [x] 2.3 `DraftStatusPolicy::draftLifetimeDays()`
  - Test: DraftStatusPolicyTest

## 3. One cleanup job

- [x] 3.1 `DraftExpiry::sweep()`: per register and schema with a lifetime, list drafts, remove the expired ones through `ObjectService::deleteObject()`
  - Spec ref: specs/form-destination/spec.md, "One background job MUST remove drafts past their schema's lifetime"
  - Test: DraftExpiryTest
- [x] 3.2 `DraftExpiryJob`, daily, declared in `appinfo/info.xml`; `info.xml` version bumped so the declaration is picked up
  - Test: DraftExpiryJobTest (finds the job from `appinfo/info.xml` and runs that class down to the delete; fails without the declaration)
- [ ] 3.3 Live: on an instance, set `draftLifetimeDays` on a schema, age a draft, run `occ background-job:execute` for the job, and read the audit trail of the removed draft (live pass, decision 139)

- [x] 3.4 `ObjectService::deleteObject()`: a stored draft passes the archival delete gate, and a draft's delete is permanent; nothing else passes
  - Spec ref: specs/form-destination/spec.md, "A draft MUST be deletable on every schema, and its delete MUST be permanent"
  - Test: ObjectServiceDraftDeleteTest (each case with its control)

## 4. Lists

- [x] 4.1 `_drafts=none` leaves out every draft, the caller's own included
  - Spec ref: specs/form-destination/spec.md, "A list MUST hide drafts unless asked"
  - Test: DraftListLensTest

## 5. Words

- [x] 5.1 `docs/features/form-destination.md`: the action, its statuses, the setting, the lens
- [x] 5.2 Dutch backend strings in `l10n/nl.json`
- [x] 5.3 `form-destination-validator`'s spec amended where it said only the submit leaves draft; its task 2b.3 ("enforced by that app on read") is superseded by section 3 here

## 6. Verification

- [ ] 6.1 `composer check:strict` once before the push; exit code in the PR body
