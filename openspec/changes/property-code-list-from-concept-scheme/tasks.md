# Tasks: property-code-list-from-concept-scheme

## 1. Schema and validation

- [x] 1.1 `x-openregister-concepts` in the schema validator; refuse beside `enum`.
  - **ALREADY BUILT, AND FOUND BY LOOKING RATHER THAN BY THE BRANCH CHECK.**
    `gh pr list` and `git ls-remote` for this slug found nothing, and the code
    was there anyway: `lib/Service/Vocabulary/` ships
    `CodedPropertyDeclaration`, its factory, `CodedValueGuard`,
    `CodedOptionsBuilder`, `CodedFilterExpander` and
    `CodedValueValidationListener`, with unit tests, shipped by
    `code-list-lifecycle-and-hierarchy` (#3725). Checking for a PR is not
    checking for the feature.
  - WHAT THIS PASS ADDED is the half that arrived after it: openregister#3883
    published `conceptScheme` as a vocabulary modifier, because that is what an
    extending form can forward and what a case-type editor writes. Two
    spellings of one binding, from two directions, neither knowing about the
    other. The factory now reads BOTH into one declaration, so the validator,
    the option builder and the filter expander cannot disagree about which
    property is coded, and `competingSpellings()` reports a property carrying
    both rather than resolving it by a precedence nobody knows.
  - DONE 2026-09-18 in a second pass: `CodedChoiceDeclaration::assert()`, called
    from `PropertyValidatorHandler::validateProperty()` beside the generated
    identifier guard and throwing in the same exception family, so every
    schema-save path answers it as a 422 naming the property without learning
    about the annotation. It refuses a scheme beside a literal `enum`, and a
    property declaring BOTH spellings of the binding, which also gives
    `competingSpellings()` the production caller #3887 left it without.
  - `@spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md`
- [x] 1.2 Value-in-scheme check in `ValidationHandler` through the concept resolution API, cached per (scheme, version) per request.
  - Built by `code-list-lifecycle-and-hierarchy`: `CodedValueGuard` behind
    `CodedValueValidationListener`. This pass only widened what counts as a
    coded property, so a field declaring the simple spelling is now checked by
    the guard that was already there rather than saving any value at all.

## 2. Read side

- [x] 2.1 Bounded options on the schema read with negotiated labels; `@self.labels` on `_extend`; facet labels.
  - Built by `code-list-lifecycle-and-hierarchy`: `CodedOptionsBuilder` behind
    `VocabularyController::propertyOptions()`. A property declaring the simple
    spelling reaches it through the same factory, so its options are offered
    without any change here.

## 3. Tests

- [x] 3.1 `tests/e2e/ci/concept-code-list.spec.ts`: declare a property on a scheme, pick a value in the form, save.
  - Written and tagged, NOT RUN: there is no Playwright runner on the build
    host, so the nightly owns it. It follows `code-list-lifecycle.spec.ts`,
    including the per-run uri prefix and the teardown that re-resolves by it.
  - IT WRITES BOTH SPELLINGS, on two properties of one schema. A spec writing
    only one would pass while the other silently stopped being read, which is
    the failure the one-reader factory exists to prevent.
  - The control runs first and asks the VOCABULARY endpoint for the key. Every
    other assertion is about a key that endpoint has to name; without it the
    suite measures a key only this spec believes in.
  - The options route is `/api/vocabulary/options`, read out of
    `appinfo/routes.php` rather than guessed from the controller: the method is
    `propertyOptions` and the URL is not.
- [ ] 3.2 Unit tests for the validator, the check, deprecation, options paging and labels.

## 4. Discovery wave 1 (CT-4)

- [x] 4.1 Refuse a choice property with an empty `enum` and no concept scheme, naming the property.
  - **ALREADY REFUSED, AND A SECOND REFUSAL WAS WRITTEN AND REMOVED.**
    `PropertyValidatorHandler` throws "'enum' at '<path>' must be a non-empty
    array", with `testValidatePropertyRejectsEmptyEnum` on the sentence. A
    refusal added in `CodedChoiceDeclaration` shadowed it with different words
    for one defect, which is how an app ends up with two error messages for one
    mistake. Found by running the suite, not by reading the file: the new
    refusal threw first and the existing test failed on the wrong sentence.
- [ ] 4.2 Hand the editor half to the dossiq lane for `code-lists-from-concepts`: the Properties tab has no input for `enumValues`, study row A4.
- [ ] 4.3 Record that the B3 half, options narrowed by another property's value, is carried by `code-list-lifecycle-and-hierarchy` REQ-CLH-002.
