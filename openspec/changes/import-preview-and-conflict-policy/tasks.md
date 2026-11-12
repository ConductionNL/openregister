# Tasks: import-preview-and-conflict-policy

## 1. The mapping

- [x] 1.1 A column mapping authored against a schema, with the first rows shown as mapped. The saved mapping is `migration-mapping-packs`' own; the preview shows every row as mapped, which is the first rows and then some.
- [x] 1.2 A mapping saved under a name and reusable; an unknown property refuses it (`SchemaMappingCheck`, applied when the mapping is bound to a schema).

## 2. The preview

- [x] 2.1 A preview reporting created, updated, skipped and refused with reasons, writing nothing (D-1).
- [x] 2.2 The write applies the preview's decisions; a changed file is refused (D-1).
- [ ] 2.3 Both run through `bulk-action-jobs` with progress (D-6). PARTIAL: both run off the request as a queued job (`ImportPreviewRunner`, `async=true`) against a job-shaped record with progress counters and a per-row outcome table. They do not yet run through the `bulk-action-jobs` engine itself, whose selection is a set of existing objects rather than a set of file rows; folding the two together is the second PR.

## 3. The conflict policy

- [x] 3.1 `create-only`, `update-only`, `upsert` and `refuse-on-conflict`, with a declared match key (D-2).
- [x] 3.2 A row matching more than one object is refused, naming the candidates, under every policy (D-3).
- [x] 3.3 An import declaring no policy keeps the current upsert behaviour, with a regression test.

## 4. The copy before destruction

- [ ] 4.1 A configured location outside the application, written before any destruction (D-4).
- [ ] 4.2 The recorded destruction names the copy.
- [ ] 4.3 A destruction whose copy fails does not run, and the failure names the location.

## 5. Instance serialisation

- [ ] 5.1 Serialise registers, schemas, objects, files and configuration as a portable set (D-5).
- [ ] 5.2 Load a set into another instance, as a bulk job with a per-row outcome.
- [ ] 5.3 Exclude secrets and record the exclusion in the set.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/import-preview.spec.ts`: map a file, preview it, see the counts, run it, see the rows.
- [ ] 6.2 Unit tests: the four policies, the double match refusal, the changed-file refusal, the failed copy, the secret exclusion. PARTIAL: the policies, the double match and the changed file are covered; the failed copy and the secret exclusion belong to sections 4 and 5.
- [x] 6.3 `openspec validate import-preview-and-conflict-policy --strict`.

## 7. Hand over

- [ ] 7.1 Hand the preview and the policy to integriq's `migration-source-adapters` (integriq#2001) as the target its source adapters write into, with candidate ids C-configuration-16, C-configuration-88, C-configuration-95, C-integrations-22 and C-integrations-50.
- [ ] 7.2 Tell the filinq lane that the copy before destruction is what a vernietiging record points at.

## 8. A whole base in one step (REQ-IPC-006, row x-import-product)

- [ ] 8.1 `lib/Service/Import/BaseSetReader.php`: read a base export archive into a neutral set of tables, columns, column types and link columns, with one reader per format (`AirtableBaseReader`, `BaserowBaseReader`, `NocoDbBaseReader`) behind a `BaseSetReaderInterface` that integriq's source adapters can also produce. Unit test each reader with a small fixture archive under `tests/Fixtures/import/`.
- [ ] 8.2 `lib/Service/Import/BaseSchemaProposer.php`: turn a set into a proposed register, one schema per table and a relation per link column, reusing the column type detection of the existing import; a link to a row outside the set becomes a per-row outcome, never a failure. Unit test: the two-table fixture gives two schemas and one relation; a dangling link is reported.
- [ ] 8.3 Extend `lib/Controller/ImportPreviewController.php` and `lib/BackgroundJob/ImportPreviewRunner.php`: a base upload creates the proposal, the confirmed proposal saves the register and schemas, then runs one preview per table in dependency order and resolves links after all tables are written; routes `importPreview#proposeBase` and `importPreview#confirmBase` in `appinfo/routes.php` with `#[NoAdminRequired]` plus a register create check.
- [ ] 8.4 Import screen: add a "Whole base" option to `src/modals/register/ImportRegister.vue` that uploads the archive, shows the proposed schemas and relations for confirmation and then the per-table outcome; `tests/e2e/ci/import-base.spec.ts` imports the two-table fixture.
- [ ] 8.5 Hand the `BaseSetReaderInterface` set shape to integriq's `migration-source-adapters` (integriq#2001) so a running installation read by an adapter lands on the same path.

## Where this stopped

Size L, so the first PR ships a capability that stands on its own: the
mapping bound to a schema, the preview, the four policies, the refusal record
and their tests. Sections 4 (the copy before destruction) and 5 (the instance
serialisation) are untouched and keep their own requirements in the delta;
they continue on a second branch, as does task 2.3.
