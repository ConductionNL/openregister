# Tasks: an-object-lists-its-grants-with-their-origin

## 1. Who may ask
- [x] 1.1 `readGrants` in `PermissionCatalogue::CANONICAL`, with no default-open fallback
- [x] 1.2 `Service/Rbac/AccessReviewGate`: the object resolution and access-review checks moved out of `ObjectPermissionsController`, plus `GrantManageGuard` and `readGrants` (D-6)
- [x] 1.3 `ObjectPermissionsController` uses the gate, behaviour unchanged for its own callers

## 2. The rows
- [x] 2.1 A guard-free share reader extracted from `ObjectSharingService::listGrants()`; `listGrants()` keeps `requireMayManageGrants()` in front of it
- [x] 2.2 `Service/Rbac/ObjectGrantsReport`: owner, shared, inherited and rule rows (D-2)
- [x] 2.3 Conditional rules evaluated with `ConditionMatcher::objectMatchesConditions()`, caller-dependent ones reported as `relative` (D-3)
- [x] 2.4 Enforcing denies taken out of a row's verbs, staged ones reported (D-4)
- [x] 2.5 `sharedBy` and display names for users, groups, remote and email principals (D-5)
- [x] 2.6 `principalTypes` filter; link shares never listed

## 3. The endpoint
- [x] 3.1 `Controller/ObjectGrantsController::index()` and its route beside `objectPermissions#index`
- [x] 3.2 A failed source answers 500 with a logged cause, never `[]` (D-8)

## 4. Tests
- [x] 4.1 Unit: each origin, a fitting and a non-fitting conditional rule, a relative rule, the deny modes, a deleted sharer
- [x] 4.2 Unit: the gate admits owner, owning group, admin, written `manage`, `grant_manage` and `readGrants`, and refuses an ordinary reader before any read
- [x] 4.3 Unit: a schema with no block refuses (no default-open)
- [ ] 4.4 Newman: 404 for a caller who cannot read the record, 403 for a reader, 200 for the owner
- [ ] 4.5 Regression: `/shares` and `/permissions` answer as before; opencatalogi and softwarecatalog unaffected

## 5. Consumer
- [ ] 5.1 dossiq `case-access-card-lists-team-and-group-grants` wires the Toegang card to this read
