# Data subject requests find records through declared subject id fields

A data subject request (access export, erasure, the erasure preview) finds a person's records in two ways:

1. **The PII entity index** — what the entity detector recognised in the record's text: names, e-mail addresses, BSNs.
2. **Declared subject id fields** — the fields a schema says hold the data subject's id.

The second source exists because an internal id is never detected. An app that keys its records on a person's uuid (a learner, an employee, a contact, a party) would otherwise see those records missing from an access export and surviving an erasure.

## Declaring the field

Add `subjectIdFields` to the schema's `x-openregister-processing` block: a map from an id type to the property that holds it.

```json
{
  "x-openregister-processing": {
    "subjectIdFields": {
      "learnerId": "learnerId"
    }
  }
}
```

The same map already names the subject in the read log; one reader (`ProcessingAnnotationReader`) serves both, so they cannot disagree about which field is the subject id.

## How the field is matched

- **Exactly.** A request in `ilike` mode still matches a declared id field exactly: a fuzzy match on an id would find other people's records.
- **By type.** A request with a type (`assembleAccessExport($id, 'learnerId')`) searches only the fields declared under that type.
- **Lists.** A list-valued field matches when it contains the id.
- **Scoped.** The search runs with RBAC and tenant scoping on, and every object found is loaded scoped again, so a handler never receives a record they may not read.

## What the result records

Each object in the result says why it was included:

```json
{
  "object": { "...": "..." },
  "gdprEntities": [],
  "subjectFields": [{ "type": "learnerId", "field": "learnerId" }]
}
```

An object found by both sources carries both `gdprEntities` and `subjectFields`; it is listed once.

## Erasure

An erasure works over the merged set. Legal holds and archival obligations are honoured exactly as for records from the entity index. In pseudonymise mode the declared field (and, in a list, the matching element) is replaced by `[erased]`, through the same audited write path.

## Out of scope

Following an id through references: a result record that references an enrolment holding `learnerId` is not found through that reference. A schema whose records should be found declares its own subject id field.
