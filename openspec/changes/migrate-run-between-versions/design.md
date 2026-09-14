# Design: migrate-run-between-versions

## D-1: never automatic

`flow-definition-versioning` forbids re-pointing a run because a silent
move is the failure it exists to stop. This change keeps that rule: a
migration is a request by a named person with a reason, validated, logged,
and refused when the marking does not fit. Publishing a new version still
moves nothing.

## D-2: the marking is the contract

A run is its marking. The validation asks, for every place holding a
token, whether the target version has a node of the same kind under the
same id or under the mapping. Same id and kind needs no mapping; a rename
needs one; a removed node fails unless mapped to a successor. Nothing else
about the graph matters to the run.

## D-3: dry run first, always available

The same validator serves `dryRun`, so a UI can show what would happen
before an administrator commits, and a bulk migration can report what
would be skipped.

## D-4: timers and tasks follow the node

A business timer bound to a task node is re-armed only when that node's
identity changed under the mapping, through supersession with reason
`migrated`, keeping elapsed time. A pending task keeps its assignee and
due date; only its node reference changes.

## D-5: kind

Code, in OpenRegister. Consuming apps add an action and a reason field.
