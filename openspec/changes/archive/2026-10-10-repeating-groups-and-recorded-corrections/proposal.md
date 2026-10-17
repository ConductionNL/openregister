---
kind: code
depends_on: [property-vocabulary-published]
---

# Proposal: repeating-groups-and-recorded-corrections

## Summary

Meerdere gemachtigden, meerdere percelen and meerdere zienswijzen are all
the same shape: a group of fields that repeats. OpenRegister can hold an
array of objects in a schema and no surface authors one. Beside it sits
the other half of this cluster: every municipality corrects a
mis-registered case, and the alternative today is a database edit. This
change adds the repeating group as a property kind, and the correction as
an act that is recorded as a correction.

## Candidates and cluster

Cluster 49 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Corrections, repeating
groups and recorded incompleteness". Owner openregister, size M, depends
on CT-1, four candidates: C-case-core-18, C-case-core-27, C-case-core-32
and C-documents-31. One is a `must`: C-case-core-18. No matrix hole.
Passers: 4, all four driven. dossiq rates `partial` on two and `no` on
two.

## The decisions this rests on

**D6, relevance-led promotion.** C-case-core-18 is a `must` with one
driven passer and enters on relevance.

**CT-1 as the dependency.** The property vocabulary is eight types wide
here and twenty in OpenRegister, and `property-vocabulary-published`
publishes the list. A repeating group is the next type on it, which is why
this change depends on that one.

## Why

The proving system is otobo, cited by the case core lane at
`case-core.tsv:27`: "Dynamic field, Set (Driver/Set.pm)". A repeating
group of fields held on one record, authored in the field editor. That is
C-case-core-18, a `must`. dossiq's lane is precise: "OpenRegister arrays
of objects exist in schemas; no repeating group in a case form found".

The rest:

- **Any core field corrected afterwards, from a block labelled
  administrative** (C-case-core-27): xxllnc-zaken, "Case page,
  Beheeracties block (Case-Zaakacties.md)". dossiq: "BulkStatusTransitionService
  for status only".
- **How long consecutive edits by one person merge into one history
  entry** (C-case-core-32, `could`): openproject, "Administration,
  /admin/settings/aggregation". The candidate's clause is the argument for
  building it carefully: "an audit trail that merges away the order of two
  edits answers a different question than the one an auditor asked".
- **The descriptions and filenames of every file on a record corrected in
  one form** (C-documents-31, `could`): redmine, "get
  'attachments/:object_type/:object_id/edit' and patch,
  attachments#edit_all". Tidying a dossier before it goes out.

**What exists here and does not close it.** `runtime-schema-api` carries
runtime schema creation and update with cache invalidation and engine
reload. `format-validators` and `schema-driven-read-coercion` carry
validation and coercion. `content-versioning` and `enhanced-audit-trail`
carry history. `file-actions` and `files-leaf-save-to-object` carry files
on an object. So an array of objects validates and a change is recorded.
What is missing is the declaration that makes a repeating group authorable
with bounds and an order, the distinction between an update and a
correction, the choice about merging consecutive edits, and a single form
over a record's file metadata.

## What changes

- **A repeating group is a declared property kind.** A group names its
  member properties, a minimum and a maximum count, whether the order is
  meaningful, and which member is the label. Each item validates the way an
  object validates, and a violation names the item's position.
- **A correction is its own act.** Correcting a core field after the fact
  requires the correction right, requires a reason, and is recorded as a
  correction rather than as an ordinary update. The audit entry carries the
  reason and both values.
- **Incompleteness is recordable.** A property may be marked as not
  supplied with a declared reason from an administered list, which is
  different from empty and survives validation that would otherwise demand
  a value.
- **Consecutive edits merge only when an administrator says so.** The
  aggregation window is administered and defaults to no merging, so the
  order of two edits is never merged away by default. When a window is
  set, the merged entry names how many edits it covers.
- **A record's file metadata is corrected in one form.** Every file on an
  object, with its name and description, edited and saved together, as one
  audit entry per file changed.

## Consumers

- **dossiq**: the case-type editor exposes the repeating group, and the
  Beheeracties block becomes the correction act rather than a status-only
  service.
- **humaniq, pipelinq, keepiq**: repeating groups on any schema with no
  work per app.
- **filinq**: the file metadata form over the files it renders.
- **portaliq**: a recorded incompleteness is what lets a citizen submit a
  form honestly rather than typing "onbekend".

## ADRs

- ADR-031: the group, its bounds and the incompleteness reasons are
  declared on the schema.
- ADR-022: the property vocabulary is the object layer's, and an app that
  wants a repeating group declares one.
- ADR-003: a correction is an audit fact carrying its reason, and a merged
  entry says what it merged.
- ADR-010: correcting is its own verb, not an ordinary update.

## Impact

- Extends: `runtime-schema-api` (the repeating group and recorded
  incompleteness) and `enhanced-audit-trail` (the correction act and the
  aggregation window).
- Affected code: the property definition validator, the save pipeline's
  per-item validation, the audit writer, the file metadata endpoints.
- Backwards compatible: an existing array of objects keeps working and
  gains bounds only when it declares them, and no aggregation happens
  unless an administrator sets a window.
- Size: M.

## Out of scope

- The form layout that renders a repeating group, which is CT-6 and
  buildiq's under D16.
- Bulk correction across many records, which is `bulk-action-jobs` plus the
  import preview.
