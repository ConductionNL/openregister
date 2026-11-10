# Design: runs-recorded-and-causes-named

## D-1: the cause is a closed vocabulary, derived on the server

An open string would be filled with whatever each caller felt like, and the
filter would be useless within a month. The vocabulary is fixed: a person, a
scheduled job, an import, a migration, a rule, a cascade. It is derived from
the acting context on the server, never read from the request, because a
client that can claim its write was a migration can hide a write.

## D-2: a run is one identity, used by three records

An import run, a quality audit run and a bulk job are the same shape: an
actor, a moment, a scope, a per-member outcome. They share one run identity,
so an audit entry whose cause is a run points at exactly one record, and a
row's failure is reachable from the entry it produced.

## D-3: the quality audit reuses the per-object scorer

`specs/data-quality-scoring` already turns a rule set into a score per
object, pure and null-safe, and never fails a save. The audit is that scorer
run over a declared population, with the results kept. Writing a second rule
evaluator would let the dashboard and the save-time score disagree, which is
the worst possible outcome for a quality measure.

## D-4: the tolerance is a decision, so it is recorded with the run

A score of 0.86 is not a verdict. The tolerance says what was agreed, and it
is stored on the run rather than read live, so a run from March still reads
as it was judged then when somebody moves the bar in June.

## D-5: an import record is bounded, and its rows are paged

A million-row load cannot keep a million rows of detail forever. The run
header is kept on the long retention; the per-row detail is kept for an
administered period and then pruned, leaving the header and the counts, in
the same shape the rule run log uses. The failed rows are exportable before
the prune, because correcting them is the reason they were kept.

## D-6: a retry is a new run

Retrying the failed rows creates a new run naming the first as its cause,
rather than mutating the original. The original stays as the record of what
happened, which is the whole point of keeping it.

## D-7: reuse analysis (ADR-012)

- The audit entry, its hash chain and its export: reused, one field added.
- The actor forwarding of `actor-forwarded-listener-jobs`: reused to carry
  the cause into deferred work.
- The scorer of `specs/data-quality-scoring`: reused unchanged.
- The preview, the mapping and the conflict policy of
  `import-preview-and-conflict-policy`: reused; this change keeps their
  result rather than re-deciding anything.
- No second audit store, no second rule evaluator, no second importer.
