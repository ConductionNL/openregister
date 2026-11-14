# Tasks: anonymising-as-an-archival-outcome

## 1. The fourth action

- [x] 1.1 `anonymiseren` joins the archival action vocabulary and its validator. (verified: lib/Service/Archival/Appraisal.php, lib/Service/Archival/ArchivalDeclarationReader.php, tests/Unit/Service/Archival/AnonymisationTest.php, tests/Unit/Service/Archival/ArchivalDeclarationReaderActionTest.php)
- [ ] 1.2 The nomination deriver can produce it, and a destruction list can carry it.
- [x] 1.3 A reviewer may answer an entry with it, recorded like the other three answers. (verified: lib/Service/Archival/DestructionReviewServiceInterface.php `ANSWER_ANONYMISE`, lib/Service/Archival/ReviewOutcomeService.php, lib/Service/Archival/DestructionService.php, tests/Unit/Service/Archival/DestructionReviewServiceTest.php, ReviewOutcomeServiceTest.php, DestructionWithholdingTest.php)
  - `anonymise` is the fourth review answer: it lands in the list's one decision history with reviewer, moment and reason, changes nothing on the record at answer time (like `destroy`), hands nothing to a transfer list, and withholds the entry from destruction (`uitgezonderd`, the reviewer's reason quoted), so an approved list never destroys a record somebody said to anonymise. Carrying the anonymisation out for such an entry is the wiring of 1.2/3.2, not done here.

## 2. The profile

- [x] 2.1 An anonymisation profile on the schema: per property, remove, fixed value, stable pseudonym or generalise. (verified: lib/Service/Archival/AnonymisationProfile.php, lib/Service/Archival/AnonymisationService.php, tests/Unit/Service/Archival/AnonymisationTest.php)
- [x] 2.2 Schema save refuses a profile naming a property the schema does not declare. (built: RecordAnnotationRefusals::checkAnonymisationProfile wired into SchemaMapper::checkDataDeclarations, via AnonymisationPlanner::refusals; AnonymisationProfileSaveTest)
- [x] 2.2a The profile is also read from `archive.anonymisation` (design D-6), and a profile in both places is refused at save. (built: checkAnonymisationProfile reads both x-openregister-archival.anonymisation and Schema::getArchive()['anonymisation'], refuses a double declaration naming both; AnonymisationProfileSaveTest covers the archive-block profile and the double declaration)
- [x] 2.3 An outcome or result type names the archival action, so the choice is configuration. (built: a result type's `anonymiseren` already normalises to `anonymise` through `Appraisal::CANONICAL`; lib/Service/Archival/AnonymisationCandidates.php turns a due, live record nominated `anonymise` (any spelling) into the sweep's candidate, reads the profile from `x-openregister-archival` or `archive`, carries the hold and an earlier marker so the run refuses them, and never takes a destroy nomination; tests/Unit/Service/Archival/AnonymisationCandidatesTest.php, including a sweep over the derived candidates)

## 3. The act

- [x] 3.1 Execution runs through the existing pseudonymise primitive, per the declared profile. (verified: lib/Service/Archival/AnonymisationService.php, tests/Unit/Service/Archival/AnonymisationTest.php)
- [ ] 3.2 The search index, the history projection and every derived copy are updated in the same act.
- [ ] 3.3 The anonymised values are removed from the audit trail's stored diffs, an entry records the act, and the chain is re-sealed.
- [x] 3.4 A record under a legal hold is not anonymised, and the refusal names the hold. (verified: lib/Service/Archival/AnonymisationRun.php, tests/Unit/Service/Archival/AnonymisationRunTest.php)
- [x] 3.5 A failure to reach a derived copy fails the whole act; nothing is half-anonymised. (verified: lib/Service/Archival/AnonymisationRun.php, tests/Unit/Service/Archival/AnonymisationRunTest.php)

## 4. The report

- [x] 4.1 The act reports the properties changed and the properties deliberately kept. (verified: lib/Service/Archival/AnonymisationService.php, tests/Unit/Service/Archival/AnonymisationTest.php)
- [x] 4.2 The report is readable afterwards from the record and from the run. (verified: lib/Service/Archival/AnonymisationRun.php, tests/Unit/Service/Archival/AnonymisationRunTest.php)

## 5. Tests

- [x] 5.1 Unit tests for each of the four treatments, including the stable pseudonym joining two rows. (verified: tests/Unit/Service/Archival/AnonymisationTest.php)
- [x] 5.2 Unit tests for the legal-hold refusal and the all-or-nothing failure. (verified: tests/Unit/Service/Archival/AnonymisationRunTest.php)
- [ ] 5.3 A test asserting the anonymised value is absent from the search index and the history projection.
- [ ] 5.4 A test asserting the audit chain verifies after the re-seal and that the anonymisation entry is on it.
- [ ] 5.5 Deduplication check (ADR-012) recorded in the PR body.
