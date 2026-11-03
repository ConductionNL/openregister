## 1. Context

- [x] 1.1 `OrganisationContext` static scope: stack of organisation uuids, restored in `finally`, innermost wins, empty uuid refused.
- [x] 1.2 `ObjectService::runForOrganisation()`: refuses an unknown organisation, runs the callable as anonymous inside the context.

## 2. Tenancy follows the context

- [x] 2.1 `OrganisationService::getActiveOrganisation()` answers with the context's organisation; `getUserActiveOrganisations()` and `getOrganisationForNewEntity()` follow.
- [x] 2.2 `MagicOrganizationHandler::resolveOrganizationScope()` returns the context's scope before the system and admin branches.
- [x] 2.3 `ConditionMatcher` keys its organisation memo by the context, so `$organisation` resolves to the context's organisation.
- [x] 2.4 `MagicRbacHandler`: inside a context a `public` rule does not lift the organisation filter and no rule crosses organisations.
- [x] 2.5 `MagicSearchHandler`: inside a context the organisation filter applies whatever `_multitenancy`, the schema or RBAC say.
- [x] 2.6 The bypass shortcuts in `MagicMapper` (single find, cross-table find, deleted scans) and the bulk-delete filter read `_multitenancy` through `OrganisationContext::multitenancy()`.

## 3. Writes and audit

- [x] 3.1 New objects are stamped with the context's organisation; the owner is the explicit `_owner` or the system identity.
- [x] 3.2 `OrganisationContextAttribution` stamps `resultSummary.organisationContext` on audit rows before insert.

## 4. Tests

- [x] 4.1 Scope tests: nesting, restore after a throw, empty uuid.
- [x] 4.2 Real-SQL tenancy probe: a context for org A reads no org B rows on list, union, single read and the aggregation decision, for public, conditional public, marked and plain rules, with `_multitenancy` off.
- [x] 4.3 Organisation, matcher, stamping and audit attribution unit tests.

## 5. Adoption (other repos)

- [ ] 5.1 portaliq: every claim-scoped read runs inside the context with multitenancy on.
- [ ] 5.2 learniq: employer writes and the occ invite run inside the context; redundant app-side checks removed.
