## 1. Query boundary

- [x] 1.1 `MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()` lifts the filter only for an admin or a held marked rule without match.
- [x] 1.2 `MagicRbacHandler::hasCrossOrganisationBranch()`, `getCrossOrganisationBranch()` and `getCrossOrganisationBranchSql()` widen the filter by the held marked rules' matches (replaces the public-only branch).
- [x] 1.3 `publicReadLiftsOrganisationFilter()` lifts only for anonymous callers and admins; `MagicSearchHandler::resolveMultitenancyFlag()` and `ObjectService::searchMultitenancy()` follow it.
- [x] 1.4 Every read path takes the decision from the same methods: list (QueryBuilder), union search, facets, count, single read, aggregation (falls back to the list when a branch exists).

## 2. Authoring

- [x] 2.1 `Schema::validateAuthorizationRule()` refuses a non-boolean `crossOrganisation`.
- [x] 2.2 `DepartmentMatrixCompiler` carries a row's marker into the compiled rule; the decision reads the resolved block.
- [x] 2.3 Schema and register lookups read `{"group": "public"}` with no match as public (`ReadRuleTenancy::readsPublicUnconditionally()`).

## 3. Migration

- [x] 3.1 Repair step `MarkImplicitCrossOrganisationReadRules`, post-migration.

## 4. Tests

- [x] 4.1 Tenancy probe across list, union, facets, single read and aggregation for each rule kind, marked and unmarked.
- [x] 4.2 Repair step, validator and compiler unit tests.

## 5. Fleet

- [ ] 5.1 One marker PR per app with an intended crossing, stacked on this change.
