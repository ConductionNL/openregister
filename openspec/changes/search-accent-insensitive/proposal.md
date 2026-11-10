---
kind: code
---

# Proposal: search-accent-insensitive

## Summary

A person searching for `reunie` finds the decision about the "reünie", and a
search for `cafe` finds "Café de Flore". Accents stop mattering in Open
Register's object search, on PostgreSQL and on MariaDB, in the plain search,
the boolean search and the facet counts alike, so the count beside a facet
matches the list. An administrator can see whether the database supports it
and switch it off, and a caller who needs an exact match can ask for one.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| decidiq | pub-19 | Search documents with boolean operators, ignoring accents and case, with the search terms highlighted in the results. | partial |

Row `pub-19` in decidiq's matrix, owned here because `built.owner` is
ConductionNL/openregister: decidiq's search passes the term to Open Register
(`lib/Search/DecidiqSearchProvider.php:157` in decidiq, per the packet).

This change closes the accent half of the row. The other halves are owned
elsewhere and are not re-specified here:

- Boolean operators are the open change `search-quality-operators-and-facets`.
  Its term parser and compiler are already called from the search path
  (`lib/Db/MagicMapper/MagicSearchHandler.php:1158-1181`).
- Highlighting is the requirement "Search result highlighting" in
  `openspec/specs/zoeken-filteren/spec.md:421`.
- Case is already ignored (see Why).

Demand rows:

- tender, TenderNed 408309, https://www.tenderned.nl/aankondigingen/overzicht/408309

Competitor yes cells: none recorded in the packet.

## Why

Case is folded today, accents are not:

- On PostgreSQL every column comparison is `col::text ILIKE pattern`
  (`MagicSearchHandler::columnMatchSql()`, `:1354-1368`), and the query-builder
  path lowers both sides (`applyFullTextSearch()`, `:3020-3116`). `ILIKE` folds
  case and nothing else: `café` does not match `cafe`.
- On MariaDB and MySQL the comparison is `LOWER(CAST(col AS CHAR)) LIKE
  LOWER(pattern)` (`:1370-1377`), and the code comment says why: the tables
  may be `utf8mb4_bin`, which is binary and so accent-sensitive as well.
- The facet counts build their own search condition the same way
  (`lib/Db/MagicMapper/MagicFacetHandler.php:1950-1970`).
- There is no `unaccent` anywhere in `lib/`.
- `zoeken-filteren`'s requirement "Dutch language search support" says the
  database backend supports "case-insensitive matching for Dutch diacritics via
  PostgreSQL's `ILIKE`", but its scenario only tests `Cafe` against `cafe`
  (`openspec/specs/zoeken-filteren/spec.md:551-566`). The claim about
  diacritics is not what the code does.

## What changes

- One folding helper builds the column and pattern expressions for every
  search comparison, used by the plain path, the boolean leaves, the fuzzy
  similarity and the facet counts.
- PostgreSQL: the `unaccent` extension, created by a migration the way
  `pg_trgm` is, wrapped in an immutable function so the searchable columns can
  carry a trigram index on the folded value.
- MariaDB and MySQL: the comparison is made under an accent- and
  case-insensitive collation instead of `LOWER()`.
- A setting, on by default where the database supports it, reported with its
  availability on the search settings endpoint, and a request parameter
  `_accents=exact` for a caller who needs an exact match.

## Consumers

- decidiq (pub-19): its search provider and index pages get accent-insensitive
  results with no change on its side.
- Every app searching through Open Register's object search, for example
  dossiq and pipelinq on names with accents.

## ADRs

- openregister ADR-007: the database backend is the only search backend, so the
  folding lives there and nowhere else.
- openregister ADR-009 (performance invariants): folded columns keep an index
  path on PostgreSQL.
- hydra ADR-058 (bounded queries): unchanged; this alters a comparison, not a
  query's size.
- hydra ADR-011 via openregister ADR-008: one folding helper, not three copies.

## Impact

- Extends `zoeken-filteren`.
- Affected code: `lib/Db/MagicMapper/MagicSearchHandler.php`
  (`columnMatchSql()`, `buildSearchLeafSql()`, `applyFullTextSearch()`),
  `lib/Db/MagicMapper/MagicFacetHandler.php`, a new
  `lib/Db/MagicMapper/SearchFolding.php`, a migration for the extension and the
  wrapper function, the searchable-index builder, the search settings in
  `SettingsController`, a new `src/views/settings/sections/SearchConfiguration.vue`.
- Backwards compatible in API; results widen to include accented matches, which
  is the purpose. `_accents=exact` restores the old comparison per request.
- Size: S.

## Out of scope

- Boolean operators (`search-quality-operators-and-facets`) and highlighting
  (`zoeken-filteren`), as above. Whoever builds highlighting uses the same
  folding so an accented match is highlighted.
- Stemming and synonyms.
- Search over file contents (`content-search-index`).
- SQLite, which Open Register does not support in production; there the setting
  reports unavailable.
