---
kind: code
depends_on: []
---
# Proposal: dsar-finds-records-by-declared-subject-id

## Summary

A learner asks for their data. learniq keys every record about a learner on `learnerId`, a uuid, and declares it on the schema as the data subject's id (`x-openregister-processing.subjectIdFields`). OpenRegister's `DataSubjectRequestService` finds a subject only through the PII entity index, which holds what the entity detector recognised in text: names, e-mail addresses, BSNs. A uuid is not detected, so the learner's enrolments, results and attendance never appear in the access export and survive an erasure. This change makes the service also find a subject's records through the fields a schema declares as subject ids.

## Halves this closes

learniq #1787: an access request for a learner returns nothing from the schemas keyed on `learnerId`. The same holds for every app that keys records on an internal person id: humaniq (employee id), pipelinq (contact uuid), dossiq (party uuid). No row in OpenRegister's matrix; this is a defect in the delivered `data-subject-rights-across-the-instance`.

## What is there

- `lib/Service/Gdpr/DataSubjectRequestService.php`: `findSubjectData()` (`:146`), `findSubjectObjects()` (`:194`), `assembleAccessExport()` (`:263`) and `erase()` (`:339`) all go through `discover()` (`:690`), which calls only `matchEntities()` (`:731`): the `openregister_entities` index joined to `openregister_entity_relations`.
- `x-openregister-processing.subjectIdFields` is a map from an id type to a property (`{"BSN": "bsn", "learnerId": "learnerId"}`), read by `ProcessingLogService::readAnnotation()` (`lib/Service/ProcessingLogService.php:341`) and used to name the subject in the read log (`extractSubject()`, `:434`). Nothing else reads it.

## What changes

- `discover()` adds a second source: for every schema whose `x-openregister-processing.subjectIdFields` declares a field, the objects whose value in that field equals the subject id (exact match). With a `type`, only fields declared under that type are searched.
- Hits from both sources are merged per object. Each hit says why it was included: an entity (`gdprEntities`, as today) or a declared field (`subjectFields: [{type, field}]`), so the export still records what triggered each inclusion.
- RBAC and tenant scoping apply to the new source exactly as to the old one; erasure keeps honouring legal holds.
- The declared-field search reads only the schemas that declare `subjectIdFields`, one query per schema, through the object query on the schema's own table.

## ADRs

- ADR-022: the schema declares who a record is about; apps do not run their own DSAR discovery.
- ADR-005: the second source returns nothing the caller may not read.
- AVG art. 15, 17, 20: an access export or an erasure that misses records keyed on an internal id is incomplete.

## Impact

- Extends `gdpr-data-subject-rights`.
- Affected code: `lib/Service/Gdpr/DataSubjectRequestService.php`; the `subjectIdFields` reader shared with `ProcessingLogService`.
- Backwards compatible: a schema without `subjectIdFields` is searched as today.
- Size: S.

## Out of scope

- Following a subject id through references (a result record referencing an enrolment that holds `learnerId`). A schema that wants its records found declares its own subject id field.
- The save-time validator for the `x-openregister-processing` dialect: `processing-activity-register` task 2.1.
