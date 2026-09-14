# Tasks: search-quality-operators-and-facets

## 1. The missing-value facet

- [ ] 1.1 A missing bucket in the facet response, counted in the same query as the value buckets (D-1).
- [ ] 1.2 The bucket is selectable as a filter and returns exactly those objects.
- [ ] 1.3 The bucket obeys the caller's access, like every other count.

## 2. The term parser

- [ ] 2.1 `AND`, `OR`, `NOT`, brackets, quoted phrases and leading or trailing `*` (D-2).
- [ ] 2.2 A malformed term is refused naming the position of the fault, never evaluated as a literal (D-2).
- [ ] 2.3 A term with no operator keeps its current meaning, with a regression test.

## 3. Match type and input control

- [ ] 3.1 A property declares `exact`, `prefix`, `range`, `fuzzy` or `fulltext`, validated at schema save (D-3).
- [ ] 3.2 A property declares the input control a list surface should render.
- [ ] 3.3 Search applies the declared type; an undeclared property keeps the auto-detected one.

## 4. The index under administration

- [ ] 4.1 A rebuild that builds beside the current index and swaps on completion (D-4).
- [ ] 4.2 Progress and failure reported to the operations console; a failure leaves the current index in place.
- [ ] 4.3 A snapshot written as a file, and a restore from one.

## 5. The query language in the active language

- [ ] 5.1 Field names resolve through the property's label in the active language (D-5).
- [ ] 5.2 An ambiguous label is refused, naming both properties.

## 6. Tests

- [ ] 6.1 `tests/e2e/ci/search-quality.spec.ts`: the missing-value facet, a `NOT` term, a wildcard, a Dutch field name.
- [ ] 6.2 Unit tests: the parser including four malformed terms, each match type, the ambiguous label, the rebuild swap and the failed rebuild.
- [ ] 6.3 `openspec validate search-quality-operators-and-facets --strict`.

## 7. Hand over

- [ ] 7.1 Hand the declared match types to the dossiq lane for the case-type field declarations, with candidate ids C-search-2, C-search-7, C-search-9, C-search-13 and C-search-38.
