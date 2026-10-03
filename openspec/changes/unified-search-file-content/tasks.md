# Tasks: unified-search-file-content

> Widen the fleet-wide NC search provider to extracted file text
> (ADR-032 `kind: code`). Checkbox budget: 3 tasks × 2 = 6 unindented
> `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Pass `_content_search` from the provider
- **spec_ref**: `openspec/changes/unified-search-file-content/specs/unified-search-file-content/spec.md#requirement-the-unified-search-provider-must-search-extracted-file-text`
- **files**: `lib/Search/ObjectsProvider.php`, `tests/Unit/Search/ObjectsProviderTest.php`
- **acceptance_criteria**:
  - `searchObjectsPaginated()` is called with `_content_search: true`, alongside the existing `_rbac: true` / `_multitenancy: true` — the provider still performs NO second access filter, per its stated contract
  - A fixture carries a term present ONLY in an attached file's extracted text; the test asserts the search finds nothing WITHOUT the flag and the owning object WITH it, so it measures the change and not the fixture
  - Results are the owning object with URL, title and icon — never a bare chunk, which has nothing to navigate to
  - Pre-existing object-field searches return the same objects in the same order; the fleet's main search bar must not silently reorder
- [x] Implement
- [x] Test

### Task 2: Prove file text cannot route around RBAC or redaction
- **spec_ref**: `openspec/changes/unified-search-file-content/specs/unified-search-file-content/spec.md#requirement-excerpts-must-continue-to-derive-from-the-rendered-object`
- **files**: `lib/Search/ObjectsProvider.php`, `tests/Unit/Search/ObjectsProviderTest.php`
- **acceptance_criteria**:
  - A file attached to an object outside the caller's RBAC/tenant scope yields no hit, PAIRED with an entitled caller who does get it — a content search matching nothing would pass the refusal alone
  - The excerpt for a content-search hit is derived from the rendered object, asserted by giving a redacted field a distinctive value that also appears in the file text and checking it is absent from the excerpt
  - This is the change's one plausible disclosure route: the object stays filtered while the excerpt leaks. It is tested directly rather than reasoned about
- [x] Implement
- [x] Test

### Task 3: Bound it, and measure what it costs
- **spec_ref**: `openspec/changes/unified-search-file-content/specs/unified-search-file-content/spec.md#requirement-content-search-must-be-bounded-and-measured`
- **files**: `lib/Search/ObjectsProvider.php`, `lib/Service/Object/ContentSearchHandler.php`, the change's own notes
- **acceptance_criteria**:
  - The chunk-candidate set is capped; a term matching a very large number of chunks returns within the bound instead of scanning the corpus
  - Latency recorded BEFORE and AFTER on the same corpus, same query set, same warm/cold state — a single warm run is not a measurement
  - The numbers are written into the change. This provider runs in the global search bar, so a regression is felt by every user at once and "it seemed fine" is not evidence
- [x] Implement
- [~] Test

## Status, 2026-09-18

**Tasks 1 and 3's implementation were already shipped, and the checkboxes above
were stale.** Read on the owning repo's branch rather than off this file:
`ObjectsProvider` sets `_content_search = true` and says in its security
contract why that is safe; `QueryHandler` forwards the flag with `_rbac` and
`_multitenancy` intact; `ContentSearchHandler` caps the candidate pool at
`CHUNK_CANDIDATE_LIMIT = 50` and memoises it per request. Task 1's flag test
and the paired guard test both exist in `ObjectsProviderTest`.

**What was missing is task 2's disclosure test, which is the whole risk of this
change**, and it is added here:

- `ContentSearchHandlerTest::testAnAppendedRowCarriesNoneOfTheChunksText` — a
  chunk hit carrying a distinctive value in its text resolves to the owning
  object, and that value appears nowhere on the row. Mutation-checked: making
  the handler carry `chunk_text` onto the entity reddens the assertion.
- `ObjectSearchResultFormatterTest::testExcerptIgnoresKeysAttachedToTheRowRatherThanDeclaredBySchema`
  — and it found something. `buildExcerpt()` walked EVERY top-level string on
  the row, so a key the pipeline attached (rather than one the schema declares
  and field-level security rendered) was excerpt material. Nothing attaches one
  today, which is why this was not visible; the handler's guarantee was the
  only thing standing between file text and the excerpt. The excerpt source now
  skips `@self` and `_`-prefixed keys. Mutation-checked: removing the skip puts
  the file text straight into the subline.

**Task 3's measurement is half done, and saying so is the point.** The cap
exists and is documented on the constant. The recorded measurement is the one
in `ContentSearchHandler`'s docblock: on 2026-09-07, on the fleet dev instance,
run per schema chunk the chunk-store query was 85% of a 55-second top-bar
search, which is what the per-request memo was added to fix. That is a before
number, not a before-and-after pair on the same corpus and query set, and this
lane took no new measurement: it has no instance with that corpus and does not
touch the shared one. A single warm run would not be a measurement, and
inventing a pair would be worse than leaving it open.
