# Tasks: content-search-index

## 1. Provider

- [x] 1.1 Add the `kind` field and file hits to the provider's result shape.
- [x] 1.2 Parse scopes from the query and narrow the schema list before the
      PR 3528 chunk loop.
- [x] 1.3 Advertise available scopes in the OCS capability.

## 2. Storage paths

- [ ] 2.1 Backend path: query object and file collections in one round trip.
- [x] 2.2 Database path: file-chunk `LIKE` bounded by page size; name the
      backend in the response.

## 3. Tests

- [x] 3.1 Unit tests for scope parsing and the two paths.
- [ ] 3.2 `tests/e2e/ci/content-search-index.spec.ts`: attach a text file to
      an object, search a word from the file, see a file hit that deep-links
      to the object. (live pass, decision 139)

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

## What the second pass built: the capability (1.3) and `app:` scopes

`lib/Capabilities/SearchScopesCapability.php` advertises, under
`openregister.search`, the provider id, the filter the scopes travel in
(`scopes`) and the `app:`, `register:` and `schema:` scopes of the searchable
schemas THIS USER may read (`PermissionCheckInterface::hasPermission(read)`;
a check that throws keeps the schema out). Nobody signed in is offered
nothing. It is excluded from the initial state Nextcloud renders into every
page, because it costs a read check per searchable schema.

`lib/Search/SearchScopeCatalog.php` is the one description of the searchable
schemas both readers use — the provider to narrow, the capability to
advertise — so a chip offered is read the same way when it comes back.

🔴 **`app:` NOW NARROWS.** The first pass parsed it and `narrowSchemas()`
ignored it, so `app:dossiq` searched every schema. It keeps the schemas the
app claims in the deep-link registry, keyed on the (register, schema) pair;
an app that claims nothing keeps nothing.

🔑 **SLUGS ARE COMPARED IN LOWER CASE.** The tokens always were, so a schema
stored as `Case` could never be named. Five rows of `SearchScopesGoldenTest`
were re-recorded for these two changes, and say so.

🔑 **`files` IS NOT ADVERTISED YET.** It narrows nothing until hits carry a
`kind` (1.1), so a chip for it would look like a broken filter.

Tests: `SearchScopesCapabilityTest` (5), `SearchScopeCatalogTest` (4), three
more in `SearchScopesTest`, one provider-level `app:` test in
`ObjectsProviderTest`. Each new file mutation-checked.

## Not built here, and named rather than claimed

- **1.1, the `kind` field and file hits.** `_content_search` already widens the
  match to extracted file text, but `augmentWithChunkMatches()` appends the
  OWNING OBJECT and the chunk does not travel out of the pipeline. Marking a hit
  as `kind: file` with its file name and excerpt means carrying the chunk (and
  resolving its source file) through `QueryHandler` to the provider, which is a
  change to the pipeline's return shape and deserves its own PR.
- **`files` in the capability.** Added with 1.1, when a `files` scope keeps
  only file hits.
- **2.1 and 2.2, the two storage paths.** The database path exists
  (`ContentSearchHandler`); the backend path and naming the backend in the
  response are part of the same response-shape change as 1.1.
- **3.2, the e2e.** Needs a live instance with an attached, extracted file.

The scopes were taken first because they are the half that is complete on its
own: a caller can narrow a search today, and nothing about the result shape had
to change to allow it.

## What the third pass built: file hits (1.1) and the database path (2.2)

`lib/Search/FileHitSearch.php` is the file arm. It asks the file-chunk table
(`ChunkMapper::searchByKeyword()`, `source_type=file`, ranked on PostgreSQL and
the unranked `LIKE` elsewhere) for at most one page of chunks, keeps one hit per
file, and resolves each file to its owning object through `MagicMapper::find()`
with RBAC and tenancy on. A file whose object the caller may not read, that is
soft-deleted, or whose schema is not among the searched (searchable, scoped)
schemas is no hit.

`ObjectsProvider` marks every entry with the attributes `kind` (`object` or
`file`) and `backend` (`database`). A file hit's title is the file name, its
subline the excerpt around the term and the object's title, and its link the
owning object's deep link; it also carries `fileId` and `fileName`.

🔴 **ONE HIT, NOT TWO.** With the file arm wired the object arm no longer sets
`_content_search`, which answered a file match as its OWNING OBJECT. Keeping
both would list the same match twice, once as an object that does not contain
the word. Without the file arm (a construction that does not pass it) the older
widening stays.

🔑 **FILE HITS RIDE ON THE FIRST PAGE ONLY**, after the object hits and bounded
by the page size, so paging through the object hits never repeats them and the
page boundary stays where the object arm put it.

🔑 **`files` IS A KIND.** Alone it searches every searchable schema and answers
file hits only; with place scopes it answers the file hits in those places. A
`register` filter narrows file hits as it narrows object hits. The capability
now offers `files`, after the place scopes, once anything is readable.

Tests: `tests/Unit/Search/ContentSearchFileHitsTest.php` (7), through
`ObjectsProvider::search()`; one assertion added to `SearchScopesCapabilityTest`.

## Still not built

- **2.1, the backend path.** There is no backend to query: the external
  Solr/Elasticsearch tier was removed and `SearchBackendHandler` names
  `database` as the only backend. The response already names the backend, so a
  backend added later only has to answer the same shape.
- **3.2, the e2e.** Needs a live instance with an attached, extracted file.

