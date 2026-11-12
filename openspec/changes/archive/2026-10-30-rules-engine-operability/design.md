# Design: rules-engine-operability

## D-1. The inventory is derived, never a second registry

A rule lives in one of four places today: a lifecycle `condition`, a
state's `fields` block, an `x-openregister-calculations` annotation and a
flow definition. The inventory reads those four and sorts them by the
order the save pipeline evaluates them. It stores nothing of its own.

The alternative, a rules table an administrator maintains beside the
annotations, gives two sources of truth and one of them drifts. The
inventory is a projection, so a rule that is not in the schema cannot
appear in it, and a rule in the schema cannot be missing from it.

## D-2. The run log records the operand that decided, not the whole rule

"Did not match" is not an answer a functional administrator can act on.
The log records the first operand that made the condition false, with the
value it read. That is one string per evaluation, bounded, and it is what
turns "waarom is de flow niet gelopen" into a fact.

A full trace per evaluation is the engineer's artefact and stays in the
flow run log, which already records what each node received and returned.

## D-3. The log is bounded by retention, because a rule runs on every save

A rule that fires on every object save writes a log row per save. The log
is a first-class retention subject: it is pruned by the same daily pass
that prunes the realtime events log and the search trail, with its own
configured period, and the inventory shows the last run and the last error
even after the detail rows are gone.

## D-4. The ceiling stops before the first write

A ceiling that stops half way through leaves a partial mutation, which is
worse than the runaway it prevents. The count is taken first, compared to
`maxObjects`, and the run refuses as a whole. The refusal names the rule
and the count, so the administrator either widens the ceiling on purpose
or fixes the filter.

## D-5. The dry run and the replay are the same evaluation with one flag

Both answer "what would this do". The dry run answers it for one object
and returns immediately; the replay answers it for a selection and runs as
a background job with a preview, under `bulk-action-jobs`. Writing them as
two engines is how they drift apart, so the evaluator takes a
`commit: false` and the two surfaces differ only in scope and transport.

## D-6. The evaluation point is the save pipeline, and the test says so

"Rules are evaluated on every mutation" is a claim that rots the moment a
new write path is added. It is enforced by a test that enumerates the
write paths (API create, API update, patch, import, flow node, bulk job)
and asserts each one reaches the evaluator. A new path with no entry fails
that test, which is the only durable version of this requirement.

## D-7. The AST is the authored surface, and the other two stay

Twig `computed` and JSONLogic `condition` are both shipped and both have
users. D3 chose the JSON AST for what an administrator writes, because it
is auditable, diffable and safe by construction, and because an auditor
reads it a year later. The other two are not removed; they are documented
as legacy and the authoring surfaces offer the AST.

## D-8. kind

Code, in OpenRegister. The consumers read two endpoints and declare a
ceiling. No leaf app builds a rule screen of its own.
