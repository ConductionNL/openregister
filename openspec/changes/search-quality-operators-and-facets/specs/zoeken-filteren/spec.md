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
