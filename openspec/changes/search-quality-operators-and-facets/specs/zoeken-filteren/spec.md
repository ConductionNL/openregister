# zoeken-filteren

## ADDED Requirements

### Requirement: The search term accepts boolean operators and wildcards (REQ-SQF-002)

The full-text term SHALL accept `AND`, `OR` and `NOT`, grouping with
brackets, a quoted phrase, and a leading or trailing `*` as a wildcard. A
term the parser cannot read SHALL be refused with a message naming the
position of the fault. A malformed term SHALL NOT be evaluated as a
literal string.

#### Scenario: a caseworker excludes a word

- **GIVEN** objects matching `dakkapel`, some of which also match `geweigerd`
- **WHEN** the term `dakkapel AND NOT geweigerd` is searched
- **THEN** only the objects that do not match `geweigerd` are returned

#### Scenario: a wildcard matches a stem

- **GIVEN** objects holding `vergunning` and `vergunningaanvraag`
- **WHEN** the term `vergunning*` is searched
- **THEN** both are returned

#### Scenario: an unbalanced bracket is refused

- **GIVEN** the term `dakkapel AND (geweigerd`
- **WHEN** it is searched
- **THEN** the response is a refusal naming the position of the fault, and holds no results
- @e2e exclude {parser behaviour, covered by unit tests}

### Requirement: A property declares its match type and its input control (REQ-SQF-003)

A property MAY declare a match type of `exact`, `prefix`, `range`, `fuzzy`
or `fulltext`, and the input control a list surface should render for it.
Search SHALL apply the declared match type. A property that declares none
SHALL keep the type auto-detected from its property definition. An unknown
match type SHALL be refused at schema save, naming the property.

#### Scenario: a date property offers a range

- **GIVEN** a date property declaring the match type `range`
- **WHEN** the list surface reads the searchable fields
- **THEN** it is told to render a range control for that property

#### Scenario: an identifier matches exactly

- **GIVEN** a property declaring `exact` and an object with `Z-2026-0044`
- **WHEN** `Z-2026` is searched against that property
- **THEN** the object is not returned
- @e2e exclude {matcher behaviour, covered by unit tests}

#### Scenario: an undeclared property behaves as today

- **GIVEN** a property that declares no match type
- **WHEN** it is searched
- **THEN** the auto-detected type is applied, unchanged from before this change

### Requirement: The index is rebuilt, snapshotted and restored under administration (REQ-SQF-004)

An administrator SHALL be able to rebuild the search index, take a
snapshot of it and restore a snapshot. A rebuild SHALL report progress and
SHALL keep the current index answering queries until the new one is
complete, at which point it is swapped. A failed rebuild SHALL leave the
current index in place and SHALL name the failure.

#### Scenario: search keeps answering during a rebuild

- **GIVEN** a rebuild running over a register with objects
- **WHEN** a user searches while it runs
- **THEN** results are returned from the index in use before the rebuild started
- @e2e exclude {long-running job, covered by unit tests with a fake indexer}

#### Scenario: a failed rebuild changes nothing

- **GIVEN** a rebuild that fails halfway
- **WHEN** the administrator reads the operations console
- **THEN** the failure is named and search still answers from the previous index
- @e2e exclude {failure path, covered by unit tests}

### Requirement: A query resolves field names through the property's own label (REQ-SQF-005)

A field name typed in a query SHALL resolve through the property's label
in the active language as well as through its technical name. A property
with no label in that language SHALL resolve by its technical name only,
and a name that resolves to more than one property SHALL be refused,
naming both.

#### Scenario: a Dutch field name finds the property

- **GIVEN** a property `assignee` labelled `behandelaar` in Dutch
- **WHEN** a user with Dutch active searches `behandelaar:me`
- **THEN** the filter is applied to `assignee`

#### Scenario: an ambiguous name is refused

- **GIVEN** two properties sharing one label in the active language
- **WHEN** that label is used as a field name
- **THEN** the query is refused, naming both properties
- @e2e exclude {resolution behaviour, covered by unit tests}

### Requirement: The register is reconciled against its search index on a schedule (REQ-SQF-006)

The consistency check SHALL include five index probes: `index-file-missing` (a file in extraction scope with no chunks and no recorded skip status), `index-chunk-orphaned` (a chunk whose source file or object no longer exists), `index-chunk-stale` (a chunk older than its source's last modification), `index-embedding-missing` (a chunk without an embedding while vectorisation is enabled) and `index-embedding-orphaned` (an embedding whose chunk is gone). A daily background job SHALL run them and store the report with its run time. The operations console SHALL show each probe's count, up to 20 sample ids and the last run, and SHALL offer the repair: queue extraction for missing and stale, delete orphans. Probes SHALL be read-only.

#### Scenario: an administrator sees what the index is missing
- **GIVEN** three files in extraction scope that never got chunks and two chunks whose file was deleted
- **WHEN** the nightly reconciliation has run and an administrator opens the operations console
- **THEN** it shows three missing files and two orphaned chunks with their ids and the time of the run

#### Scenario: repairing missing files queues their extraction
<!-- @e2e exclude Covered by PHPUnit IndexReconciliationTest::testRepairQueuesExtractionForMissingFiles through ConsistencyRepairService. -->

- **GIVEN** the report above
- **WHEN** the administrator repairs the missing-file probe
- **THEN** an extraction job is queued for each of the three files and nothing else changes

#### Scenario: the check writes nothing
<!-- @e2e exclude Covered by the existing ConsistencyCheckWouldWriteException guard test, extended to the five probes. -->

- **GIVEN** the five probes
- **WHEN** they run
- **THEN** no table they inspect is written

### Requirement: An administrator reindexes a selection and watches it run (REQ-SQF-007)

`POST /api/operations/reindex` SHALL be administrator only and SHALL accept exactly one selection: a register and schema, a register and schema with object list filters, or a list of object ids. It SHALL resolve the selection, refuse more than 50,000 objects with 400, and queue one recorded run that re-extracts each object's text chunks and each attached file's text and embeddings. The run SHALL appear in `GET /api/operations/runs` with `processed`, `total`, `failed` and the failed ids with reasons, updated at least every 100 objects while it runs, and the operations console SHALL show it live.

#### Scenario: an administrator reindexes one request's documents
- **GIVEN** 240 publications of one Woo request selected by the filter `wooVerzoek=2026-118`
- **WHEN** an administrator starts a reindex of that selection from the operations console
- **THEN** a run appears with total 240, its processed count rises while it runs, and it ends with processed 240 and the failures named

#### Scenario: a selection too large is refused
<!-- @e2e exclude Covered by PHPUnit ReindexSelectionTest::testMoreThanFiftyThousandIsRefused. -->

- **GIVEN** a selection resolving to 60,000 objects
- **WHEN** it is posted
- **THEN** the response is 400 naming the whole-index rebuild
