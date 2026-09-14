---
kind: code
depends_on: [export-as-its-own-right, bulk-action-jobs, delete-window-and-recorded-destruction]
---

# Proposal: import-preview-and-conflict-policy

## Summary

A supported migration path out of a named competing product is the third
strongest capability in the sweep: eight driven passers, and dossiq has
none. Every member of this cluster is a `must` and four of the five are
matrix holes. This change adds the column mapping, the preview that says
what an import will change before it writes, a conflict policy, a dump
taken before anything is destroyed, and a serialisation of the whole
instance that another instance can load.

## Candidates and cluster

Cluster 8 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Migration in and
migration out". Owner openregister, size L, depends on export as its own
right, five candidates: C-configuration-16, C-configuration-88,
C-configuration-95, C-integrations-22 and C-integrations-50. All five are
`must` and four are matrix holes. Passers: 15, thirteen driven and two
documented. dossiq rates `partial` on one and `no` on four.

## The decisions this rests on

**D6, relevance-led promotion.** Four of the five are holes: a `must` for
a municipality with two or more driven passers and no row in the corpus.
Under D6 every one of them enters.

**D10 as taken.** openregister's existing soft delete is the recovery
window, and what it needs is a stated period and a recorded destruction.
The dump before destruction in this change writes the copy that recorded
destruction can point at, which is why it depends on
`delete-window-and-recorded-destruction` rather than inventing a second
delete model.

## Why

The proving system is openproject, cited by the configuration lane at
`configuration.tsv:57`: "Administration Import, /admin/import/jira,
resources :jira with post :test, twenty jobs under app/workers/import/
including jira_revert_import_job.rb". A running installation of the
product being replaced, imported, with a revert. That is
C-configuration-88, with forgejo, gitea, gitlab, kanboard, openproject,
otobo, vikunja and zammad driven. A gemeente replacing a zaaksysteem
migrates the running dossiers, not a spreadsheet, and dossiq's own lane
reads "zero hits".

The rest:

- **A file uploaded, its columns mapped, records created in bulk**
  (C-configuration-16, a hole): otobo, "Import and export
  (AdminImportExport.pm, DynamicFieldImportExport.pm, imexport_ seven
  tables, the free ImportExportTicket package)", and redmine. This is the
  migration and the bulk correction in one screen, and no row in 268 asks
  for either.
- **What an import will change, shown before it writes**
  (C-configuration-95, a hole): glpi, "Form export and import
  (Form/ExportController.php, Form/Import/Step1IndexController.php through
  Step4ExecuteController.php)", and valtimo. dossiq: "import exists via
  OpenRegister; no preview or conflict step found".
- **A restorable copy written outside the product before anything is
  destroyed** (C-integrations-22, a hole): request-tracker, "Admin, Tools,
  Shredder (share/html/Admin/Tools/Shredder/, 11 plugins, SQLDump.pm)",
  and tuleap. Archiefwet vernietiging owes a verklaring of what was
  destroyed, and an irreversible delete with no dump is the thing an
  archivaris refuses to sign.
- **The whole instance serialised and loaded into another one**
  (C-integrations-50): request-tracker, "sbin/rt-serializer.in,
  rt-importer.in". The exit strategy a gemeente's inkoop asks for.

**What exists here and does not close it.** `data-import-export`
specifies import from CSV, Excel, JSON and XML, bulk import over the API,
validation against the schema before insertion, detailed error reporting
with a downloadable error file, duplicate detection with idempotent
upsert, and progress tracking. `register-import-auto-create` and
`config-import-seed-objects` bring registers and seed objects in.
`migration-mapping-packs` is archived with an unwritten purpose. So
reading a file and writing rows is solved. What is missing is the step
before the write: nobody sees what is about to change, nothing states what
happens on a conflict beyond upsert, no copy is taken before a
destruction, and nothing serialises the instance as a whole.

## What changes

- **A column mapping is authored and saved.** An uploaded file's columns
  are mapped onto the schema's properties in a screen, with a preview of
  the first rows as mapped. The mapping is saved, named and reusable, so
  the monthly correction is one act.
- **An import is previewed before it writes.** The preview names how many
  rows would be created, updated, skipped and refused, with the reason per
  refused row, and it writes nothing. A preview is a bulk job, so a large
  file previews with progress.
- **A conflict policy is declared, not guessed.** Per import: create only,
  update only, upsert, or refuse on conflict. The match is on a declared
  key, and a row matching more than one object is refused rather than
  updating an arbitrary one.
- **An import runs from an approved preview.** The write uses the preview's
  own decisions, and a file that changed since the preview is refused.
- **A destruction takes a copy first.** Before an object or a file is
  destroyed, a restorable copy is written to a configured location outside
  the application, and the recorded destruction names the copy. A
  destruction whose copy cannot be written does not run.
- **The instance serialises and loads.** A complete serialisation of
  registers, schemas, objects, files and configuration, and a load of one
  into another instance, both as bulk jobs with progress and a per-row
  outcome. Secrets are excluded and the file says so.
- **A source adapter is integriq's.** This change owns the mapping, the
  preview, the policy and the writer. Reading a running installation of
  another product is a connector.

## Consumers

- **integriq**: builds the source adapters for the named competing
  products, writing into this preview and this policy rather than into the
  database.
- **dossiq**: the migration of running dossiers, and the bulk correction of
  a mis-registered field, in one screen.
- **filinq**: the copy before destruction is what the archiving process
  points at when it records a vernietiging.
- **every fleet app**: one import model, one preview, one policy.

## ADRs

- ADR-022: the import mechanism is the platform layer's, consumed by every
  leaf app; the source adapters are integriq's.
- ADR-005: a destruction whose copy cannot be written fails closed, and a
  row matching more than one object is refused rather than resolved.
- ADR-003: the import, the destruction and the copy are audit facts on the
  chain.
- ADR-009: the preview is a bounded job over batches, never one statement
  over the whole file.

## Impact

- Extends: `data-import-export` with the mapping, the preview, the policy
  and the instance serialisation.
- Affected code: the import service and its readers, a mapping object and
  its editor, the destruction path, the bulk job runner, the export writers
  this reuses.
- Backwards compatible: an import that declares no policy keeps the current
  upsert behaviour, and the preview is an added step rather than a required
  one for an API caller that asks to skip it.
- Size: L.

## Out of scope

- Reading a running installation of a competing product. That is an
  integriq connector, and this change is what it writes into.
- The delete window itself, which `delete-window-and-recorded-destruction`
  carries under D10 as taken.
- The archiving process and its sign-off, which
  `archiving-as-a-process-with-sign-off` carries under D7.
