# flow-object-write-skip-when

## ADDED Requirements

### Requirement: An item can pass through object-write unwritten

`openregister.object-write` SHALL accept an optional `skipWhen` dot-path.
When it resolves on an item to boolean `true` or the string `skip`
(case-insensitive), that item SHALL be emitted unchanged, in its input
position, and SHALL NOT be written, SHALL NOT consume the write cap, and
SHALL NOT appear in the bulk payload.

Any other value — including the strings `"false"` and `"0"`, which are truthy
in PHP — SHALL NOT skip the item. A value nobody meant as a skip must not
silence a write.

With `skipWhen` absent, behaviour SHALL be unchanged.

#### Scenario: An unchanged item is not rewritten

- **GIVEN** an object-write step with `skipWhen: "contract.outcome"`
- **AND** an item whose `contract.outcome` is `"skip"`
- **WHEN** the step executes
- **THEN** no write is performed for that item and it is emitted unchanged

#### Scenario: A skipped item is emitted, never dropped

- **GIVEN** a page of items where some are skipped and some are not
- **WHEN** the step executes
- **THEN** the output holds one item per input item, in input order

Dropping a skipped item would remove it from what
`openconnector.contract-sweep` counts as reached, and the sweep would then
delete the object that was unchanged. That is why this is a pass-through and
not a filter.
