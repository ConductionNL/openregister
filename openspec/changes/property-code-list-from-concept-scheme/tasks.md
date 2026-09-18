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
  - STILL OPEN: refusing the annotation beside a literal `enum`. The two are
    both readable today and nothing reports the pair.
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

- [ ] 3.1 `tests/e2e/ci/concept-code-list.spec.ts`: declare a property on a scheme, pick a value in the form, save.
- [ ] 3.2 Unit tests for the validator, the check, deprecation, options paging and labels.

## 4. Discovery wave 1 (CT-4)

- [ ] 4.1 Refuse a choice property with an empty `enum` and no concept scheme, naming the property.
- [ ] 4.2 Hand the editor half to the dossiq lane for `code-lists-from-concepts`: the Properties tab has no input for `enumValues`, study row A4.
- [ ] 4.3 Record that the B3 half, options narrowed by another property's value, is carried by `code-list-lifecycle-and-hierarchy` REQ-CLH-002.
