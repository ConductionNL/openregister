# reference-existence-validation (delta)

## ADDED Requirements

### Requirement: Reference-validation exceptions MUST expose a structured diagnostic payload
The two `ValidationException` subclasses raised on the reference-existence path (`ReferenceValidationException`, `CircularReferenceException`) MUST expose their diagnostic data as a structured `toArray()` payload and via individual getters, so that controllers can surface a machine-readable error without re-parsing the human-readable message. Both subclasses MUST default the HTTP code to 422 and MUST build a structured default message from their fields when no explicit message is supplied.

This is the exception-level contract that the HTTP 422 `details` envelope (see
"Validation error reporting MUST include structured diagnostic information") is
mapped from; the key names differ deliberately because `toArray()` is the
internal diagnostic shape and `details` is the public API envelope.

#### Scenario: ReferenceValidationException toArray shape

- GIVEN a `ReferenceValidationException` constructed with
  `propertyName = "assignee"`, `referencedUuid = "nonexistent-uuid"`,
  `targetSchemaSlug = "person"`, `targetRegister = "procest"`
- WHEN `toArray()` is called
- THEN it MUST return exactly the keys `propertyName`, `referencedUuid`,
  `targetSchemaSlug`, `targetRegister`, `message`, `code`
- AND `code` MUST be `422`
- AND `message` MUST be
  `"Referenced object 'nonexistent-uuid' not found in schema 'person' for property 'assignee'"`
  when no explicit message was supplied
- AND `getPropertyName()`, `getReferencedUuid()`, `getTargetSchemaSlug()`, and
  `getTargetRegister()` MUST each return the corresponding constructor value
  unchanged

#### Scenario: CircularReferenceException toArray shape

- GIVEN a `CircularReferenceException` constructed with
  `referencedUuid = "obj-a"`, `targetSchemaSlug = "incident"`, and a non-empty
  `cycle` array of `(register, schema, uuid)` entries
- WHEN `toArray()` is called
- THEN it MUST return exactly the keys `referencedUuid`, `targetSchemaSlug`,
  `cycle`, `message`, `code`
- AND `code` MUST be `422`
- AND `cycle` MUST contain the visited chain that triggered detection
- AND `getReferencedUuid()`, `getTargetSchemaSlug()`, and `getCycle()` MUST each
  return the corresponding constructor value unchanged

#### Scenario: Default message built from fields

- GIVEN a `CircularReferenceException` constructed with no `message` argument
- WHEN the exception is inspected
- THEN `getMessage()` MUST be
  `"Circular reference detected for object '<referencedUuid>' in schema '<targetSchemaSlug>'"`
