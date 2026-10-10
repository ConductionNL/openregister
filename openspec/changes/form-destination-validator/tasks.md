# Tasks: form-destination-validator

## 1. Validator

- [ ] 1.1 `lib/Service/Form/FormDestinationValidator.php` with the finding codes; `TaskFormReader::validate` delegates to it
  - Spec ref: specs/form-destination/spec.md, "OpenRegister MUST judge a form's mapping against its destination schema"
  - Test: `tests/Unit/Service/Form/FormDestinationValidatorTest.php`, one case per code plus a zero-findings control
- [ ] 1.2 Read `x-openregister.serverSet` and `x-openregister.confirmation` in the schema property dialect; document both in the schema standards (ADR-011 table)
- [ ] 1.3 `POST /api/forms/validate` (authenticated, body: mapping and destination) so buildiq and portaliq can show findings while authoring; refuses from the first release, no report mode (decision 181)

## 2. Submit

- [ ] 2.1 `lib/Service/Form/FormSubmitService.php`: full validation regardless of hard-validation flag, create under the subject's RBAC, confirmation map read after listeners
  - Test: unit test with a fake creating listener; a control proving the field is absent when the marker is absent
- [ ] 2.2 All-or-none for several writes, with audited compensation
- [ ] 2.3 Idempotency store (24 h) keyed by form and key
- [ ] 2.4 `POST /api/forms/{formId}/submit` route, `#[PublicPage]`, ADR-082 attributes and `registerAttempt`; routes.php entry
- [ ] 2.5 `ObjectsController::create` answers 422 in the update error shape

## 2b. Lifecycle status `draft` (decision 180)

- [ ] 2b.1 Stored `@self.status` in object metadata (`draft`, `active`); objects without it keep the date-deduced status
  - Spec ref: specs/form-destination/spec.md, "An object MUST be able to carry the explicit lifecycle status `draft`"
  - Test: unit tests for each scenario, including the legacy read control
- [ ] 2b.2 Draft validation skips `required` only; the draft-to-active move goes through `FormSubmitService` with full validation and receipt effects
- [ ] 2b.3 Flagged to Ruben, not built here: staff list default for drafts, draft expiry

## 3. Uploads

- [ ] 3.1 Upload token endpoint and claim in submit
- [ ] 3.2 Purge job (`TimedJob`, hourly), logs the count

## 4. Schema save

- [ ] 4.1 On schema save, re-validate dependent published forms; apply `formDestinationBreak`; list affected forms in the response; notify authors

## 5. Journey registry

- [ ] 5.1 Apply the MODIFIED requirement to `or-form-and-journey-registry` (`journeyRun` replaced by draft destination objects; commit through `FormSubmitService`)

## 6. Verification

- [ ] 6.1 `composer check:strict`, `npm run lint`, `openspec validate form-destination-validator --strict`
