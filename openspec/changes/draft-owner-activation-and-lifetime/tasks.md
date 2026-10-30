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

- [x] 2.1 Configuration key `draftLifetimeDays` declared in `Schema::validateConfigurationEntry()`: a whole number of days, 1 or more, or `false` to keep drafts (decision 228); anything else is stored as `false` and reported
  - Spec ref: specs/form-destination/spec.md, "A schema MUST be able to set a draft lifetime"
  - Test: SchemaFormConfigurationTest
- [x] 2.2 The key survives an import, an export and a second import
  - Test: DraftLifetimeImportExportTest (real `ImportHandler` and `ExportHandler`; an undeclared key as control)
- [x] 2.3 `DraftStatusPolicy::draftLifetimeDays()`: 30 days without the key, null for a schema that keeps its drafts
  - Test: DraftStatusPolicyTest
- [x] 2.4 `occ openregister:drafts:expired` (`DraftsExpiredCommand`, declared in `appinfo/info.xml`): the count per schema a run would remove, nothing removed
  - Spec ref: specs/form-destination/spec.md, "An administrator MUST be able to see what the draft cleanup would remove"
  - Test: DraftsExpiredCommandTest
- [x] 2.5 Upgrade note in `CHANGELOG.md` and the PR body: the first run removes drafts older than 30 days

## 3. One cleanup job

- [x] 3.1 `DraftExpiry::sweep()`: per register and schema with a lifetime, list drafts, remove the expired ones through `ObjectService::deleteObject()`; at most 1,000 per run, the rest deferred; a count per schema; `preview()` counts and removes nothing
  - Spec ref: specs/form-destination/spec.md, "One background job MUST remove drafts past their schema's lifetime"
  - Test: DraftExpiryTest
- [x] 3.2 `DraftExpiryJob`, daily, declared in `appinfo/info.xml`; `info.xml` version bumped so the declaration is picked up
  - Test: DraftExpiryJobTest (finds the job from `appinfo/info.xml` and runs that class down to the delete; fails without the declaration)
- [ ] 3.3 Live: on an instance, set `draftLifetimeDays` on a schema, age a draft, run `occ background-job:execute` for the job, and read the audit trail of the removed draft (live pass, decision 139)

- [x] 3.4 `ObjectService::deleteObject()`: a stored draft passes the archival delete gate, and a draft's delete is permanent; nothing else passes
  - Spec ref: specs/form-destination/spec.md, "A draft MUST be deletable on every schema, and its delete MUST be permanent"
  - Test: ObjectServiceDraftDeleteTest (each case with its control)

- [x] 3.5 `AnnotationNotificationListener`: a draft's saves notify nobody; `ObjectActivatedEvent` fires the `created` rules once (registered in `Application`)
  - Spec ref: specs/form-destination/spec.md, "A draft MUST NOT notify anybody until it leaves draft"
  - Test: AnnotationNotificationListenerTest
- [x] 3.6 Moved to the change `draft-creation-side-effects` (decision 227): OpenRegister's other create-time listeners (flow triggers, webhooks, activity, push, metrics) act on a draft's first save and not on `ObjectActivatedEvent`. This change fixes the notification rules only
- [x] 3.7 `DraftRemovalRecord`: the permanent delete of a draft writes one audit-trail entry `draft.removed` through `AuditTrailMapper::createAuditTrailEntry()`, naming the user or the job, never the content (decision 226)
  - Spec ref: specs/form-destination/spec.md, "Removing a draft MUST leave one audit-trail entry without its content"
  - Test: DraftRemovalRecordTest (the real delete handler, with a record that is not a draft as control), DraftRemovalAuditRowTest (the row read back from the migrated audit table), DraftExpiryJobTest (the job as actor)
- [x] 3.8 `DraftActivation`: a stored null is a property nobody filled in, so a missing required property answers `required` and a server-filled one is excused (found live: the table holds null for every empty column)
  - Spec ref: specs/form-destination/spec.md, scenario "A stored empty value is a property nobody filled in"
  - Test: DraftActivationTest::testAStoredNullIsAPropertyNobodyFilledIn
- [x] 3.9 `ObjectService::saveObject()`: a save under RBAC cannot write over a draft its caller cannot read (found live: PATCH and a POST naming the id changed another user's draft)
  - Spec ref: specs/form-destination/spec.md, "A draft MUST NOT be changed by somebody who cannot read it"
  - Test: ObjectServiceDraftWriteTest (each refusal with its controls), DraftStatusPolicyTest::testADraftIsKeptFromEverybodyButItsOwner, the Newman collection's colleague steps
- [x] 3.10 `ObjectDraftController`: the register and the schema are looked up past the organisation filter, as `ObjectService::setRegister()` does (found in CI: the owner's activation answered 404 once openregister-crud had switched multitenancy on)
  - Spec ref: specs/form-destination/spec.md, scenario "The register of another organisation does not hide the owner's draft"
  - Test: ObjectDraftControllerTest::testTheRegisterOfAnotherOrganisationIsFound

## 4. Lists

- [x] 4.1 `_drafts=none` leaves out every draft, the caller's own included
  - Spec ref: specs/form-destination/spec.md, "A list MUST hide drafts unless asked"
  - Test: DraftListLensTest
- [x] 4.2 Schema configuration `draftsListed: false` makes that the default for the schema's lists, counts and facets, on both query paths; it survives import and export
  - Spec ref: specs/form-destination/spec.md, "A schema MUST be able to keep the caller's own drafts out of its lists"
  - Test: DraftListLensTest, MagicSearchHandlerDraftLensTest, DraftStatusPolicyTest, SchemaFormConfigurationTest, DraftLifetimeImportExportTest

## 5. Words

- [x] 5.1 `docs/features/form-destination.md`: the action, its statuses, the setting, the lens
- [x] 5.2 Dutch backend strings in `l10n/nl.json`
- [x] 5.3 `form-destination-validator`'s spec amended where it said only the submit leaves draft; its task 2b.3 ("enforced by that app on read") is superseded by section 3 here

## 6. Verification

- [ ] 6.1 `composer check:strict` once before the push; exit code in the PR body
