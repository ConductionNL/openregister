# Tasks: classification clearance on an object

## 1. Declaration

- [x] 1.1 Add `x-openregister-classification` to
      `SchemaSlugMap::SCHEMA_ANNOTATION_KEYS` so the block is not dropped in
      silence on import.
- [x] 1.2 Validate the block at schema save: at least two levels, no
      duplicate, `property` declared on the schema, `clearances` entries
      naming a declared level, `default` a declared level.
- [x] 1.3 Validate `ceilings` entries and the `floor` reference.

## 2. Resolution

- [x] 2.1 A comparator placing a label by its index, with an absent, empty or
      unknown label at the most restrictive index, logged.
- [x] 2.2 A clearance resolver: highest mapped group, then `default`, then the
      lowest level, with the administrator at the top, logged when
      unresolvable.

## 3. Enforcement

- [x] 3.1 `PermissionHandler` refuses a find above the caller's clearance.
      > 2026-11-14: `Service/Rbac/ClassificationReadGate`, called from
      > `PermissionHandler::evaluatePermission()` after the deny pass and before
      > any grant, as a final refusal (no grant, schema rule or elevation
      > re-opens it). Only an ask about one object; a schema without the block
      > is never looked at further. Tests: `ClassificationReadGateTest`,
      > `PermissionHandlerClassificationTest`.
- [x] 3.2 `MagicRbacHandler` compiles the comparison into the list and
      aggregation queries as an `IN` over the admitted labels.
      > 2026-11-14: `Service/Rbac/ClassificationListTerm` answers the admitted
      > labels as a `{property: {$in: [...]}}` block, compiled by the existing
      > match emitter. `applyRbacFilters()` ANDs it ahead of the authorization
      > and deny terms; `buildRbacConditionsSql()` (and so the alias and row
      > predicates the aggregation and UNION paths use) ANDs it into every
      > condition, and turns an open result into that one condition. No label
      > admitted is deny-all. Tests: `ClassificationListTermTest`,
      > `MagicRbacClassificationTest`.
- [x] 3.3 Ceilings refuse a scope ahead of every grant and schema rule.
      > 2026-11-14, find path only: an anonymous caller is the `public` scope,
      > and a ceiling on it refuses every action on an object at or above its
      > level, ahead of a public schema rule and the object's own public grant
      > (`PermissionHandlerClassificationTest::testACeilingBeatsEveryGrantToItsScope`).
      > List path (2026-11-14): an anonymous list loses the labels at or above a
      > `public` ceiling, on every action, ahead of the authorization block.
      > `public` is the only scope a caller is mapped to; a ceiling naming
      > another scope is declared and validated but refuses nothing yet.
- [x] 3.4 The floor is checked at save and a lowering write is refused naming
      both levels.

## 4. The dead vocabulary

- [x] 4.1 `Service/CaseTypeAuthorizationService` becomes the ZGW adapter onto
      this block and gains a caller, or is deleted. It does not stay as it is.
      > 2026-11-14: deleted, with `ZgwAutorisatieGrant` and their tests. No
      > caller in openregister or any fleet app; dossiq declares the ZGW levels
      > as schema configuration (6.1), so an adapter would have no caller
      > either. `docs/rbac-confidentiality.md` now describes the block.

## 5. Tests

- [x] 5.1 Unit tests for each requirement, including both fail directions and
      a ceiling beating an explicit grant. Doubles use `onlyMethods`.
      > Scale, validator, limits, resolver, find gate, floor guard, list term
      > and the handler's list predicate each have a test class.
- [ ] 5.2 `tests/e2e/api-direct/classification-clearance.spec.ts`, probing the
      ceiling with the anonymous principal and the read with a user cleared
      one level short.
- [x] 5.3 A paging test asserting the page size and total are correct when
      rows are excluded, which is the bug the app-side filter cannot fix.
      > `MagicRbacClassificationTest::testAPageIsFullAndTheTotalCountsOnlyAdmittedRows`
      > runs the handler's predicate over forty rows in SQLite: a page of
      > twenty is full and the total is twenty-eight.

## 6. Consuming apps

- [ ] 6.1 dossiq: declare the eight ZGW levels and the clearance map on the
      informatieobject schema, then delete
      `Service/InformatieobjectAccessGuard` and its call sites, including
      `filterDossierForUser()`.
- [ ] 6.2 Migrate `dossier_clearance_group_map` and
      `dossier_default_clearance` into the schema block on upgrade.
