# Design: results, side moves and reopen on graph mode

## D-1: a form is properties, not a new payload

`form: { properties: ['result', 'comment'], required: ['result'] }` names
existing schema properties. The transition endpoint accepts their values
beside the action id, validates them against the schema, and saves them in
the same write as the lifecycle field. The audit entry of the transition
lists them, so "closed with result granted" is one row.

## D-2: side moves set a second field and freeze the graph

`sideMoves: { suspend: { field: 'suspended', set: true, label: ... },
resume: { field: 'suspended', set: false } }`. While `suspended` is true the
engine offers no graph move; `availableActions()` returns `resume` only.
A term-extension is a side move with a form on a date property and no
field change, so the same mechanism covers Termijn wijzigen.

## D-3: reopen is a declared exception to terminal lock-out

Terminal graph states lock out non-any moves (object-lifecycle, Terminal
graph states lock out non-any moves). `reopen: { to: <statusType filter or
'first'>, requires: 'manage' }` is the one declared exception. It is a
named action, guarded through the same registry, audited as a reopen.

## D-4: one code path

`availableActions()` and `transition()` keep sharing the derivation; side
moves and reopen are added to the same list the client already reads, each
with its `form`, so a client cannot apply an action it was not offered.

## D-5: kind

Code, in OpenRegister. Consuming apps declare `form`, `sideMoves` and
`reopen` in schema JSON, which is config.
