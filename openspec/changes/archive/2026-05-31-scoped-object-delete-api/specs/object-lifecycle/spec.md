## ADDED Requirements

### Requirement: The delete pipeline MUST honour register/schema scope when both are supplied

When a caller invokes `ObjectService::deleteObject(string $uuid, Register|string|int|null $register, Schema|string|int|null $schema, ...)` with both `$register` and `$schema` non-null, the delete pipeline MUST resolve the UUID using the scoped path that targets exactly one magic table (`MagicMapper::find($identifier, $register, $schema, ...)`). The pipeline MUST NOT fall back to `findAcrossAllSources()` / `findAcrossAllMagicTables()` when the caller has expressed a scope. A UUID that exists in a different `(register,schema)` scope MUST raise `DoesNotExistException`, and the pipeline MUST NOT mutate any row in any magic table.

When the caller omits one or both of `$register` / `$schema`, the legacy unscoped lookup (`findAcrossAllSources`) MUST remain in force so existing call sites continue to work; the unscoped form is soft-deprecated in the docblock.

`ObjectServiceMapperAdapter::delete(array $criteria)` MUST forward the adapter's own bound `(register, schema)` to `ObjectService::deleteObject()`. The array form MUST NOT collapse to an unscoped delete when the adapter itself is scoped.

The audit-trail row recorded by the scoped delete MUST capture both the `register` and `schema` of the deleted object, so the audit log distinguishes "deleted UUID X from `gemeente`/`meldingen`" from "deleted UUID X from `landelijk`/`meldingen`" even when the UUID is identical.

#### Scenario: Scoped delete refuses cross-scope UUID
- **GIVEN** an object with UUID `abc-123` exists in magic table `oc_openregister_table_1_5` (register `openconnector`, schema `source`)
- **AND** no object with UUID `abc-123` exists in register `softwarecatalogus` / schema `application`
- **WHEN** a caller invokes `ObjectService::deleteObject(uuid: 'abc-123', register: 'softwarecatalogus', schema: 'application')`
- **THEN** the pipeline MUST raise `DoesNotExistException`
- **AND** the row in `oc_openregister_table_1_5` MUST remain present and unmodified
- **AND** no audit-trail row MUST be recorded

#### Scenario: Scoped delete succeeds when UUID is in the requested scope
- **GIVEN** an object with UUID `abc-123` exists in magic table for register `openconnector` / schema `source`
- **WHEN** a caller invokes `ObjectService::deleteObject(uuid: 'abc-123', register: 'openconnector', schema: 'source')`
- **THEN** the pipeline MUST locate the object via the scoped `MagicMapper::find()` path (no cross-table scan)
- **AND** the row MUST be deleted from the `(openconnector, source)` magic table
- **AND** an audit-trail row MUST be recorded with `register=openconnector` and `schema=source`

#### Scenario: Cross-magic-table UUID collision touches only the matching scope
- **GIVEN** two distinct objects share UUID `dup-uuid`: one in register `A` / schema `X`, another in register `B` / schema `Y`
- **WHEN** a caller invokes `ObjectService::deleteObject(uuid: 'dup-uuid', register: 'B', schema: 'Y')`
- **THEN** only the row in the `(B, Y)` magic table MUST be deleted
- **AND** the row in the `(A, X)` magic table MUST remain present and unmodified

#### Scenario: Legacy unscoped delete remains unchanged
- **GIVEN** a caller invokes `ObjectService::deleteObject(uuid: 'abc-123')` with no register/schema (the pre-existing form)
- **WHEN** the pipeline runs
- **THEN** the legacy `findAcrossAllSources()` lookup MUST be used (preserves backward compatibility)
- **AND** the row MUST be deleted if found in any magic table
- **AND** the docblock `@deprecated` notice MUST point callers at the scoped form

#### Scenario: Adapter forwards bound scope to the service
- **GIVEN** an `ObjectServiceMapperAdapter` bound to register `openconnector` / schema `source`
- **AND** an object with UUID `abc-123` in a different scope (register `softwarecatalogus` / schema `application`)
- **WHEN** the adapter's `delete(['id' => 'abc-123'])` is called
- **THEN** the adapter MUST forward `(openconnector, source)` to `ObjectService::deleteObject()`
- **AND** the call MUST raise `DoesNotExistException` because `abc-123` is not in the bound scope
- **AND** the `softwarecatalogus` / `application` row MUST remain present and unmodified
