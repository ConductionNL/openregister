---
kind: code
depends_on: [property-vocabulary-published]
---

# Proposal: search-quality-operators-and-facets

## Summary

Search finds what is there. A caseworker asking which cases have no result
type, or searching for `dakkapel AND NOT geweigerd`, gets nothing useful
today. This change adds the missing-value facet, boolean operators and
wildcards in the term, a declared match type and input control per search
field, and an administered index rebuild. Four small things that decide
whether a search layer feels finished.

- Woo programme (wave 1) amendment: closes 9.16 (reconciliation of files, chunks and embeddings on a schedule) and 9.19 (reindex a selection with a visible run), not statutory. See the amendment section below.
- Dependencies: `openregister/property-vocabulary-published` (all tasks done, no issue). `openregister/recompute-and-reindex-on-demand` (wave 2) builds on the reindex run.
- No Ruben decision bears on it.
- Build rules: openspec/woo-build-rules.md

## Candidates and cluster

Cluster 65 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Search quality:
operators, facets, stopwords and the index". Owner openregister, size M,
depends on CT-1, seven candidates: C-search-2, C-search-7, C-search-9,
C-search-13, C-search-20, C-search-21 and C-search-38. Highest relevance
`should`, no `must`, no matrix hole. Passers: 6, three driven and three
documented. dossiq rates `partial` on two and `no` on five.

Ledger rows the candidate notes name: 9.2 and 9.15.

## The decisions this rests on

**D21, documented candidates admitted and labelled.** Three of the seven
have no driven passer: C-search-7 (jira-data-center), C-search-20
(decos-join) and C-search-38 (youtrack). Two are in scope as documented
and one is out of scope. None is counted in a driven tally.

**D6, relevance-led promotion.** The cluster carries no `must`, so it
enters on relevance and on its dependence on CT-1, not on a count.

## Why

The proving system is dimpact-zac, cited by the search lane twice. At
`search.tsv:36`: "Search facets (search-and-indexing/spec.md)", for the
facet that offers the records missing the value. At `search.tsv:56`:
"Case search (docs/user-manual-features.md)", for boolean operators and
wildcards. One system, two of the seven members, both driven.

- **A facet for the records missing the value** (C-search-2): "twelve
  cases have no result type" is a data quality report nobody has to write.
- **Boolean operators and wildcards** (C-search-9): a handler who knows
  AND and NOT is faster than any filter panel.
- **A match type and an input control per search field** (C-search-13):
  valtimo, "Case definition Dossierlijst
  (CaseDefinition-Dossierlijst.md)". A date range filter is a field type
  there and a code change here. dossiq rates this `partial`.
- **An administered index rebuild and snapshot** (C-search-7, documented,
  jira-data-center): "System administration, Search indexing, index,
  index-snapshot and reindex API groups". A stale index is a search that
  quietly does not find a zaak, which under the Woo is an answer that is
  wrong rather than late.
- **The query syntax in the organisation's own language** (C-search-38,
  documented, youtrack): "Search Terms for Additional System Languages". A
  Dutch caseworker should not have to type `assigned to: me`.

**What exists here and does not close it.** `zoeken-filteren` specifies
full-text search across object properties, field-level filtering with
comparison operators, fuzzy search over pg_trgm, faceted search,
highlighting and saved searches. `faceting-configuration` specifies
facetable config objects, type auto-detection from property definitions,
discovery through `_facetable`, request configuration through `_facets`,
and a schema editor surface. Between them they cover the filter grammar
and the facet response. Neither has a bucket for the absent value, neither
parses a boolean term, neither lets a property say how it wants to be
matched, and neither has an administered rebuild.

## What changes

- **A facet offers the missing value.** A facet response carries a bucket
  for the objects that hold no value for that property, counted the same
  way as every other bucket and selectable as a filter.
- **The search term accepts boolean operators and wildcards.** `AND`, `OR`,
  `NOT`, grouping with brackets, a leading or trailing `*`, and a quoted
  phrase. A malformed term is refused with the position of the fault, never
  silently treated as a literal string.
- **A property declares its match type and its input control.** Exact,
  prefix, range, fuzzy or full text, and the control the list surface
  should render. The vocabulary comes from `property-vocabulary-published`,
  which is why this change depends on it.
