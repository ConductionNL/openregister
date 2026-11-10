---
status: proposed
---

# zoeken-filteren

## ADDED Requirements

### Requirement: On PostgreSQL search matches Dutch inflected forms (REQ-SDL-001)

On PostgreSQL, `_search` SHALL match an object when either the existing case-insensitive substring match holds or `to_tsvector(<config>, <expression>) @@ plainto_tsquery(<config>, :term)` holds, where `<expression>` covers `_name`, `_description`, `_summary` and the schema's string properties and `<config>` is the setting `search.textSearchConfig` (default `dutch`; a value not listed in `pg_ts_config` SHALL be refused when saved). `ChunkMapper::searchByKeyword()` SHALL add the same arm over `text_content`. Each magic table and the chunk table SHALL get a functional GIN index matching the expression. On MySQL and MariaDB behaviour SHALL be unchanged. The capabilities document and `GET /api/settings/search` SHALL report `stemming` as the configuration on PostgreSQL and `false` elsewhere.

#### Scenario: an inflected form finds its base form
- **GIVEN** on PostgreSQL a publication titled `Omgevingsvergunning bouwen Dorpsstraat`
- **WHEN** a visitor searches for `omgevingsvergunningen`
- **THEN** the publication is in the results

#### Scenario: a document's text is found by an inflected form
<!-- @e2e exclude Covered by PHPUnit ChunkDutchSearchTest::testAnInflectedFormFindsChunkText against the PostgreSQL test database. -->

- **GIVEN** a file whose text contains `besluit` and not `besluiten`
- **WHEN** `searchByKeyword('besluiten')` runs on PostgreSQL
- **THEN** the file's chunk is returned

#### Scenario: MySQL says it does not stem
<!-- @e2e exclude Covered by PHPUnit SearchCapabilityTest::testMysqlReportsNoStemming. -->

- **GIVEN** an instance on MariaDB
- **WHEN** a client reads the capabilities
- **THEN** `stemming` is `false`, and `_search` behaves as before this change

## MODIFIED Requirements

### Requirement: Response envelope stays object-shaped — no raw chunk payloads (ZKN-CONTENT-003)

Chunk hits routed through `_content_search=true` MUST NOT leak chunk-shaped fields into the `searchObjectsPaginated` response. Specifically, the returned rows MUST NOT contain:

- A `chunk_id`, `chunk`, or `source_id` field (unless that field is already part of the object schema for other reasons).
- A `text_content` field, snippet, or excerpt derived from the chunk, except the `@self.snippet` allowed by REQ-SDL-002 under its conditions.
- A `ts_rank` / `score` field or any ranking numerical exposed as a row property.

The row shape MUST be exactly what `searchObjectsPaginated` returns for a metadata-only match today, with the caller's existing `_extend` / `_fields` selection semantics applied uniformly. Amended by `search-dutch-language-quality` under decision D5 (2026-10-05): the only exception is REQ-SDL-002.

#### Scenario: no chunk fields in response rows

- GIVEN a `_content_search=true` call whose sole matches are chunk-only (no metadata match), without `_snippet=true`,
- WHEN the response rows are inspected,
- THEN no row MUST contain a `chunk_id`, `text_content`, `snippet`, or `ts_rank` / `score` field,
- AND every row MUST be shape-identical to the rows produced by a metadata-only match on the same schemas.

#### Scenario: `_extend` / `_fields` selection applies uniformly

- GIVEN two calls with identical query except one is a metadata match and the other is a chunk-only match,
- AND both calls pass the same `_extend` / `_fields` selection,
- WHEN both responses' first rows are compared,
- THEN the rows MUST have the same set of top-level keys and the same nested shape under `@self`.

## ADDED Requirements

### Requirement: A result shows the passage that matched, only from what the reader may see (REQ-SDL-002)

With `_snippet=true`, each result row SHALL carry `@self.snippet` `{source: "property"|"content", property?, fileId?, text}` with the matched terms marked by `<mark>`, at most 300 characters, HTML-escaped apart from the marks. For a property hit the text SHALL come from that property's value, and never from a property declared write-only or encrypted. For a content hit the text SHALL come only from a chunk of an anonymised copy whose stored verification verdict is `clean` and which is published, and the caller SHALL be able to read that file; for any other content hit the row SHALL carry no snippet. On PostgreSQL the passage SHALL be produced by `ts_headline` with the search configuration; elsewhere by a window around the first substring match.

#### Scenario: a visitor sees why a document matched
- **GIVEN** a public publication whose published anonymised attachment contains `de aanvraag voor de omgevingsvergunning is afgewezen` with a clean verdict
- **WHEN** a client searches `_content_search=true&_snippet=true&_search=vergunning`
- **THEN** the row carries a content snippet from the anonymised copy with `omgevingsvergunning` marked

#### Scenario: the original's text never appears
<!-- @e2e exclude Covered by PHPUnit SnippetSafetyTest::testAContentHitOnAnOriginalHasNoSnippet. -->

- **GIVEN** an original file holding a redacted name and its anonymised copy
- **WHEN** a search for that name matches the original's chunk
- **THEN** the row carries no snippet

#### Scenario: an unpublished or unverified copy gives no snippet
<!-- @e2e exclude Covered by PHPUnit SnippetSafetyTest::testAnUnpublishedCopyHasNoSnippet and testALeakingVerdictHasNoSnippet. -->

- **GIVEN** an anonymised copy that is not published, or whose verdict is `leaking` or `unverifiable`
- **WHEN** its chunk matches
- **THEN** the row carries no snippet

#### Scenario: a write-only property is never quoted
<!-- @e2e exclude Covered by PHPUnit SnippetSafetyTest::testAWriteOnlyPropertyIsNeverQuoted. -->

- **GIVEN** a schema with a write-only property `bsn` that matches the search
- **WHEN** `_snippet=true` is passed
- **THEN** no snippet quotes `bsn`
