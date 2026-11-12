# Tasks: or-form-and-journey-registry

> Schemas + run API + formats + retention job (ADR-032 `kind: mixed`).
> Checkbox budget: 15 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Declare the forms register
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-body-must-validate-through-the-canonical-manifest-validator`
- **files**: `lib/Settings/forms_register.json`, `lib/Service/FormValidationService.php`, `tests/Unit/Service/FormValidationServiceTest.php`
- **acceptance_criteria**:
  - `form.config` is validated by the SAME `validateManifestV2()` entry point an app manifest uses — asserted by feeding one fixture through both paths and comparing the error objects, not by reading the code
  - `journey` declares `steps[]` (`form`|`review`|`confirmation`), `next` rules `$ref`ing the canonical `$defs.visibleWhen`, per-step `writes[]`, and a REQUIRED `access`
  - A `writes[]` mapping naming a property absent from the target schema is rejected at author time, naming the property and schema
  - A journey with no `access` is rejected; access is never inferred
- [ ] 1.1 Vendor `app-manifest-v2.schema.json` from `@conduction/nextcloud-vue` (`src/schemas/`) into `lib/Settings/schemas/` with a sync script `scripts/sync-manifest-schema.sh`, and validate `form.config` against it in `lib/Service/Form/FormBodyValidator.php` with the Opis validator already used by `ValidateObject`.
- [ ] 1.2 Add the `form` and `journey` schemas to a new `lib/Settings/forms_register.json` (journey: `steps[]`, `next` rules `$ref`ing `$defs.visibleWhen`, per-step `writes[]`, required `access`); refuse a `writes[]` property absent from the target schema at save, naming property and schema, through `lib/Service/Form/FormDestinationValidator.php`.
- [ ] 1.3 Red first: `tests/Unit/Service/Form/FormBodyValidatorTest.php` feeds one fixture through the PHP path and, in `tests/Unit/Service/Form/fixtures/`, records the error objects `validateV2.cjs` emits for it, and asserts they match; plus a journey without `access` and an unknown `writes[]` property are refused.

### Task 2: Journey run service: committed writes through FormSubmitService
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-run-must-store-no-written-ids-and-must-commit-a-step-all-or-none`
- **files**: `lib/Service/JourneyRunService.php`, `tests/Unit/Service/JourneyRunServiceTest.php`
- **acceptance_criteria**:
  - No target-register object exists until a step declaring `writes[]` commits, proven by querying the target register mid-run; there is no `journeyRun`: a saved journey is its destination objects in status `draft` (decisions 179, 180)
  - Writes go through `FormSubmitService::submitAll` (form-destination-validator), in declared order; a later entry resolves a preceding entry's id with `{ "$write": "<as>" }`
  - A mid-`writes[]` refusal deletes the earlier writes of that step before the response (all or none)
  - An answer for a field outside the current step is refused — the client does not select its own scope
- [ ] 2.1 `lib/Service/Form/JourneyRunService.php`: hold a run as its destination objects in status `draft` (decisions 179, 180), commit a step's `writes[]` through `FormSubmitService::submitAll()` in declared order, resolve `{ "$write": "<as>" }` to a preceding entry's id, and delete the step's earlier writes when a later one is refused.
- [ ] 2.2 Red first: `tests/Unit/Service/Form/JourneyRunServiceTest.php` queries the target register mid-run (no object before the writing step commits), forces a refusal on the second write (the first is gone afterwards), and submits an answer for a later step's field (refused).

### Task 3: Run controller — start, answer, resume, submit
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle`
- **files**: `lib/Controller/JourneyRunController.php`, `appinfo/routes.php`, `tests/Unit/Controller/JourneyRunControllerTest.php`
- **acceptance_criteria**:
  - Every route declares its auth posture explicitly (route-auth gate); the anonymous routes carry `#[PublicPage]` in the attribute form the throttling sweep actually counts
  - Resume works by account (authenticated) and by unguessable single-purpose token (anonymous); a token/run mismatch and an unknown run return IDENTICAL responses — asserted by comparing the full response, not the status code alone
  - Anonymous writes stamp NO subject or organisation ownership
  - Anonymous endpoints are throttled, and the throttle is proven by two independent discriminators — an absent success is not evidence
