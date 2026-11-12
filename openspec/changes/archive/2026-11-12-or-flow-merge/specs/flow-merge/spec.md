## ADDED Requirements

### Requirement: Items belong to places, not to the run (REQ-FM-001)

The engine SHALL carry an item list per PLACE, not one list for the whole run.
A parallel split SHALL hand each branch the items from the split point; a join
SHALL read the concatenation of every incoming branch's items, in the froms'
declared order.

The buffers SHALL be seeded from the current marking, so a resumed run's stored
items land on the place that holds the token.

#### Scenario: Parallel branches do not see each other

- **GIVEN** a split to two branches from one seed item
- **WHEN** each branch runs
- **THEN** each receives the seed item, not the other branch's output

#### Scenario: A join reads every branch

- **GIVEN** two branches that each stamp a distinct marker, converging on a join
- **WHEN** the join fires
- **THEN** it receives one item from each branch

### Requirement: Merge combines branch items (REQ-FM-002)

`openregister.merge` SHALL combine the items the join gave it. `append` keeps
them all; `mergeByKey` groups items sharing a `key` value and shallow-merges
each group, the later branch winning a field collision; `unique` keeps the
first item per `key` value.

A keyed mode with no `key`, or an unknown mode, SHALL be refused at save time.

#### Scenario: mergeByKey enriches

- **GIVEN** two items with the same key carrying different fields
- **WHEN** merged by that key
- **THEN** one item results, carrying both fields

### Requirement: Loop batches an item list (REQ-FM-003)

`openregister.loop` SHALL split its items into fixed-size batches, emitting one
item per batch carrying `batchIndex`, `batchCount` and the slice under `items`.
An empty input SHALL yield no batches. A batch size below one SHALL be refused
at save time.

#### Scenario: Seven items in batches of three

- **GIVEN** seven items and a batch size of three
- **WHEN** the loop runs
- **THEN** three batch-items result, sized 3, 3 and 1

@e2e exclude engine item semantics are backend-only — covered by PHPUnit
