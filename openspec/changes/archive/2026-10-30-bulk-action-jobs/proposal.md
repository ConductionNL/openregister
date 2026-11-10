---
kind: code
depends_on: []
---

# Proposal: bulk-action-jobs

## Summary

A bulk action over four hundred statutory cases is unrecoverable. Today
OpenRegister has no user-facing bulk act at all: a selection is looped
client side, one request per object, with no preview, no progress, no
record of what was skipped and no way to stop it half way. This change
makes a bulk action one named job: previewed before it commits, run in the
background with progress, reporting a per-object outcome, cancellable, and
carrying the reason the operator typed.

## Candidates and cluster

Cluster 52 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Bulk action as a
background job". Owner openregister, size L, eight candidates, five of
them `must`: C-case-core-1 (a matrix hole), C-case-core-2, C-case-core-3,
C-case-core-4, C-case-core-45, C-search-17, C-reporting-30,
C-configuration-20. Ledger row named in the notes: 13.37. Passers: three,
all driven. dossiq rates `no` on seven of the eight.

Three driven passers is a small number, and under decision **D6** that is
not a reason to drop the cluster: the promotion bar is relevance-led, and
five of these eight candidates are `must`.

## Why

The proving system is dimpact-zac, cited by the case-core lane at
`case-core.tsv:13`: "Bulk operations (websocket-events/spec.md)", for the
candidate that a bulk action runs as a background job and reports its
progress, naming what it skipped. The candidate's own clause is the
sentence to keep: "it skipped 12 of 400 and here they are" is the
difference between a bulk action and a leap of faith.

- **xxllnc-zaken**, `case-core.tsv:44`: "Uitgebreid zoeken V2
  (search-anatomy.md)". The action runs in simulation first and what it
  would have done is read before committing. The same surface refuses a
  bulk attribute change across two case type versions
  (`case-core.tsv:38`), which is the guard that makes the simulation safe
  rather than merely reversible.
- **dimpact-zac**, `case-core.tsv:43`: "Werkvoorraad, coordinator
  (docs/user-manual-features.md)". A bulk distribution needs a written
  justification, and the same surface releases a departing handler's
  caseload in bulk (`case-core.tsv:40`).
- **xxllnc-zaken**, `reporting.tsv:25`: "Background jobs
  (background-jobs/spec.md)". Your own jobs listed, cancellable while
  running, with the result or the error report downloadable. The clause
  names the support call: a bulk action or an export that failed silently.
- **glpi**, `configuration.tsv:127`: "Progress on a long operation
  (src/Glpi/Controller/ProgressController.php,
  Traits/AsyncOperationProgressControllerTrait.php)".
- **xxllnc-zaken**, `search.tsv:47`: every row matching the search is
  selected, not only the page, and the product says which of the two you
  have.

**What exists here and does not close it.** `or-flow-bulk-object-write`
gives a flow node one bulk save per page for synchronisation, with a write
cap and a loud failure on rejected rows. That is the engine's write path,
reached from a flow, with no operator, no preview and no selection.
`scheduled-report-jobs` runs a report on a schedule. Neither answers "I
have selected 400 objects and I want to reassign them", which is the act
every one of these candidates describes.

## What changes

- **A bulk action is a job with a name, an actor and a reason.** It is
  created from a selection and a named action, it carries free-text
  justification, and it is recorded in the audit trail as one act with
  its members.
- **The selection is explicit about what it is.** A caller selects the
  objects on the page or every object matching a query. The job records
  which of the two, and the count at creation time, so a selection that
  grew between preview and commit is visible rather than silent.
- **Preview before commit.** The job is created in `previewed` state and
  reports, per object, what would happen: applied, skipped with a reason,
  or refused with an error. Nothing is written until it is committed.
- **Homogeneity is a guard, not a hope.** A bulk attribute write is
  refused when the selection spans more than one schema version, naming
  the versions and the counts. The guard is declared per action.
- **Progress, and a per-object outcome.** A running job reports its
  position and its counts; a finished job holds the outcome of every
  object, downloadable, with the skipped ones and their reasons.
- **Cancellable, and idempotent on retry.** Cancelling stops before the
  next object, leaves what is already committed committed, and says so. A
  retried job does not repeat an object it already applied.
- **A ceiling and a job list.** An instance declares the largest selection
  one job may carry. Every user reads their own jobs, running and
  finished, with the error report.

## Consumers

- **dossiq**: renders the progress and the skip list, which the build plan
  names as the dossiq half of this cluster. The coordinator's
  werkvoorraad acts (bulk distribution with a justification, releasing a
  departing handler's caseload) become two named actions over this job,
  not two controllers.
- **nextcloud-vue**: one progress and outcome component for every list in
  the fleet, over the same envelope.
- **filinq, pipelinq, humaniq, keepiq**: a bulk act over their own objects
  with nothing to build but the action.

## ADRs

- ADR-022: the bulk mechanism is in the platform layer, and a leaf app
  declares an action rather than writing a loop.
- ADR-005: the preview and the commit resolve access per object. An object
  the actor may not write is reported as refused, never skipped quietly.
- ADR-031: an action is declared, with its guards, not branched in a
  controller.

## Impact

- New capability `bulk-action-jobs`. Extends nothing: the flow bulk write
  stays the engine path and keeps its scope.
- Affected code: a job entity and its controller, the selection resolver
  over the object query, the action registry, the audit trail entries per
  member, and the existing background job infrastructure.
- Backwards compatible: nothing changes for a caller that does not create
  a bulk job.
- Size: L.

## Out of scope

- The per-row write optimisation of `or-flow-bulk-object-write`. This
  change may use it and does not replace it.
- Bulk migration of running objects between schema versions. D18 refuses
  it by design and `case-type-rebind` is the deliberate exception, in
  dossiq, in wave 3.
- A scheduled bulk action. A job that repeats is `scheduled-report-jobs`
  with an action instead of a report, and is a change of its own.
