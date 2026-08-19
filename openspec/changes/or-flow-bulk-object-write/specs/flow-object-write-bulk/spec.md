# flow-object-write-bulk

## ADDED Requirements

### Requirement: A bulk write is one call, refused wherever its semantics are narrower

`openregister.object-write` with `bulk: true` SHALL write the whole page of
items through exactly one `ObjectService::saveObjects()` call, executed
inside the same `runAs($owner)` wrapper as the per-item path.

Bulk mode SHALL be refused at save time when combined with semantics the
bulk path does not have:

- an operation other than `create` or `upsert`;
- `onConflict: "fail"` (the bulk path cannot arbitrate per-row claims);
- an upsert without `replace: true` (the bulk path replaces whole rows and
  cannot patch);
- an upsert whose match is not exactly one pair on `uuid` / `@self.uuid`
  (the bulk path decides create-versus-update by row uuid alone);
- a non-boolean `bulk` value.

#### Scenario: A bulk update is refused when the flow is saved

- **GIVEN** an object-write step with `operation: "update"` and `bulk: true`
- **WHEN** the flow is validated
- **THEN** validation fails naming bulk's create/upsert-only support

#### Scenario: A bulk upsert without replace is refused

- **GIVEN** an object-write step with `operation: "upsert"`, `bulk: true`,
  a uuid match, and no `replace`
- **WHEN** the flow is validated
- **THEN** validation fails saying the bulk path cannot patch

### Requirement: Bulk row identity is decided client-side

In bulk mode the node SHALL assign every row's uuid before the call:
a fresh v4 uuid for a create, the resolved single uuid match value for an
upsert. An upsert match value that does not resolve SHALL fail the item
rather than widen into a create. Every output item SHALL carry its row's
`uuid`, `register` and `schema`, so a downstream step can name the row
without re-reading.

#### Scenario: Output items name their rows after a bulk create

- **GIVEN** a bulk create over three items
- **WHEN** the step executes
- **THEN** three rows are sent in one `saveObjects()` call, each with a
  distinct generated `id`, and each output item carries that id as `uuid`

### Requirement: A bulk result with rejected rows fails the step loudly

`saveObjects()` records refusals in its result rather than throwing. The
node SHALL fail the step when the result carries `invalid` or `errors`
entries, naming the rejected count, the first reason, and that accepted
rows were written and not rolled back. The write cap SHALL be enforced
against the page size before anything is written.

#### Scenario: A page over the cap writes nothing

- **GIVEN** a bulk write of more items than the step's `maxWrites`
- **WHEN** the step executes
- **THEN** it fails before calling `saveObjects()` and says nothing was
  written
