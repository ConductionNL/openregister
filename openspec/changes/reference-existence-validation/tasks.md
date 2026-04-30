# Tasks: reference-existence-validation Specification

> **Status (Phase 2):** Core (6 of 16) implemented in `SaveObject::validateReferences()` and verified by `tests/Service/ReferenceExistenceValidationIntegrationTest` (now 6 tests). Phase 2 added structured diagnostic information by introducing `ReferenceValidationException` (subclass of `ValidationException`) with typed fields. The remaining 10 are extensions — batch optimisation, circular detection, GraphQL/async/events/strictness — left open as separate work.

## Implemented

- [x] Schema properties support a `validateReference: true` configuration. Detected via `($property['validateReference'] ?? false) !== true` short-circuit in `SaveObject::validateReferences()`.
- [x] Save rejects objects with invalid references when `validateReference` is enabled. `validateReferenceExists()` throws `ValidationException` (HTTP 422) when the referenced UUID is not found. **Verified live:** `testNonExistentUuidReferenceIsRejected` saves a referrer with a UUID that was never persisted and asserts `ValidationException` is thrown.
- [x] Reference validation resolves target schema via existing `$ref` resolution. Reads `$property['$ref'] ?? $property['items']['$ref']`, then `resolveSchemaReference()` walks the standard `#/components/schemas/{slug}` form. Array-shaped properties (`type: array, items: {$ref}`) are normalised to a UUID list and each element is validated.
- [x] Reference validation works with the object's register context. `targetRegister = $property['register'] ?? $register` — property-level override falls back to the referrer's register. **Verified live:** the integration test sets up two schemas in the same register and the lookup finds the target via the inherited register context.
- [x] Reference validation does NOT impact update operations for unchanged references. `if ($oldData[$propertyName] === $value) continue;` skips re-validation when the reference value is unchanged. **Verified live:** `testUpdateWithUnchangedReferenceSkipsValidation` saves a referrer with a valid target, deletes the target, then updates an unrelated field on the referrer — the update succeeds because the reference was not re-validated.

- [x] Validation error reporting includes structured diagnostic information. `lib/Exception/ReferenceValidationException.php` (subclass of `ValidationException`) exposes typed fields (`getPropertyName()`, `getReferencedUuid()`, `getTargetSchemaSlug()`, `getTargetRegister()`) plus a `toArray()` method for direct rendering as a JSON 422 envelope. `SaveObject::validateReferenceExists` now throws this subclass instead of raw `ValidationException`, preserving HTTP 422 semantics + `instanceof ValidationException` compatibility for existing 422 routing. **Verified live** by `testRejectionExceptionCarriesStructuredFields` (catches the exception, asserts every typed field is populated correctly + the array shape is the documented one + the subclass still matches `instanceof ValidationException` for backwards compatibility).

## Open

- [ ] Soft-deleted references treated as nonexistent. `validateReferenceExists` currently checks existence via the routing mapper, which respects `includeDeleted: false` in most paths but should be made explicit. **Open** — needs verification + a dedicated test.
- [ ] Batch reference validation optimised for bulk imports. Today each reference is checked individually (one DB lookup per UUID). For 1000-row imports this is 1000+ queries. **Open** — would benefit from a per-import cache keyed on `(targetSchemaId, uuid)`.
- [ ] Circular reference chains detected during validation. **Open** — would need a per-save visited-set to detect cycles like A→B→A.
- [ ] External URL references support configurable validation. Today only `#/components/schemas/{slug}` refs are validated; HTTP/HTTPS refs aren't. **Open** — design question whether to fetch + verify external URLs at save time (latency) or accept them.
- [ ] Validation results cached within a request scope. **Open** — see batch optimisation above.
- [ ] Admin users able to bypass reference validation. **Open** — would mirror `MultiTenancyTrait::isCurrentUserAdmin()` short-circuit.
- [ ] Reference validation works in GraphQL mutations. **Open** — GraphQL mutation handler doesn't currently route through the same `SaveObject::saveObject` path; needs alignment.
- [ ] Async validation supported for large batch operations. **Open** — depends on batch optimisation + a way to surface async errors.
- [ ] Validation events dispatched for notification and extensibility. **Open** — would add `ReferenceValidatedEvent` / `ReferenceValidationFailedEvent` to the event family.
- [ ] Schema-configurable validation strictness levels. **Open** — currently it's binary (`validateReference: true` or absent). Strictness levels (`warn` / `error` / `block`) are a separate UX feature.

## Test coverage

- [x] `tests/Service/ReferenceExistenceValidationIntegrationTest` — 6 tests against real schema + Postgres:
  - valid UUID reference passes validation
  - non-existent UUID reference rejected with `ValidationException`
  - non-existent UUID rejection carries structured fields on `ReferenceValidationException` (propertyName / referencedUuid / targetSchemaSlug / targetRegister + toArray + still `instanceof ValidationException`)
  - null reference accepted (optional)
  - empty-string reference accepted (defence in depth)
  - update with unchanged reference skips validation
