# Design: rules-compose-read-transitions-and-time

## D-1: a named condition is a record, not a macro

Textual inclusion would make the twenty copies invisible rather than absent.
A named condition is stored once and referenced, so the inventory can say
which rules use it and a correction reaches all of them. That also makes the
cycle check possible: a reference graph can be walked, an expanded string
cannot.

## D-2: composition is bounded and fails closed

Named conditions may reference each other within an administered depth. A
cycle is refused at save, and an unresolvable reference at evaluation time
is a refusal, never a pass. A rules engine that fails open is a permission
system that fails open, one layer down.

## D-3: before and after, not an event type

Adding an event type for "moved into" would need one per property. A
condition that can address the prior value expresses the same thing once and
composes with everything else. The save pipeline already holds both states
for the audit diff, so the operand is available where it is needed.

## D-4: create has no before

On a create, a condition over the prior value has no answer. Returning false
silently means the rule never fires and nobody knows why. So a rule that
requires a prior value declares it, and applying it to a create is refused
at save rather than at three in the morning.

## D-5: relative time is compiled, not interpreted per row

"Created more than three working hours ago" over a hundred thousand objects
is a query, not a loop. Calendar-day and hour offsets compile to a comparison
against a computed instant. Working-hour and business-day offsets resolve the
instant through the working calendar once per evaluation, not once per
object, using the same resolution the timers use so two screens cannot
disagree about the same deadline.

## D-6: the message is content, and it is the administrator's

A validation with a generic message teaches people to click past it. The
message is stored as translatable content beside the rule, points at the
properties it concerns, and is returned verbatim in the refusal. Severity
separates a refusal from a warning, because the same check is often a
refusal for one case type and a hint for another.

## D-7: validations run where every write reaches

`rules-engine-operability` puts rule evaluation in the save pipeline and
carries a test enumerating the write paths. Administered validations are
evaluated at that same point, so nothing added here can be skipped by the
API, an import, a flow node or a bulk job.

## D-8: reuse analysis (ADR-012)

- The JSON AST expression vocabulary and its evaluator: reused, as decision
  D3 requires.
- The save pipeline evaluation point and the write-path enumeration test of
  `rules-engine-operability`: reused.
- The rule inventory and the run log: reused, so a named condition and an
  administered validation appear there like any other rule.
- The working calendar resolution of `flow-business-timers`: reused for the
  business units.
- The i18n content path: reused for the message.
- No second evaluator, no second calendar, no second validation stage.
