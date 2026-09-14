# Tasks: export-as-its-own-right

## 1. The verb

- [ ] 1.1 An `export` verb in the authorization layer, evaluated beside read (D-1).
- [ ] 1.2 Every export path checks it, the API included; the refusal names the verb.
- [ ] 1.3 A migration granting export wherever read is granted, stated in the release note (D-2).

## 2. The profile

- [ ] 2.1 An export profile object: name, ordered field set, value mode, format, optional filter (D-3).
- [ ] 2.2 The field set is independent of any saved view's columns.
- [ ] 2.3 Value mode `stored` and `rendered`, with the mode written into the export's metadata (D-4).

## 3. Schedule and whole-set extract

- [ ] 3.1 A profile runs on a schedule through the scheduled report runner, with the owner's access.
- [ ] 3.2 A whole-set profile runs through `bulk-action-jobs`, one file per schema, with progress and skips (D-5).

## 4. The record

- [ ] 4.1 One audit entry per completed export: actor, profile, row count, time (D-6).
- [ ] 4.2 A refused export recorded with its reason.

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/export-profile.spec.ts`: a read-only principal refused, a profile with its own field order, a rendered export.
- [ ] 5.2 Unit tests: the verb on every path, the upgrade default, both value modes, the metadata line, the audit entries.
- [ ] 5.3 `openspec validate export-as-its-own-right --strict`.

## 6. Hand over

- [ ] 6.1 Hand the profile and the verb to the dossiq lane for `case-list-export-via-or-export-leaf`, with the ten candidate ids.
