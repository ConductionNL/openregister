---
kind: code
depends_on: [redaction-release-safeguards]
---

# Proposal: search-dutch-language-quality

## Summary

Search behaves for Dutch text on PostgreSQL: Dutch stemming, and a content snippet taken only from the redacted copy of a public document.

- Rows: 6.32 and 6.33 (not statutory).
- Wave 1, size S.
- Dependencies: `openregister/redaction-release-safeguards` (https://github.com/ConductionNL/openregister/issues/4392) supplies the verdict a snippet requires; until it lands content hits get no snippet.
- Decisions: D6 (PostgreSQL `dutch`, PostgreSQL only, stated; MySQL keeps substring matching) and D5 for 6.33 (passage from the redacted copy, public documents only).
- Build rules: openspec/woo-build-rules.md

## Why

Two rows ask that search behave for Dutch text. Our column (the round 1 baseline):

| row | capability | ours today |
|---|---|---|
| 6.32 | Search matches a Dutch word in its inflected forms, not only as typed | partial: `MagicSearchHandler` matches `_search` as `ILIKE '%term%'`, so `vergunning` finds `vergunningen` but `vergunningen` does not find `vergunning`; `_fuzzy=true` adds `pg_trgm` similarity on the name. `ChunkMapper::searchByKeyword()` uses the `simple` text search configuration on purpose. No Dutch stemming anywhere |
| 6.33 | A search result shows the passage that matched | no: `ObjectsProvider` builds a subline from the rendered object, not the matched passage; opencatalogi strips `_snippet` from public rows; `zoeken-filteren` ZKN-CONTENT-003 forbids a snippet in object search rows |

**Decision D6 (Ruben, 2026-10-05):** the accepted `zoeken-filteren` requirement for stemming and highlighting targeted Solr, which ADR-007 removed. Build it on PostgreSQL's `dutch` text search configuration, PostgreSQL only, and say so; MySQL and MariaDB keep substring matching.

**Decision D5 (Ruben, 2026-10-05):** ZKN-CONTENT-003 and opencatalogi's specs forbid chunk text in results because a snippet taken from an original leaks what redaction removed. The row wins only with the passage taken from the redacted copy, and only for public documents; otherwise the spec wins. This change amends ZKN-CONTENT-003 exactly that far.

## What changes

- **Stemming (6.32), PostgreSQL.** `_search` on PostgreSQL also matches `to_tsvector(<config>, ...) @@ plainto_tsquery(<config>, :term)` over `_name`, `_description`, `_summary` and the schema's string properties, beside the existing `ILIKE`, so an inflected form finds its base and the reverse. The configuration is the setting `search.textSearchConfig` (default `dutch`, limited to the configurations the database reports). A functional GIN index per magic table on the metadata expression keeps it fast. `ChunkMapper::searchByKeyword()` gains the same arm with its own GIN index on `to_tsvector('dutch', text_content)`.
- **Stated, not hidden.** On MySQL and MariaDB nothing changes, and the Nextcloud capabilities document and `GET /api/settings/search` say `stemming: false` there and `stemming: "dutch"` on PostgreSQL.
- **Matched passage (6.33).** With `_snippet=true`, a result row carries `@self.snippet`: for a property hit, the matched passage of that property's value with the match marked (`ts_headline` on PostgreSQL, a window around the match elsewhere), never from a write-only or encrypted property; for a content hit, a passage only when the chunk belongs to an anonymised copy whose verification verdict is `clean` (from `redaction-release-safeguards`) and which is published, and the caller may read it. A content hit from anything else gets no snippet. Without `_snippet=true` rows are as today.

## What does not change

- Substring matching, `_fuzzy`, and ranking.
- opencatalogi's public rows: it may now pass `_snippet` through for public documents; that is its own change.

## Dependencies and absent apps

- `redaction-release-safeguards` (wave 1) supplies the verdict a content snippet requires. Until it lands, content hits get no snippet at all, which is the safe state.
- No other app is called.

## Wave and decision

Wave 1, size S. Implements D6 (PostgreSQL `dutch`, PostgreSQL only, stated) and D5 for 6.33 (passage from the redacted copy, public documents only). Closes 6.32 and 6.33.
