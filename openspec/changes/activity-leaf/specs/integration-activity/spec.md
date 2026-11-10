# integration-activity

## ADDED Requirements

### Requirement: The activity leaf merges an object's feed from five sources

The activity leaf SHALL offer a merged feed for one object drawn from the
object's audit trail, its file events, its notes, mail linked to it and NC
Activity rows, in one reverse chronological list where each row carries a
kind, actor, time, summary and a deep link when the item has one. Each
source SHALL be bounded to the page size and merged on a shared cursor.

#### Scenario: three kinds of write appear as three rows in order

- **GIVEN** an object that was edited, then got a file, then got a note
- **WHEN** the user opens the merged feed
- **THEN** the feed shows the note, the file and the edit in that order with their kinds and actors
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/activity-leaf.spec.ts when the merge ships}

### Requirement: Reads are hidden unless asked for

The merged feed SHALL exclude audit entries of kind read unless the user
turns on the reads toggle, and SHALL remember the toggle per user.

#### Scenario: a page of reads does not bury one write

- **GIVEN** an object with fifteen read entries and one update entry
- **WHEN** the feed opens with the toggle off
- **THEN** the update entry is the only audit row shown
- @e2e exclude {the read filter is covered by unit tests on the provider}

### Requirement: The feed filters by kind and period and exports

The merged feed SHALL offer filter chips per kind and a date range, and
SHALL export the filtered feed as CSV and PDF through the existing export
formats.

#### Scenario: a filtered export holds the filtered rows

- **GIVEN** a feed with two file rows and three note rows
- **WHEN** the user filters on kind note and exports as CSV
- **THEN** the file holds three rows, all of kind note
- @e2e exclude {export contents are covered by unit tests on the exporter}

### Requirement: The merged feed is a tab and a widget surface

The activity leaf SHALL expose the merged feed as a `tab` surface and a
`widget` surface so that a manifest places it on a detail page in place of
separate audit and version tabs.

#### Scenario: a manifest replaces two tabs with the feed

- **GIVEN** a consuming app whose detail page declares the activity leaf's `tab` surface and no audit tab
- **WHEN** a user opens an object's detail page
- **THEN** one Activity tab renders the merged feed
- @e2e exclude {leaf placement is asserted by the manifest parity gate and the consuming app's e2e}
