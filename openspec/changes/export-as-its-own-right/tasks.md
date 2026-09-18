# Tasks: export-as-its-own-right

## 1. The verb

- [x] 1.1 An `export` verb in the authorization layer, evaluated beside read (D-1).
- [x] 1.2 Every export path checks it, the API included; the refusal names the verb.
  - The first branch gated `objects#export`, the export profile endpoints and
    the whole-set bulk action. Measured on the merge, three more paths carry
    object data off the instance and did not check: `tmlo#exportSingle`,
    `tmlo#exportBatch` and `objectRelations#exportGraph`. All three now go
    through `ExportGate`, which holds the check, the refusal shape and the
    audit entry in one place so the next path is one call rather than a fourth
    copy of the control.
  - **Deliberately not gated, and why.** `registers#export` and
    `configuration#export` take the schema definitions, not the objects, and
    are an administrator's act. `auditTrail#export`, `auditQuery#export` and
    `searchTrail#export` take trails, which have their own admin gate.
    `user#exportData`, `subjectExport#download` and `gdpr/access-export` are a
    data subject exercising their own right, and gating those behind an
    administered verb would let an instance switch off a right it does not
    grant. `flow#exportBpmn` takes a flow definition.
- [x] 1.3 A migration granting export wherever read is granted, stated in the release note (D-2).

## 2. The profile

- [x] 2.1 An export profile object: name, ordered field set, value mode, format, optional filter (D-3).
- [x] 2.2 The field set is independent of any saved view's columns.
- [x] 2.3 Value mode `stored` and `rendered`, with the mode written into the export's metadata (D-4).

## 3. Schedule and whole-set extract

- [x] 3.1 A profile runs on a schedule through the scheduled report runner, with the owner's access.
- [x] 3.2 A whole-set profile runs through `bulk-action-jobs`, one file per schema, with progress and skips (D-5).

## 4. The record

- [x] 4.1 One audit entry per completed export: actor, profile, row count, time (D-6).
- [x] 4.2 A refused export recorded with its reason.

## 5. Tests

- [x] 5.1 `tests/e2e/ci/export-profile.spec.ts`: a read-only principal refused, a profile with its own field order, a rendered export.
- [x] 5.2 Unit tests: the verb on every path, the upgrade default, both value modes, the metadata line, the audit entries.
- [x] 5.3 `openspec validate export-as-its-own-right --strict`.

## 6. Hand over

- [x] 6.1 Hand the profile and the verb to the dossiq lane for `case-list-export-via-or-export-leaf`, with the ten candidate ids.
