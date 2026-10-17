# Tasks: form-destination-validator

## 1. Validator

- [x] 1.1 `lib/Service/Form/FormDestinationValidator.php` with the finding codes; `TaskFormReader::validate` delegates to it
  - Spec ref: specs/form-destination/spec.md, "OpenRegister MUST judge a form's mapping against its destination schema"
  - Test: `tests/Unit/Service/Form/FormDestinationValidatorTest.php`, one case per code plus a zero-findings control
- [x] 1.2 Read `x-openregister.serverSet` and `x-openregister.confirmation` in the schema property dialect; document both (plus `x-openregister.reference`) in `docs/features/form-destination.md`; the hydra ADR-011 table row is owed in hydra#748
- [x] 1.3 `POST /api/forms/validate` (authenticated, body: mapping and destination) so buildiq and portaliq can show findings while authoring; refuses from the first release (Q9 answer: no report mode). `FormsController::validate`, `tests/Unit/Controller/FormsControllerTest.php`

## 2. Submit

- [x] 2.1 `lib/Service/Form/FormSubmitService.php`: full validation regardless of hard-validation flag, create under the subject's RBAC, confirmation map read after listeners
  - Test: unit test with a fake creating listener; a control proving the field is absent when the marker is absent
- [x] 2.2 All-or-none for several writes, with audited compensation
- [x] 2.3 Idempotency store (24 h) keyed by form and key
- [x] 2.4 `POST /api/forms/{formId}/submit` route, `#[PublicPage]`, ADR-082 attributes and `registerAttempt`; routes.php entry
- [x] 2.5 `ObjectsController::create` answers in the update error shape, through the same handler and status update uses (400; 422 for all three is Q-openregister-F1)

## 2b. Lifecycle status `draft` (decision 180)

- [x] 2b.1 Stored `@self.status` in object metadata (`draft`, `active`); objects without it keep the date-deduced status
  - Spec ref: specs/form-destination/spec.md, "An object MUST be able to carry the explicit lifecycle status `draft`"
  - Test: unit tests for each scenario, including the legacy read control
- [x] 2b.2 Draft validation skips `required` only; the draft-to-active move goes through `FormSubmitService` with full validation and receipt effects
- [x] 2b.4 A draft may be owned by a scope value `<scope>:<reference>` such as `portal:<subjectRef>` (decision 195); only a caller asserting that owner finds, reads, saves or sends it, anyone else gets 404
  - Spec ref: specs/form-destination/spec.md, "A draft MUST be able to be owned by a scope value"
  - Test: DraftOwnerTest, FormSubmitServiceTest (portal owner cases), SaveObjectSystemOwnerTest (explicit owner)
- [x] 2b.5 The owner may discard its draft; only its own object in `draft` is removed, permanently, anything else is "not found"
  - Spec ref: specs/form-destination/spec.md, "A draft MUST be able to be discarded by its owner"
  - Test: DraftDiscardTest
- [x] 2b.6 Starting a scope-owned draft is authorised by the owner assertion, not by the destination's public create rule; a fresh submit stays under RBAC; no controller can assert an owner
  - Spec ref: specs/form-destination/spec.md, "A draft MUST be able to be owned by a scope value" (scenarios "A scope-owned draft starts where the public may not create", "A request cannot name the owner")
  - Test: FormSubmitServiceTest (scope-owned draft and its control), FormsControllerTest (a request cannot name the owner, no controller asserts one)
- [x] 2b.7 Lists and searches hide drafts by default, keep the caller's own in view, and show them with `_drafts=true` / `_drafts=any` or a `@self[status]` filter (decision 201)
  - Spec ref: specs/form-destination/spec.md, "A list MUST hide drafts unless asked"
  - Test: DraftListLensTest, MagicSearchHandlerDraftLensTest (union path, single-table path, reserved parameter), DraftFinderTest (the finder asks for drafts)
- [ ] 2b.8 Live: on an instance, a draft is absent from `GET /api/objects/{register}/{schema}` for an administrator, present with `_drafts=any`, and its total and facets agree (live pass, decision 139)
- [ ] 2b.3 Answered by Ruben (decision 201), the rest not built here: a draft expires after the owning app's retention (30 days by default); since decision 216 the app declares that as `draftLifetimeDays` on its schema and OpenRegister's one cleanup job enforces it (change `draft-owner-activation-and-lifetime`); a draft gets its case number only when it leaves draft. The list half is 2b.7. Anonymous drafts stay open (Q-openregister-F2)

## 3. Uploads

- [x] 3.1 Upload token endpoint and claim in submit
- [x] 3.2 Purge job (`TimedJob`, hourly), logs the count

## 4. Schema save

- [x] 4.1 On schema save, re-validate dependent published forms; apply `formDestinationBreak`; list affected forms in the response; notify authors

## 5. Journey registry

- [x] 5.1 Apply the MODIFIED requirement to `or-form-and-journey-registry` (`journeyRun` replaced by draft destination objects; commit through `FormSubmitService`); its remaining `journeyRun` text is marked superseded

## 6. Verification

- [ ] 6.1 `composer check:strict`, `npm run lint`, `openspec validate form-destination-validator --strict`
