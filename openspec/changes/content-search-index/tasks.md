# Tasks: content-search-index

## 1. Provider

- [ ] 1.1 Add the `kind` field and file hits to the provider's result shape.
- [x] 1.2 Parse scopes from the query and narrow the schema list before the
      PR 3528 chunk loop.
- [ ] 1.3 Advertise available scopes in the OCS capability.

## 2. Storage paths

- [ ] 2.1 Backend path: query object and file collections in one round trip.
- [ ] 2.2 Database path: file-chunk `LIKE` bounded by page size; name the
      backend in the response.

## 3. Tests

- [x] 3.1 Unit tests for scope parsing and the two paths.
- [ ] 3.2 `tests/e2e/ci/content-search-index.spec.ts`: attach a text file to
      an object, search a word from the file, see a file hit that deep-links
      to the object.

## What was built: the scopes (1.2) and their test (part of 3.1)

`lib/Search/SearchScopes.php` parses `app:<id>`, `register:<slug>`,
`schema:<slug>` and `files`; `ObjectsProvider` declares a `scopes` filter and
narrows the searchable-schema list with it. `tests/Unit/Search/SearchScopesTest`
(10).

🔴 **NARROWED BEFORE THE CHUNK LOOP, NEVER AFTER IT (D-4).** Filtering the page
afterwards would make a scoped search cost MORE than an unscoped one — the same
union over every searchable table, plus a discard — and would break paging,
because the page boundary would be cut before the unwanted rows were removed.

🔴 **THE TWO WAYS A SCOPE FAILS ARE OPPOSITE, AND BOTH ARE SILENT.** One that
narrows nothing when it should answers rows the reader filtered out and looks
like a broken filter. One that narrows everything when it should not answers an
EMPTY page to somebody who mistyped a chip, and looks exactly like a search that
found nothing. So `colour:blue` is REPORTED as unparsed and leaves the search
unscoped, while `schema:nosuchschema` genuinely keeps nothing — the first asked
no question, the second asked a precise one whose answer is empty.

🔑 **TWO CHIPS ARE AN OR.** A reader who ticks two schemas wants to see more;
intersecting them answers an empty page to somebody who asked for both.
Mutation-checked: turning the OR into an AND reddened three assertions.

🔑 **THE REGISTER SLUG COMES FROM THE REGISTER THAT OWNS THE SCHEMA.** Pairing
a schema with the first register that happens to load makes `register:dossiq`
answer somebody else's rows — true-looking, and about the wrong data.

## Not built here, and named rather than claimed

- **1.1, the `kind` field and file hits.** `_content_search` already widens the
  match to extracted file text, but `augmentWithChunkMatches()` appends the
  OWNING OBJECT and the chunk does not travel out of the pipeline. Marking a hit
  as `kind: file` with its file name and excerpt means carrying the chunk (and
  resolving its source file) through `QueryHandler` to the provider, which is a
  change to the pipeline's return shape and deserves its own PR.
- **1.3, the OCS capability.** It advertises the scopes available TO THIS USER,
  so it needs the per-user searchable set, not the instance's. Small, but it
  belongs with 1.1's shape rather than ahead of it.
- **2.1 and 2.2, the two storage paths.** The database path exists
  (`ContentSearchHandler`); the backend path and naming the backend in the
  response are part of the same response-shape change as 1.1.
- **3.2, the e2e.** Needs a live instance with an attached, extracted file.

The scopes were taken first because they are the half that is complete on its
own: a caller can narrow a search today, and nothing about the result shape had
to change to allow it.
