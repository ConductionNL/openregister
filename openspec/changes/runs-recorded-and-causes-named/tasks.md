# Tasks: runs-recorded-and-causes-named

## 1. The cause on an audit entry

- [x] 1.1 A closed cause vocabulary on the audit entry, derived from the acting context.
- [x] 1.2 A cause that is a run carries the run identity.
- [ ] 1.3 The cause is forwarded into deferred listener jobs with the actor.
- [x] 1.4 The audit read and export filter on cause and on run.
- [x] 1.5 A cause supplied by a client is ignored, and the attempt is recorded.

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

- [x] 4.1 Unit tests for the cause derivation, the cascade cause, the forwarded cause and the ignored client-supplied cause.
- [ ] 4.2 Unit tests for the tolerance verdict, the stored tolerance and the failing-record list.
- [ ] 4.3 Unit tests for the run record, the retry lineage and the prune that keeps the header.
- [ ] 4.4 An e2e over an import run read back after it finished, with a failed row and its reason.
- [ ] 4.5 Deduplication check (ADR-012) recorded in the PR body.

## What was built: section 1, the cause

`lib/Service/WriteCause.php` (the closed vocabulary and the ambient frame),
`Version1Date20260918171500` (the `cause` and `cause_run` columns, indexed as a
pair), the two fields on `AuditTrail`, the stamp in `AuditTrailMapper`'s shared
builder, both filter allowlists, the client-attempt guard in
`ObjectsController`, and `tests/Unit/Service/WriteCauseTest.php` (8).

🔴 **THE CLOSED VOCABULARY IS A SECURITY PROPERTY.** A client that can claim its
write was a `migration` can hide a write: an administrator filtering out the
noise of a bulk load would filter out exactly the entry somebody wanted buried.
A word outside the six is NOT STORED, and the test asserts the stored value
rather than that a call was refused — an implementation that passed the word
through and logged would satisfy a "was it rejected" test and still poison the
filter. Mutation-checked.

🔴 **THE FRAME IS A STACK.** An import that fires a rule that cascades is three
causes deep, and the entry is caused by the INNERMOST one. Flattening it makes
the cascade inside an import read as an import, and the import then appears to
have written rows it never touched. There is a test that the OUTER frame
survives the inner one, without which a value-replacing implementation passes.

🔴 **POPPED IN `finally`.** A frame stranded by a throwing operation labels
every later write in the request, and the request reads as one long import.

🔑 **STAMPED IN THE SHARED BUILDER, NOT THE INSERTS.** `insertAuditTrails()`
builds its rows through the same method; stamping the inserts would leave every
BULK write uncaused, which is precisely the write a cause filter exists to find.

🔑 **BOTH FILTER ALLOWLISTS.** There are two, and a filter honoured by one and
dropped by the other answers the whole unfiltered trail with a 200 — the
failure the existing `flow_run` comment already records.

## Not built here, and named rather than claimed

- **1.3, the cause forwarded into deferred listener jobs.** `ActorForwardedJob`
  re-establishes the actor and is the right place, but the frame has to be
  captured at enqueue and restored per job, which touches every subclass. Its
  own change.
- **Section 2 entirely, the data quality audit.** A `qualityAudit` record, a
  population query, a rule set, a tolerance stored WITH the run, a schedule,
  kept runs and exportable failures. That is a record with a lifecycle, not a
  field.
- **Section 3 entirely, the import run.** Same shape: a record, per-row
  outcomes, paging, export, retry lineage and a prune that keeps the header.
- **4.2, 4.3 and 4.4.** They test sections 2 and 3, and an e2e needs a live
  instance.

The cause vocabulary is deliberately the FIRST half: sections 2 and 3 both need
somewhere for `cause_run` to point, and building the pointer before the thing it
points at is what lets the two disagree about what a run is (D-2).

## 4.5 Deduplication check (ADR-012)

- The audit entry, its hash chain and its export: reused, two columns added.
- `AuditFlowAttribution`'s stamping point in the shared builder: reused as the
  precedent and the location, so flow attribution and cause cannot diverge.
- The existing filter allowlist: reused, not a second filter path.
- `SystemOperationContext`: the precedent for an ambient frame rather than a
  threaded argument, and the reason — a single caller that forgot the parameter
  would produce silently uncaused entries.

## Two test-suite findings, both fixed here

- **`RuleEvaluationPointTest` went red on `MoveObject.php`** (merged in #3879),
  which suppresses events when it removes the source row of a move. The guard is
  right to ask, and the answer is that the object was NOT deleted: it is
  readable at its new address with the same uuid. The guard now carries a named
  justification list that must stay in step with the code — an entry whose
  suppression is gone fails too, so it ratchets both ways. My earlier re-run
  was scoped to `tests/Unit/Controller` and `tests/Unit/Service/Object` and did
  not reach it; the whole suite runs in two minutes and there was no reason to
  scope it.
- **`SchemaReuseHygieneTest` was ALREADY RED on `parity/round2`**, on
  `Version1Date20260918101500` returning null. Verified by running the test
  against the base with my work stashed. Fixed here as a one-line inherited fix
  rather than reported: a red suite blocks every later lane from telling their
  red from this one, which is a different cost from a lint finding.
