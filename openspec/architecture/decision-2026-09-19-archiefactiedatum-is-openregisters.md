# Decision: OpenRegister owns the ZGW archiefactiedatum rule

**Status**: recorded, not built

**Date**: 2026-09-19

**Decided by**: Ruben

## The decision

OpenRegister owns the derivation of `archiefnominatie` and `archiefactiedatum`,
not dossiq. `Service/Archival/ArchivalNominationDeriver` in dossiq is a
stand-in, and it goes when OpenRegister can answer the whole rule.

## What OpenRegister needs, and what already landed

The blocker dossiq's docblock names is out of date, and that matters because a
docblock claiming a dependency is blocked stops anyone testing it. It says
`x-openregister-lifecycle.final` is a static enum list validated against the
field's enum, that `initial` has a dynamic `{from, field}` form and `final` has
no analogue, and that OpenRegister therefore cannot answer finality for a
provider-mode schema whose `case.status` is a `$ref` to a `statusType` row.
`final` does have that analogue today. `ArchivalNominationService::isTerminalState()`
reads `final` in reference form and hands it to
`Service/Lifecycle/LifecycleFinalStateResolver::isFinalByReference()`, which
resolves the lifecycle value as a row of the declared schema, reads the named
property off it, and treats an unresolvable row as not terminal with a warning
rather than in silence. So finality for a provider-mode schema is answered, and
the one thing OpenRegister still cannot express is the rest of ZGW rule zrc-021:
the two hops from `case.result` to `result.resultType` to
`resultType.archivalPeriod`, with the nomination copied off that far row and the
retention period read off it rather than declared on the schema.
`Service/Archival/ArchiveActionDateCalculator` follows exactly one hop, through
`sourceRelation` and `sourceRelationProperty`, and the period is a schema
declaration. To take the rule over, OpenRegister needs a relation path of more
than one hop, and it needs a retention declaration that can name the far row as
the source of both the nomination and the period.

## Does `lifecycle-over-reference-field` close it

No. That change adds a transition `form`, `sideMoves` and `reopen` to graph
mode, so a case can record a result with its closing move. It makes the trigger
better and leaves the derivation where it is. The half that is already closed
was closed by `archiving-as-a-process-with-sign-off`, which shipped the
reference form of `final`. The half that remains is the multi-hop retention
source, and it belongs to `archival-conformance`, which already tracks the
single-hop mechanic as gap C1.

## Next step

Open a change against `retention-management` for the multi-hop retention
source, then delete dossiq's deriver and correct its docblock in the same pass.