- [ ] 3.1 `lib/Controller/JourneyRunController.php` with `start`, `answer`, `resume` and `submit`, routed in `appinfo/routes.php` under `/api/journeys/{journeyId}/runs`; authenticated routes `#[NoAdminRequired]`, anonymous ones `#[PublicPage]` plus `#[AnonRateLimit]` and a `ThrottledSurfaces` entry checked through `IThrottler` like `FormsController::submit()`; anonymous writes stamp no owner or organisation.
- [ ] 3.2 Resume by account, or by a random single-purpose token stored hashed on the draft (`FormIdempotencyStore` style); a token for another run and an unknown run return the same body, status and headers.
- [ ] 3.3 Red first: `tests/Unit/Controller/JourneyRunControllerTest.php` compares the full mismatch and unknown responses, asserts no `_owner`/`_organisation` on an anonymous write, and proves the throttle with the throttler's attempt count and a 429 after the limit.

### Task 4: Named formats replacing the app-local forks
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-named-formats-must-replace-the-app-local-validator-forks`
- **files**: `lib/Formats/EmailFormat.php`, `lib/Formats/WebsiteFormat.php`, `lib/Formats/NlPhoneFormat.php`, `tests/Unit/Formats/*Test.php`
- **acceptance_criteria**:
  - Behaviour is pinned against the cases `tilburg-woo-ui/src/views/ac-forms/validation/form-validations.js` handles today, including its explicit rejections (`www.nl`, `http://.nl`, hyphen-only labels) and its `06`/`+31` phone handling
  - The API rejects exactly what the UI rejects — asserted by submitting the same corpus through both paths
  - A format-rejected value never reaches the target register
- [ ] 4.1 `lib/Formats/EmailFormat.php`, `lib/Formats/WebsiteFormat.php` and `lib/Formats/NlPhoneFormat.php`, registered beside `BsnFormat` where the validator registers its formats.
- [ ] 4.2 Red first: `tests/Unit/Formats/EmailFormatTest.php`, `WebsiteFormatTest.php`, `NlPhoneFormatTest.php` pinned to the corpus of `tilburg-woo-ui/src/views/ac-forms/validation/form-validations.js` (copied as a fixture), including `www.nl`, `http://.nl`, hyphen-only labels, `06` and `+31`; the same corpus submitted through `forms#submit` is refused identically.

### Task 5: Retention job for journey runs
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-expired-runs-must-be-purged-and-the-purge-must-report-its-work`
- **files**: `lib/BackgroundJob/JourneyRunRetentionJob.php`, `tests/Unit/BackgroundJob/JourneyRunRetentionJobTest.php`
- **acceptance_criteria**:
  - Deletes expired runs and their staged uploads; REPORTS the count deleted, so a zero-row run is distinguishable from a job that never executed
  - A deletion failure is surfaced, NOT caught and discarded — the audit-purge failure mode was a `catch` that swallowed the throw while the job reported success
  - First execution against seeded expired runs is verified by counting rows before and after, not by the job's own success return
  - Registered as a background job, never on the install hook
- [ ] 5.1 `lib/BackgroundJob/JourneyRunRetentionJob.php` (a `TimedJob` in `appinfo/info.xml` `<background-jobs>`, beside `FormUploadPurgeJob`): delete expired draft runs and their staged uploads through `FormUploadStore`, log the count deleted, and let a deletion failure throw.
- [ ] 5.2 Red first: `tests/Unit/BackgroundJob/JourneyRunRetentionJobTest.php` counts seeded expired runs before and after, asserts the logged count, and asserts a failing delete surfaces instead of being swallowed.

### Task 6: Change an existing record through a form link
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008`
- **files**: `lib/Db/AccessLink.php`, `lib/Service/Form/JourneyRunService.php`, `lib/Controller/AccessLinkPageController.php`, `src/views/accessLink/AccessLinkPage.vue`
- **acceptance_criteria**:
  - only a link with the `edit` capability on one object of the journey's schema starts an update run
  - the run is prefilled and its submit updates that object through the normal save path, with the link as actor in the audit trail
- [ ] 6.1 Add `CAP_EDIT` to `AccessLink::CAPABILITIES` in `lib/Db/AccessLink.php`, accepted only together with a `journey` id whose `mode` is `update` and whose target schema is the link's subject schema.
- [ ] 6.2 `JourneyRunService::startFromLink()`: prefill the declared fields from the object, refuse answers for undeclared fields, and submit through `ObjectService::saveObject()` as an update; red first in `tests/Unit/Service/Form/JourneyRunServiceTest.php`.
- [ ] 6.3 Render the journey on the public link page (`AccessLinkPageController`, `AccessLinkPage.vue`) when the link carries `edit`; `tests/e2e/ci/form-update-link.spec.ts` changes a field through the link and reads the audit entry.
