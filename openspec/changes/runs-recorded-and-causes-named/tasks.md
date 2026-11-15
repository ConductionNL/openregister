# Tasks: runs-recorded-and-causes-named

## 1. The cause on an audit entry

- [x] 1.1 A closed cause vocabulary on the audit entry, derived from the acting context.
- [x] 1.2 A cause that is a run carries the run identity.
- [x] 1.3 The cause is forwarded into deferred listener jobs with the actor.
      `ListenerDeferralService` reads `WriteCause::current()` when an entry is deferred (the buffer flushes at
      shutdown, after every frame is popped) and sends consecutive entries of one cause out as one job;
      `DeferredListenerContext` carries `cause` and `causeRun` in the job argument (only for a cause other than
      a person's, so a person's job argument is unchanged); `ActorForwardedJob` runs `runDeferred()` inside
      `WriteCause::runAs()`, so no subclass changes.
- [x] 1.4 The audit read and export filter on cause and on run.
- [x] 1.5 A cause supplied by a client is ignored, and the attempt is recorded.
      The attempt is recorded on the entry itself: `AuditTrailMapper::buildAuditTrail()`
      marks `changed.clientSuppliedCause` when `WriteCause::clientAttempted()`, before the row is sealed.

## 2. The data quality audit

- [x] 2.1 A `qualityAudit` record: a named population query, a rule set, a tolerance, a schedule.
      `QualityAudit` (`openregister_quality_audits`, `Version1Date20261114130000`): name, a population of
      register, schema and field filters, the scorer's rules, a tolerance and `daily`/`weekly`/`monthly` or
      none. `QualityAuditService::save()` refuses a faulty declaration naming each fault (the evaluator's
      check, an unknown schedule, a register or schema that does not exist), and `/api/quality-audits`
      declares, reads, changes and removes them, administrators only.
- [x] 2.2 A run evaluates the declared rules over the population with the existing scorer.
      `QualityAuditService::run()` executes the population query a page at a time and hands the members to
      `QualityAuditEvaluator::evaluate()`, which scores them with `QualityScorer`; no second rule evaluator
      exists. On demand: `POST /api/quality-audits/{id}/run`. On a schedule: `QualityAuditScheduleJob`
      (hourly) runs every audit whose period has passed since its last run.
- [x] 2.3 The run records the score, the tolerance it was judged against, the verdict and the failing records with the rules they failed.
      `QualityAuditRun` keeps the moment, who started it (a user or `schedule`), the count evaluated, the
      score, the tolerance AS IT STOOD, the verdict and the failure count; the failing records go to
      `openregister_quality_audit_failures` in batches as they are found, never held in memory. A run that
      cannot finish is kept with the verdict `error`.
- [x] 2.4 Runs are kept, so a score is readable beside its predecessors.
      `GET /api/quality-audits/{id}` returns the audit with its runs oldest first; `/runs` pages them.
      Changing an audit never touches its runs.
- [x] 2.5 The failing records of a run are exportable.
      `GET /api/quality-audit-runs/{id}/failures` (paged JSON) and `/failures.csv` (`record,rules`).

## 3. The import run

- [x] 3.1 An `importRun` record: file name, fingerprint, mapping, conflict policy, actor, moment, counts.
      The import preview of `import-preview-and-conflict-policy` already is that record (`sourceName`,
      `sourceHash`, `packSlug`, `policy`, `createdBy`, `created`, the counts), so it is kept rather than
      duplicated (D-7). `Version1Date20261114120000` adds `cause_run` and `rows_pruned_at` to it, and a
      commit runs inside `WriteCause::runAs(import, <run uuid>)`, so every audit entry it writes names the run.
- [x] 3.2 One outcome per row with its reason, paged on read.
      The paged `/api/import-previews/{id}/rows` already existed; a row now also carries `outcome`
      (`written` or `failed`), the object it created (its uuid fixed at preview time) or updated, and
      `source`, the row as it was submitted.
- [x] 3.3 The failed rows are exportable for correction.
      `GET /api/import-previews/{id}/failed` (`ImportFailedRowsCsv`): every skipped, refused or failed row
      in its submitted columns, read a page at a time, led by `_row` and `_reason`, which the importer
      ignores, so the corrected file loads as it is.
- [x] 3.4 A retry of the failed rows creates a new run naming the original as its cause.
      `POST /api/import-previews` with `retryOf=<run id>`: only a run the caller may read is accepted, and
      the new run's `causeRun` is the original's uuid; the original is not touched.
- [x] 3.5 Per-row detail is pruned on an administered period; the header and the counts are kept.
      `ImportRunRetentionJob` (daily) through `ImportRunRetention`: app config
      `import_run_row_retention_days` (default 90). A pruned run reads `rowsPruned: true`, its rows say
      `pruned`, and its failed-row export answers 410 rather than an empty file.

## 4. Tests

- [x] 4.1 Unit tests for the cause derivation, the cascade cause, the forwarded cause and the ignored client-supplied cause.
- [x] 4.2 Unit tests for the tolerance verdict, the stored tolerance and the failing-record list.
      (`tests/Unit/Service/Quality/QualityAuditEvaluatorTest.php`, against the real scorer; the definition
      check of REQ-RCN-003 is covered there too. The stored run, the schedule and the export:
      `QualityAuditServiceTest`, `QualityAuditTest`, `QualityAuditControllerTest`.)
- [x] 4.3 Unit tests for the run record, the retry lineage and the prune that keeps the header.
      `ImportRunRecordTest`, `ImportFailedRowsCsvTest`, `ImportRunRetentionTest`, `ImportRunControllerTest`.
- [ ] 4.4 An e2e over an import run read back after it finished, with a failed row and its reason.
- [x] 4.5 Deduplication check (ADR-012) recorded in the PR body.
      Section 1 in its PR and below; sections 2 and 3: the scorer and evaluator reused, the import preview
      reused as the run record, the cause frame reused for the run identity, no second store or evaluator.

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

- **1.3, the cause forwarded into deferred listener jobs.** Built since: the
  base `ActorForwardedJob` restores the frame around `runDeferred()`, so no
  subclass needed to change (see the task line above).
- **Sections 2 and 3**, the data quality audit and the import run: built in the
  second pass (see the task lines above). The quality audit is three new tables
  (the audit, its kept runs, their failing records); the import run reuses the
  import preview as its record, as D-7 asks, rather than a second store.
- **4.4, the e2e** over an import run read back with a failed row: it needs a
  live instance, so it is left for a lane that has one.

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
