# Tasks: or-form-and-journey-registry

> Schemas + run API + formats + retention job (ADR-032 `kind: mixed`).
> Checkbox budget: 5 tasks × 2 = 10 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Declare the forms register
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-body-must-validate-through-the-canonical-manifest-validator`
- **files**: `lib/Settings/forms_register.json`, `lib/Service/FormValidationService.php`, `tests/Unit/Service/FormValidationServiceTest.php`
- **acceptance_criteria**:
  - `form.config` is validated by the SAME `validateManifestV2()` entry point an app manifest uses — asserted by feeding one fixture through both paths and comparing the error objects, not by reading the code
  - `journey` declares `steps[]` (`form`|`review`|`confirmation`), `next` rules `$ref`ing the canonical `$defs.visibleWhen`, per-step `writes[]`, and a REQUIRED `access`
  - A `writes[]` mapping naming a property absent from the target schema is rejected at author time, naming the property and schema
  - A journey with no `access` is rejected; access is never inferred
- [ ] Implement
- [ ] Test

### Task 2: Journey run service — staged answers, committed writes
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-the-run-api-must-stage-answers-and-commit-only-at-declared-steps`
- **files**: `lib/Service/JourneyRunService.php`, `tests/Unit/Service/JourneyRunServiceTest.php`
- **acceptance_criteria**:
  - Answers persist to the `journeyRun`; no target-register object exists until a step declaring `writes[]` commits — proven by querying the target register mid-run
  - Writes execute in declared order; a later entry resolves a preceding entry's id
  - A mid-`writes[]` failure records the failure AND the already-written id; re-submitting UPDATES that object rather than creating a second
  - An answer for a field outside the current step is refused — the client does not select its own scope
- [ ] Implement
- [ ] Test

### Task 3: Run controller — start, answer, resume, submit
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle`
- **files**: `lib/Controller/JourneyRunController.php`, `appinfo/routes.php`, `tests/Unit/Controller/JourneyRunControllerTest.php`
- **acceptance_criteria**:
  - Every route declares its auth posture explicitly (route-auth gate); the anonymous routes carry `#[PublicPage]` in the attribute form the throttling sweep actually counts
  - Resume works by account (authenticated) and by unguessable single-purpose token (anonymous); a token/run mismatch and an unknown run return IDENTICAL responses — asserted by comparing the full response, not the status code alone
  - Anonymous writes stamp NO subject or organisation ownership
  - Anonymous endpoints are throttled, and the throttle is proven by two independent discriminators — an absent success is not evidence
- [ ] Implement
- [ ] Test

### Task 4: Named formats replacing the app-local forks
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-named-formats-must-replace-the-app-local-validator-forks`
- **files**: `lib/Formats/EmailFormat.php`, `lib/Formats/WebsiteFormat.php`, `lib/Formats/NlPhoneFormat.php`, `tests/Unit/Formats/*Test.php`
- **acceptance_criteria**:
  - Behaviour is pinned against the cases `tilburg-woo-ui/src/views/ac-forms/validation/form-validations.js` handles today, including its explicit rejections (`www.nl`, `http://.nl`, hyphen-only labels) and its `06`/`+31` phone handling
  - The API rejects exactly what the UI rejects — asserted by submitting the same corpus through both paths
  - A format-rejected value never reaches the target register
- [ ] Implement
- [ ] Test

### Task 5: Retention job for journey runs
- **spec_ref**: `openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-expired-runs-must-be-purged-and-the-purge-must-report-its-work`
- **files**: `lib/BackgroundJob/JourneyRunRetentionJob.php`, `tests/Unit/BackgroundJob/JourneyRunRetentionJobTest.php`
- **acceptance_criteria**:
  - Deletes expired runs and their staged uploads; REPORTS the count deleted, so a zero-row run is distinguishable from a job that never executed
  - A deletion failure is surfaced, NOT caught and discarded — the audit-purge failure mode was a `catch` that swallowed the throw while the job reported success
  - First execution against seeded expired runs is verified by counting rows before and after, not by the job's own success return
  - Registered as a background job, never on the install hook
- [ ] Implement
- [ ] Test
