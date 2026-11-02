# Proposal: decision-table-stored-picker

## Why

Board DqFlow picks a decision table step's table from a list ("Beslistabel: Welstandsgebieden Zuiddrecht"). The node
declared `tableRef` as free text, so an author had to know and type a table's key or id.

## What changes

The decision-table node declares `tableRef` as a `select` with `optionsFrom` the tables in the decision-tables register
(`/apps/openregister/api/objects/decision-tables/decision-table`). The flow editor's step dialog and the board inspector
already load such options; a picked table is stored by its id, which the node already resolves. A stored key keeps
working: the evaluator resolves key or id as before.

## Reach

The decision-table step's form only. No schema change.
