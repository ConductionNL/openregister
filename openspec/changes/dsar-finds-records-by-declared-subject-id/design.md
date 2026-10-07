# Design: dsar-finds-records-by-declared-subject-id

No screen. OpenRegister is not one of the canvas apps.

## D-1: two sources, one result

`discover()` runs `matchEntities()` as today and, beside it, `matchDeclaredFields()`. Results are grouped per object uuid. An object found by both carries both `gdprEntities` and `subjectFields`. The envelope the callers return keeps its shape and gains `subjectFields`.

## D-2: which schemas are searched

The schemas whose configuration carries `x-openregister-processing.subjectIdFields`, found once per request through the schema mapper. For each, the object query on that schema's table with `<field> = <subjectId>` for each declared field (or only the fields under the requested `type`), `_rbac` and `_multitenancy` on, as the entity source loads its objects. A list-valued field matches when it contains the id.

Exact match only. `mode: ilike` keeps applying to the entity source; a fuzzy match on an id field would find other people's records.

## D-3: one reader for the dialect

`ProcessingLogService::readAnnotation()` already normalises the dialect and its legacy shorthand. It moves to a small `ProcessingAnnotationReader` that both services use, so the DSAR and the read log cannot disagree about which field is the subject id.

## D-4: erasure

`erase()` and the erasure preview get the merged set. An object found only through a declared field is pseudonymised by clearing or replacing the declared field and the PII the entity index has for it, through the same audited path and legal-hold check as today.
