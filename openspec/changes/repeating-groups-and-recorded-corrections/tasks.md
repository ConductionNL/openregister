# Tasks: repeating-groups-and-recorded-corrections

## 1. The repeating group

- [ ] 1.1 A declared group property: members, minimum, maximum, ordered, label member (D-1).
- [ ] 1.2 Per-item validation, with the position and member named on a violation (D-2).
- [ ] 1.3 Bounds enforced on write; an existing array of objects keeps working, with a regression test.

## 2. Recorded incompleteness

- [ ] 2.1 A not-supplied state with an administered reason, distinguishable from empty (D-4).
- [ ] 2.2 Not supplied satisfies a required-value rule.

## 3. The correction

- [ ] 3.1 A correction right and a required reason (D-3).
- [ ] 3.2 The audit entry recorded as a correction with the reason and both values.
- [ ] 3.3 The trail filterable to corrections.

## 4. Aggregation and file metadata

- [ ] 4.1 No aggregation by default; an administered window that names how many edits it merged (D-5).
- [ ] 4.2 One form over every file's name and description on an object, one audit entry per changed file (D-6).

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/repeating-groups.spec.ts`: two items saved, a positioned violation, a refused fourth item, a correction with a reason.
- [ ] 5.2 Unit tests: not supplied against a required rule, the default no-merge, the merged entry's count, the file form writing only changed entries.
- [ ] 5.3 `openspec validate repeating-groups-and-recorded-corrections --strict`.

## 6. Hand over

- [ ] 6.1 Hand the group kind to the dossiq lane for the case-type editor, with candidate ids C-case-core-18, C-case-core-27, C-case-core-32 and C-documents-31.
- [ ] 6.2 Tell the buildiq lane that rendering a repeating group is CT-6's half.
