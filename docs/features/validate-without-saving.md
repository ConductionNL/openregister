---
title: Validate a record without saving it
description: Ask whether a sample record would be accepted, and on which properties not, without writing anything.
keywords:
  - Open Register
  - Validation
  - Release tests
---

# Validate a record without saving it

A release test, a form or an integration can ask OpenRegister whether a record **would be accepted**, and if not on which properties, without saving it. The answer comes from the same checks a save runs, so validate says invalid exactly when a save would refuse the record for one of those rules.

## API

```http
POST /api/objects/{register}/{schema}/validate
POST /api/objects/{register}/{schema}/validate?id={object}
```

The body is the sample record. Without `id` it is checked as a **create**; with `id` it is merged onto that stored object and checked as an **update** of it.

The answer is `200` for a well-formed request, valid or not:

```json
{
  "valid": false,
  "errors": [
    { "property": "gemeente", "message": "Atlantis is not in the list.", "rule": "coded-value" }
  ]
}
```

| Status | When |
|---|---|
| 200 | The verdict, `valid` true or false. |
| 403 | The caller may not create in this schema, or may not update the named object. A verdict can reveal whether a unique value exists, so it asks the same right a save would. |
| 404 | The register or schema does not exist, or the named object cannot be read by the caller. |

Nothing is written: no object, no audit entry, no object event, no webhook.

## What is checked

Each error names the rule that refused it:

| `rule` | What refused the property |
|---|---|
| `write-rule` | Declared bounds, repeating groups, geometry, read-only fields on an update, lenses. These name no single property (`property` is `null`). |
| `schema` | The schema validator, when the schema validates hard or the record is a draft (as on save). |
| `unique` | A unique field whose value another object already holds. |
| `field-type` | An extended field type (colour, recurrence). |
| `coded-value` | A value outside its coded list. |
| `concept-shape` | A concept that does not match its scheme's declared shape. |
| `dependent-value` | A value its dependent-value table does not allow for the controlling property. |

The coded-value and dependent-value rules run through `ObjectSaveCheck`, the same check their save listeners call, so the two cannot disagree. A rule whose own machinery fails refuses nothing, exactly as the save then goes ahead.

Other listeners that can refuse a save (administered validation rules, decision tables, state rules, quota) do not yet implement `ObjectSaveCheck`; until they do, validate does not report them.

## From PHP

Apps that consume the published contract call:

```php
$verdict = $objectService->validateObject(object: $sample, register: 'zaken', schema: 'aanvraag', id: null);
$verdict->isValid();   // bool
$verdict->errors;      // list of {property, message, rule}
```

`OCA\OpenRegister\Contract\ObjectServiceInterface::validateObject()` returns an `ObjectValidationVerdict`.
