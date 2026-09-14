# Tasks: import-preview-and-conflict-policy

## 1. The mapping

- [ ] 1.1 A column mapping authored against a schema, with the first rows shown as mapped.
- [ ] 1.2 A mapping saved under a name and reusable; an unknown property refuses it.

## 2. The preview

- [ ] 2.1 A preview reporting created, updated, skipped and refused with reasons, writing nothing (D-1).
- [ ] 2.2 The write applies the preview's decisions; a changed file is refused (D-1).
- [ ] 2.3 Both run through `bulk-action-jobs` with progress (D-6).

## 3. The conflict policy

- [ ] 3.1 `create-only`, `update-only`, `upsert` and `refuse-on-conflict`, with a declared match key (D-2).
- [ ] 3.2 A row matching more than one object is refused, naming the candidates, under every policy (D-3).
- [ ] 3.3 An import declaring no policy keeps the current upsert behaviour, with a regression test.

## 4. The copy before destruction

- [ ] 4.1 A configured location outside the application, written before any destruction (D-4).
- [ ] 4.2 The recorded destruction names the copy.
- [ ] 4.3 A destruction whose copy fails does not run, and the failure names the location.

## 5. Instance serialisation

- [ ] 5.1 Serialise registers, schemas, objects, files and configuration as a portable set (D-5).
- [ ] 5.2 Load a set into another instance, as a bulk job with a per-row outcome.
- [ ] 5.3 Exclude secrets and record the exclusion in the set.

## 6. Tests

- [ ] 6.1 `tests/e2e/ci/import-preview.spec.ts`: map a file, preview it, see the counts, run it, see the rows.
- [ ] 6.2 Unit tests: the four policies, the double match refusal, the changed-file refusal, the failed copy, the secret exclusion.
- [ ] 6.3 `openspec validate import-preview-and-conflict-policy --strict`.

## 7. Hand over

- [ ] 7.1 Hand the preview and the policy to integriq's `migration-source-adapters` (integriq#2001) as the target its source adapters write into, with candidate ids C-configuration-16, C-configuration-88, C-configuration-95, C-integrations-22 and C-integrations-50.
- [ ] 7.2 Tell the filinq lane that the copy before destruction is what a vernietiging record points at.