- **The index is rebuilt, snapshotted and restored, administered.** A
  rebuild reports progress and leaves the current index answering until it
  completes. A snapshot is a file an administrator can move to another
  node. The admin surface for running it is the operations console.
- **Field names in the query resolve through the property's own label.** A
  query typed in the active language resolves to the same properties as
  the English one, because the label is already translated on the property.

## Consumers

- **dossiq**: declares which fields are facetable and which match type each
  takes, per case type, and gets the missing-value facet on the case list.
- **nextcloud-vue**: the list components render the declared input control
  rather than guessing from the value.
- **opencatalogi**: a Woo search that finds a publication by a wildcard and
  excludes a category with `NOT`.
- **stackiq, pipelinq, keepiq**: the same facets on their own registers
  with no work per app.

## ADRs

- ADR-007: the options for facets come from the built-in search backend.
  No external engine returns.
- ADR-022: one search layer in the object layer, consumed, not
  reimplemented per app.
- ADR-031: the match type and the input control are declared on the
  property.
- ADR-009: the missing-value bucket is counted in the same query as the
  other buckets, never by a second pass over the result set.

## Impact

- Extends: `zoeken-filteren` (the boolean term and the declared match type)
  and `faceting-configuration` (the missing-value bucket).
- Affected code: the search term parser, the facet builder and its cache,
  the property definition validator, the index maintenance job.
- Backwards compatible: a term with no operator keeps its current meaning,
  and a property that declares no match type keeps the auto-detected one.
- Size: M.

## Out of scope

- One box that routes to a knowledge base or to case data (C-search-20,
  documented, decos-join). The knowledge base is not openregister's.
- One working list across several mailboxes (C-search-21). dossiq has one
  queue and no mailboxes to cross, as its own lane note says.
- Stopword and synonym administration. The cluster name mentions
  stopwords; no candidate asks for them and ADR-007 leaves the analyser to
  the built-in backend.

## Woo capability programme amendment (2026-10-05)

The Woo capability programme (round 1 build plan, wave 1) amends this change with two rows. Re-read on `development` at 1dc6a4667 immediately before writing: the change is open at 15 of 18 tasks (5.1, 5.2 and 6.1 open); the administered rebuild (REQ-SQF-004) is built over the whole index. The operations console the rebuild's note says did not exist has since landed (`admin-operations-console`: `ConsistencyCheckService` probes, `JobRunRecorder`, `/api/operations/*`). Nothing already done is rewritten.

| row | capability | ours today (the round 1 baseline) |
|---|---|---|
| 9.16 | The product reconciles its register against its index on a schedule, and reports what is missing | no: OpenRegister is its own index, so objects and their table rows cannot diverge; files and their text chunks and embeddings can, and nothing reconciles or reports them |
| 9.19 | An administrator reindexes a selection of records, and the run is visible while it happens | partial: bulk save and import jobs exist; REQ-SQF-004 rebuilds whole indexes; there is no reindex of a selection with a visible run |

What the amendment adds:

- **Reconciliation on a schedule (9.16).** Five probes join the consistency check: a file in extraction scope with no chunks and no recorded skip (missing), chunks whose file or object is gone (orphaned), chunks older than their source file (stale), chunks without an embedding while vectorisation is on (missing), and embeddings whose chunk is gone (orphaned). A daily `IndexReconciliationJob` runs them and stores the report; the operations console shows each count with sample ids and the time of the last run, and offers the existing repair action per probe (queue extraction for missing and stale, delete orphans).
- **Reindex a selection (9.19).** `POST /api/operations/reindex` with exactly one of `{register, schema}`, `{register, schema, query}` (the object list filters) or `{ids}` queues a recorded run that re-extracts the objects' own text and their files' text and embeddings. The run is listed in `/api/operations/runs` with `processed`, `total`, `failed` and the failures named, updated while it runs, and shown live on the operations console. Administrator only; a selection above 50,000 objects is refused with the advice to use the whole-index rebuild.

Dependencies: none new. This change's own `recompute-and-reindex-on-demand` (wave 2) builds on the reindex run for parent-to-child reindexing. Closes 9.16 and 9.19.
