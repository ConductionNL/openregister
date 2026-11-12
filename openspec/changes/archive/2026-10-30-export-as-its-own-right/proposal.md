---
kind: code
depends_on: [bulk-action-jobs]
---

# Proposal: export-as-its-own-right

## Summary

May read and may take it all with you are not the same grant, and the AVG
treats them differently. OpenRegister exports well and gates the export
behind read. This change makes export its own permission verb, gives an
export its own declared field set separate from what the screen shows,
lets the author choose stored values or rendered ones, and adds the
whole-dataset export a datawarehouse asks for.

## Candidates and cluster

Cluster 16 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Export as its own right,
its own field set and its own schedule". Owner openregister, size M,
depends on bulk action as a background job, ten candidates: C-reporting-1,
-4, -5, -6, -7, -8, -9, -18, -29 and C-access-and-privacy-63. One is a
`must`: C-reporting-18. No matrix hole. Passers: 13, ten driven and three
documented. dossiq rates `partial` on two and `no` on eight.

## The decisions this rests on

**D6, relevance-led promotion.** C-reporting-18 is a `must` with one
driven passer, dimpact-zac, and enters on relevance rather than on a
count. The candidate file's own line is the reason: "an export is a data
transfer and gating it behind its own permission is the control nobody
else in the corpus has".

**D21, documented candidates admitted and labelled.** C-reporting-4,
C-reporting-8 and C-reporting-9 have no driven passer. One is in scope as
documented and two are recorded, not built.

## Why

The proving system is otobo, cited by the reporting lane at
`reporting.tsv:6`: "Agent, Reports, Statistics Reports
(AgentStatisticsReports.pm, stats_report table,
Output/PDF/StatisticsReports.pm)". A report produced on a schedule and
delivered without anybody opening the product.

The members this change builds:

- **Exporting is a permission of its own** (C-reporting-18, `must`):
  dimpact-zac, "Case list (dashboard-worklists/spec.md)". dossiq's lane:
  "export exists ungated".
- **Permission to export is separate from permission to read**
  (C-access-and-privacy-63): huly, "plugins/export, Settings > Export, and
  the model's 52 permissions". The same control from the access side.
- **Which fields an export carries is configured, separately from the
  list** (C-reporting-29): osticket, "Queue export fields
  (FLAG_INHERIT_EXPORT, include/class.export.php:27 dumpQuery, :43
  dumpTickets, :120 dumpTasks, :180 saveUsers)", and valtimo. The monthly
  aanlevering to the VNG is a fixed field set, not whatever the screen
  shows.
- **Stored values or rendered values, chosen** (C-reporting-7):
  xxllnc-zaken, "Uitgebreid zoeken V2 (search-anatomy.md)". That choice is
  the difference between a report and a data extract, and no row asks it.
- **A scheduled bulk export of the whole set, shaped for analytics**
  (C-reporting-9, documented): jira-data-center, "Data pipeline". dossiq's
  lane: "#Cases exports a list".

**What exists here and does not close it.** `data-import-export`
specifies export to CSV, Excel, JSON, XML and ODS, with filtering, column
selection, relation resolution to human-readable names and streaming for
large sets. `scheduled-report-jobs` specifies a recurring report, an
hourly catch-up-safe runner, the owner's RBAC and tenancy on execution,
delivery to Files, delivery by e-mail and a run-now action.
`rapportage-bi-export` specifies the aggregation API, report templates and
external BI access. Between them, producing and delivering an export is
solved. What is missing is who may do it, what exactly it carries, in
which form the values come out, and a whole-set extract rather than a
report.

## What changes

- **Export is its own verb.** A principal may hold read without export.
  Every export path, the API included, checks it. A refusal names the verb,
  not the record.
- **An export profile is a declared object.** A name, an ordered field set,
  a value mode, a format and an optional filter. It belongs to a register
  and a schema, and it is independent of any saved view's columns.
- **Values come out stored or rendered, and the profile says which.**
  Stored is the raw value as the object holds it. Rendered is what the
  screen would show: a resolved relation, a code list label, a formatted
  date. One profile, one answer, named in the file's own metadata.
- **A profile runs on a schedule.** Over `scheduled-report-jobs`, so the
  runner, the catch-up and the delivery are the ones already specified.
- **A whole-dataset extract is a profile with no filter and every
  register in scope.** It runs as a background job through
  `bulk-action-jobs`, writes one file per schema, and reports progress and
  skips like any other bulk act.
- **Every export is recorded.** Who exported, which profile, how many rows
  and when. An export is a data transfer, so it belongs on the audit
  trail.

## Consumers

- **dossiq**: `case-list-export-via-or-export-leaf` consumes the profile
  and the verb; the case list stops exporting ungated.
- **humaniq, shillinq**: the monthly aanlevering as a profile rather than a
  spreadsheet somebody maintains.
- **opencatalogi**: a Woo extract with rendered values, because a
  publication needs labels and not ids.
- **stackiq, pipelinq, keepiq**: the same verb and the same profiles with
  no work per app.

## ADRs

- ADR-010: export is a permission verb extension, evaluated in the
  authorization layer beside read, create, update and delete.
- ADR-022: one export mechanism in the object layer, consumed by every leaf
  app.
- ADR-031: the field set and the value mode are declared, not coded per
  surface.
- ADR-003: an export is an audit fact naming actor, profile and row count.

## Impact

- Extends: `data-import-export` (the profile, the value mode and the
  whole-set extract) and `authorization-rbac` (the export verb).
- Affected code: the permission handler's verb set, the export service and
  its writers, the scheduled report runner, the audit writer.
- Backwards compatible: an instance that grants export wherever it grants
  read, and exports with no profile, behaves as today. The verb defaults
  to granted on upgrade, and an administrator narrows it deliberately.
- Size: M.

## Out of scope

- A read-only query against the live data from the browser
  (C-reporting-5). The candidate's own clause names the reason: it reads
  everything, and no permission model in this change survives it.
- A budget object the case costs are charged against (C-reporting-1).
  shillinq owns money under cluster 55.
- A satisfaction survey as its own object (C-reporting-8, documented) and
  a requirements traceability matrix (C-reporting-4, documented). Recorded,
  not built.
