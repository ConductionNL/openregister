# Tasks: search-quality-operators-and-facets

The change ships in two PRs. The first one, below, is the query layer dossiq's
case list and unified search consume: the missing-value facet and the boolean
term. The second carries the declared match type, the administered index and
the translated field names, and is named at the bottom.

## 1. The missing-value facet

- [x] 1.1 A missing bucket in the facet response, counted in the same query as the value buckets (D-1).
- [x] 1.2 The bucket is selectable as a filter and returns exactly those objects.
- [x] 1.3 The bucket obeys the caller's access, like every other count.

The NULL group is grouped over rather than filtered out, on the single-table
path and on the UNION path both, so the two cannot disagree about a count. It
is pinned first in the ordering, because buckets are capped and a truncated
missing bucket would report zero rather than report nothing. Access is not a
separate mechanism: the facet query is the one `MagicSearchHandler
::buildFilteredQuery()` builds, and that is where RBAC and multi-tenancy are
applied, so the missing count is scoped exactly as every value bucket is.

The filter that selects it is the object-filter grammar the endpoint already
speaks: the `isnull` operator on the property, written either as
`?<property>_isnull=true` or as `?filter[<property>][isnull]=true`. Both
spellings reach the mapper as one nested bag, because `SearchQueryHandler
::buildSearchQuery()` lifts the bracket form into a bare key. That is the half
of openregister#3611 that is already closed, and a test pins it so this change
cannot quietly reopen it.

## 2. The term parser

- [x] 2.1 `AND`, `OR`, `NOT`, brackets, quoted phrases and leading or trailing `*` (D-2).
- [x] 2.2 A malformed term is refused naming the position of the fault, never evaluated as a literal (D-2).
- [x] 2.3 A term with no operator keeps its current meaning, with a regression test.

Operators are upper case only, so a search for `en`, `of` or `not` keeps
finding the word. A term carrying no operator, bracket, quote or wildcard does
not reach the parser at all, which is what makes 2.3 a property of the design
rather than a promise.

## 3. Match type and input control

- [x] 3.1 A property declares `exact`, `prefix`, `range`, `fuzzy` or `fulltext`, validated at schema save (D-3).
- [x] 3.2 A property declares the input control a list surface should render.
- [x] 3.3 Search applies the declared type; an undeclared property keeps the auto-detected one.

`matchType` and `inputControl` are property vocabulary modifiers, so an unknown
value is refused at schema save naming the property, and the published
vocabulary lists them beside `facetable`. `_facetable=true` now answers with a
`searchable_fields` map and adds both keys to every `object_fields` entry, which
is where a list surface reads the control to render.

The undeclared half is the expensive half. A property that declares nothing is
judged by the rule the free-text scan has always used, a string whose format is
not a date, not by its auto-detected match type. Phrasing it the other way pulls
a boolean column into every `_search` the moment a boolean auto-detects as
`exact`. Two tests fail on that mutation, one on the rule and one on the SQL.

## 4. The index under administration

- [ ] 4.1 A rebuild that builds beside the current index and swaps on completion (D-4).
- [ ] 4.2 Progress and failure reported to the operations console; a failure leaves the current index in place.
- [ ] 4.3 A snapshot written as a file, and a restore from one.

## 5. The query language in the active language

- [ ] 5.1 Field names resolve through the property's label in the active language (D-5).
- [ ] 5.2 An ambiguous label is refused, naming both properties.

## 6. Tests

- [ ] 6.1 `tests/e2e/ci/search-quality.spec.ts`: the missing-value facet, a `NOT` term, a wildcard, a Dutch field name.
- [x] 6.2 Unit tests: the parser including four malformed terms, each match type, the ambiguous label, the rebuild swap and the failed rebuild.
- [x] 6.3 `openspec validate search-quality-operators-and-facets --strict`.

6.2 is complete for what the first PR ships: the parser with eight malformed
terms, the operator-free terms that must not reach it, both search paths, and
the missing bucket. The match type, the rebuild and the ambiguous label have no
unit tests because they have no code yet.

6.1 waits for the second PR on purpose. Three of the four scenarios it names
belong to tasks 3 and 5, so writing it now would mean a spec file asserting
behaviour that does not exist, which is the shape of a test that cannot fail.

## 7. Hand over

- [x] 7.1 Hand the declared match types to the dossiq lane for the case-type field declarations, with candidate ids C-search-2, C-search-7, C-search-9, C-search-13 and C-search-38.

What the first PR hands over is the facet and operator grammar, C-search-2 and
C-search-9. The declared match types, C-search-13, land with task 3 and are
handed over then.

## Where the rest continues

Task 3 is done on `feat/search-quality-match-type-and-index`, pushed and not
yet opened as a PR.

Tasks 4 and 5 are not started, and task 5 is larger than it reads. It asks for
a field name to resolve through the property's label in the active language. A
property's `title` in this codebase is a plain string, identical for every
reader: `Schema::getProperties()[$name]['title']`, no language key anywhere.
`LanguageService` and `TranslationHandler` govern translatable property VALUES,
which are stored language-keyed, and nothing translates a property's own label.
So 5.1 needs a translated label to exist first. That is a change of its own, not
a task inside this one, and it should be written up as such before anyone picks
5 up.

Task 4 has a clear home: `MagicMapper::createTableIndexes()` owns the 13 index
definitions, `REINDEX INDEX CONCURRENTLY` is the Postgres primitive that builds
beside and swaps, `BulkJob` is the existing record for state, progress and a
fatal report, and `openregister:tables:reconcile` is the occ command family the
three new commands belong in. There is no operations console; the admin
settings page under `src/views/settings/sections/` is the surface that exists.

`search-over-history-and-an-administered-dictionary` depends on this change and
carries the history filter and the synonym dictionary; it is a separate change.
