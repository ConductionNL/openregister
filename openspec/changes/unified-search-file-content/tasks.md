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
- [ ] Implement
- [ ] Test

### Task 2: Prove file text cannot route around RBAC or redaction
- **spec_ref**: `openspec/changes/unified-search-file-content/specs/unified-search-file-content/spec.md#requirement-excerpts-must-continue-to-derive-from-the-rendered-object`
- **files**: `lib/Search/ObjectsProvider.php`, `tests/Unit/Search/ObjectsProviderTest.php`
- **acceptance_criteria**:
  - A file attached to an object outside the caller's RBAC/tenant scope yields no hit, PAIRED with an entitled caller who does get it — a content search matching nothing would pass the refusal alone
  - The excerpt for a content-search hit is derived from the rendered object, asserted by giving a redacted field a distinctive value that also appears in the file text and checking it is absent from the excerpt
  - This is the change's one plausible disclosure route: the object stays filtered while the excerpt leaks. It is tested directly rather than reasoned about
- [ ] Implement
- [ ] Test

### Task 3: Bound it, and measure what it costs
- **spec_ref**: `openspec/changes/unified-search-file-content/specs/unified-search-file-content/spec.md#requirement-content-search-must-be-bounded-and-measured`
- **files**: `lib/Search/ObjectsProvider.php`, `lib/Service/Object/ContentSearchHandler.php`, the change's own notes
- **acceptance_criteria**:
  - The chunk-candidate set is capped; a term matching a very large number of chunks returns within the bound instead of scanning the corpus
  - Latency recorded BEFORE and AFTER on the same corpus, same query set, same warm/cold state — a single warm run is not a measurement
  - The numbers are written into the change. This provider runs in the global search bar, so a regression is felt by every user at once and "it seemed fine" is not evidence
- [ ] Implement
- [ ] Test
