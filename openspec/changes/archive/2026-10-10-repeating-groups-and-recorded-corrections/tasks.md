# Tasks: repeating-groups-and-recorded-corrections

## 1. The repeating group

- [x] 1.1 A declared group property: members, minimum, maximum, ordered, label member (D-1).
- [x] 1.2 Per-item validation, with the position and member named on a violation (D-2).
- [x] 1.3 Bounds enforced on write; an existing array of objects keeps working, with a regression test.

## 2. Recorded incompleteness

- [x] 2.1 A not-supplied state with an administered reason, distinguishable from empty (D-4).
- [x] 2.2 Not supplied satisfies a required-value rule.

## 3. The correction

- [x] 3.1 A correction right and a required reason (D-3).
- [x] 3.2 The audit entry recorded as a correction with the reason and both values.
- [x] 3.3 The trail filterable to corrections.

## 4. Aggregation and file metadata

- [x] 4.1 No aggregation by default; an administered window that names how many edits it merged (D-5).
- [x] 4.2 One form over every file's name and description on an object, one audit entry per changed file (D-6).

## 5. Tests

- [x] 5.1 `tests/e2e/ci/repeating-groups.spec.ts`: two items saved, a positioned violation, a refused fourth item, a correction with a reason.
- [x] 5.2 Unit tests: not supplied against a required rule, the default no-merge, the merged entry's count, the file form writing only changed entries.
- [x] 5.3 `openspec validate repeating-groups-and-recorded-corrections --strict`.

## 6. Hand over

- [x] 6.1 Hand the group kind to the dossiq lane for the case-type editor, with candidate ids C-case-core-18, C-case-core-27, C-case-core-32 and C-documents-31.
- [x] 6.2 Tell the buildiq lane that rendering a repeating group is CT-6's half.

### The contract dossiq consumes

**Declaring a repeating group.** On a schema property:

```json
"gemachtigden": {
  "type": "array",
  "repeatingGroup": true,
  "groupOrdered": true,
  "groupLabel": "naam",
  "minItems": 1,
  "maxItems": 3,
  "items": {
    "type": "object",
    "required": ["naam"],
    "properties": { "naam": {"type": "string"}, "rol": {"type": "string"} }
  }
}
```

The members are `items.properties` and the bounds are `minItems` and
`maxItems`, so the shape is the array of objects OpenRegister already stored.
Three keys are new and all three are published in the property vocabulary at
`GET /api/schemas/property-vocabulary`, which is where a generated property
editor reads them from. A refusal names the row counted from one and the
member: `Row 2 of 'gemachtigden' is missing 'naam'.` The violation list carries
`property`, `position`, `member` and `code`, so a form can put the message on
the field rather than at the top of the page.

**Recording that a value was not supplied.** The schema administers the reasons
under `configuration["x-openregister-not-supplied-reasons"]`, a map of code to
label. An object then carries `"@notSupplied": {"bsn": "onbekend_bij_aanvrager"}`
beside `@self`. That state satisfies a required rule, is distinguishable from
empty, and reads back with the object.

**Correcting a value.** `POST /api/objects/{register}/{schema}/{id}/correct`
with `{"reason": "...", "values": {"bsn": "111222333"}}`. It needs the
`object.correct` right, seeded admin-only. The trail records it as
`action: correction`, readable back at
`GET /api/objects/{register}/{schema}/{id}/audit-trails?action=correction`,
and the entry's `changed.correction` carries the reason, the right and both
values per field. That is what the Beheeracties block becomes: a correction
act, not a status-only service.

**The aggregation window.** `GET` and `PATCH /api/settings/audit-aggregation`,
admin-only, `windowSeconds` defaulting to zero. Zero is no merging, which is
the default on every instance. A merged entry carries
`changed.aggregation.edits`.

**The file metadata form.** `PUT /api/objects/{register}/{schema}/{id}/files/metadata`
with `{"files": [{"fileId": 1, "name": "...", "description": "..."}]}`. It
answers `changed`, `unchanged` and `failed`, and writes one audit entry
(`file.metadata_corrected`) per file actually changed.

### Where this continues

- **Rendering** a repeating group is CT-6 and belongs to buildiq under D16.
  Everything above is the declaration and the write path; no form layout ships
  here.
- **The pending half** of the property cluster is
  `fields-a-user-adds-and-choices-a-record-narrows` (rows 11.45 and 11.47). It
  is a different cluster and nothing in it was built here. It shares only the
  property vocabulary, which this change extends by three keys rather than
  reshaping.
- **Bulk correction** across many records stays with `bulk-action-jobs` plus
  the import preview, as the proposal's out-of-scope section says.
