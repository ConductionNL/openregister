# Tasks: classification clearance on an object

## 1. Declaration

- [ ] 1.1 Add `x-openregister-classification` to
      `SchemaSlugMap::SCHEMA_ANNOTATION_KEYS` so the block is not dropped in
      silence on import.
- [ ] 1.2 Validate the block at schema save: at least two levels, no
      duplicate, `property` declared on the schema, `clearances` entries
      naming a declared level, `default` a declared level.
- [ ] 1.3 Validate `ceilings` entries and the `floor` reference.

## 2. Resolution

- [ ] 2.1 A comparator placing a label by its index, with an absent, empty or
      unknown label at the most restrictive index, logged.
- [ ] 2.2 A clearance resolver: highest mapped group, then `default`, then the
      lowest level, with the administrator at the top, logged when
      unresolvable.

## 3. Enforcement

- [ ] 3.1 `PermissionHandler` refuses a find above the caller's clearance.
- [ ] 3.2 `MagicRbacHandler` compiles the comparison into the list and
      aggregation queries as an `IN` over the admitted labels.
- [ ] 3.3 Ceilings refuse a scope ahead of every grant and schema rule.
- [ ] 3.4 The floor is checked at save and a lowering write is refused naming
      both levels.

## 4. The dead vocabulary

- [ ] 4.1 `Service/CaseTypeAuthorizationService` becomes the ZGW adapter onto
      this block and gains a caller, or is deleted. It does not stay as it is.

## 5. Tests

- [ ] 5.1 Unit tests for each requirement, including both fail directions and
      a ceiling beating an explicit grant. Doubles use `onlyMethods`.
- [ ] 5.2 `tests/e2e/api-direct/classification-clearance.spec.ts`, probing the
      ceiling with the anonymous principal and the read with a user cleared
      one level short.
- [ ] 5.3 A paging test asserting the page size and total are correct when
      rows are excluded, which is the bug the app-side filter cannot fix.

## 6. Consuming apps

- [ ] 6.1 dossiq: declare the eight ZGW levels and the clearance map on the
      informatieobject schema, then delete
      `Service/InformatieobjectAccessGuard` and its call sites, including
      `filterDossierForUser()`.
- [ ] 6.2 Migrate `dossier_clearance_group_map` and
      `dossier_default_clearance` into the schema block on upgrade.
