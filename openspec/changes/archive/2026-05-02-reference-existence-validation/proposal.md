# reference-existence-validation Specification

## Why

OpenRegister stores cross-schema relations as UUIDs in `$ref` properties. Without an existence check, schemas can accumulate dangling references when target objects are deleted, when imports race the data they reference, or when a typo lands a UUID that was never persisted. Downstream consumers (link rendering in the UI, audit reports, sync jobs) then have to defensively skip-or-warn on every miss. Government compliance audits (BIO, archival workflows) also require us to demonstrate that referenced records actually exist at save time. A configurable `validateReference: true` per property closes the gap without forcing the cost on schemas that don't need it.

## What Changes

- Recognise `validateReference: true` on schema properties; absence of the flag short-circuits the check.
- Resolve target schema via existing `$ref` resolution (`#/components/schemas/{slug}`); array-shaped properties (`type: array, items: {$ref}`) are normalised to a UUID list and each element is validated.
- Inherit register context from the property (`$property['register']`) or fall back to the referrer's register.
- Skip re-validation on update when the reference value is unchanged (`$oldData[$propertyName] === $value`).
- Throw `ReferenceValidationException` (subclass of `ValidationException`, HTTP 422) carrying typed fields (`getPropertyName()`, `getReferencedUuid()`, `getTargetSchemaSlug()`, `getTargetRegister()`) plus a `toArray()` shape for direct JSON rendering.
- Add admin bypass: `SaveObject::shouldBypassValidationForAdmin()` short-circuits when the session user is in the `admin` group AND the `reference_validation_admin_bypass` app-config flag (default `true`) is on; operators can flip it off.
- Dispatch `ReferenceValidatedEvent` on every successful UUID lookup and `ReferenceValidationFailedEvent` immediately before throwing on rejection; both extend `OCP\EventDispatcher\Event` and expose typed fields. Listener exceptions are caught and logged so a misbehaving listener cannot block a save.
- Treat soft-deleted targets as nonexistent — make `includeDeleted: false` explicit in the lookup path.
- Add a per-import cache keyed on `(targetSchemaId, uuid)` so 1000-row imports don't issue 1000+ lookups.
- Detect circular reference chains (A→B→A) via a per-save visited set.
- Support external URL references with configurable validation (HTTP/HTTPS HEAD or skip).
- Cache validation results within request scope so repeated lookups in one save are free.
- Route GraphQL mutations through the same `SaveObject::saveObject` path so they get the same validation.
- Support async validation for large batch operations, with a way to surface async errors back to the client.
- Support schema-configurable strictness levels (`warn` / `error` / `block`) instead of the current binary on/off.

## Problem
Add configurable validation that ensures objects referenced via `$ref` properties actually exist before saving. When a schema property has `$ref` pointing to another schema and `validateReference` is enabled, the save pipeline checks that the UUID stored in that property corresponds to an existing object in the target schema.

## Proposed Solution
Add configurable validation that ensures objects referenced via `$ref` properties actually exist before saving. When a schema property has `$ref` pointing to another schema and `validateReference` is enabled, the save pipeline checks that the UUID stored in that property corresponds to an existing object in the target schema. This spec covers the full lifecycle of reference existence checking: single-object saves, bulk imports, GraphQL mutations, soft-deleted reference handling, circular referen
