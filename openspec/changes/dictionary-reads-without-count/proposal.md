# The search-term dictionary reads its rows without counting the table

## What was wrong

Every cross-table text search (`GET /api/objects?_search=...`, the
unified-search provider) loads the administered search-term dictionary once
per language per request (`SearchDictionaryProvider::buildDictionary`). That
load is four reads: the synonym scheme and the stopword scheme by URI, then
the concepts in each. All four went through `searchObjectsPaginated()` with
`countQuery: []`, which for one register and schema still counts the whole
table (an empty count query means "count every row"). The dictionary reads
rows only and never a total, so each read paid one `COUNT(*)` for nothing:
about 1.3 ms of the ~2.3 ms each read took, ~5 ms of a ~75 ms search.

## What changes

- The dictionary reads through `searchObjects()` (rows only, the same
  register/schema resolution, the same filters, `_limit`, `_rbac: false`,
  `_multitenancy: false`), so no count statement runs.
- What the dictionary contains, and the spec's "an edit takes effect on the
  next query", are unchanged: nothing is cached.

## Out of scope

- One combined read of both schemes and their concepts.
- A cross-request cache of the dictionary (looked at and dropped, see the
  performance handoff: the spec wants an edit to apply on the next query).
