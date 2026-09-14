# Tasks: runs-recorded-and-causes-named

## 1. The cause on an audit entry

- [ ] 1.1 A closed cause vocabulary on the audit entry, derived from the acting context.
- [ ] 1.2 A cause that is a run carries the run identity.
- [ ] 1.3 The cause is forwarded into deferred listener jobs with the actor.
- [ ] 1.4 The audit read and export filter on cause and on run.
- [ ] 1.5 A cause supplied by a client is ignored, and the attempt is recorded.

## 2. The data quality audit

- [ ] 2.1 A `qualityAudit` record: a named population query, a rule set, a tolerance, a schedule.
- [ ] 2.2 A run evaluates the declared rules over the population with the existing scorer.
- [ ] 2.3 The run records the score, the tolerance it was judged against, the verdict and the failing records with the rules they failed.
- [ ] 2.4 Runs are kept, so a score is readable beside its predecessors.
- [ ] 2.5 The failing records of a run are exportable.

## 3. The import run

- [ ] 3.1 An `importRun` record: file name, fingerprint, mapping, conflict policy, actor, moment, counts.
- [ ] 3.2 One outcome per row with its reason, paged on read.
- [ ] 3.3 The failed rows are exportable for correction.
- [ ] 3.4 A retry of the failed rows creates a new run naming the original as its cause.
- [ ] 3.5 Per-row detail is pruned on an administered period; the header and the counts are kept.

## 4. Tests

- [ ] 4.1 Unit tests for the cause derivation, the cascade cause, the forwarded cause and the ignored client-supplied cause.
- [ ] 4.2 Unit tests for the tolerance verdict, the stored tolerance and the failing-record list.
- [ ] 4.3 Unit tests for the run record, the retry lineage and the prune that keeps the header.
- [ ] 4.4 An e2e over an import run read back after it finished, with a failed row and its reason.
- [ ] 4.5 Deduplication check (ADR-012) recorded in the PR body.
