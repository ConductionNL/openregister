# Proposal: referential-integrity-on-delete

## Summary
Add referential integrity enforcement to OpenRegister's schema relation system. When an object is deleted, the system checks all schemas that reference it and applies the configured `onDelete` action: CASCADE (delete dependent objects), RESTRICT (block deletion), SET_NULL (clear reference), SET_DEFAULT (set to default value), or NO_ACTION (do nothing). A pre-flight `canDelete()` method walks the full relation graph — including chained cascades that may hit a RESTRICT deeper in the tree — before any mutation occurs, returning a clear API response if deletion is blocked.

## Motivation
OpenRegister currently has no referential integrity on deletion. When an object is deleted, related objects that depend on it are silently orphaned — their references point to a UUID that no longer resolves. This creates data corruption that is invisible until someone tries to load the orphaned object's relation.

Real-world examples:
- **Orphan problem**: A `ContactDetail` object describes further details on a `Person`. When `Person` is deleted, `ContactDetail` becomes meaningless — it should cascade-delete.
- **Blocking problem**: A `Service` object implements a `ServiceType`. Deleting the `ServiceType` would leave `Service` objects pointing at nothing, breaking business logic — deletion should be blocked.
- **Cleanup problem**: An `Order` references an optional `CouponCode`. When the coupon is deleted, the order is still valid — just clear the reference (SET_NULL).

Without this, apps must implement their own deletion guards in business logic, leading to inconsistent behavior across OpenCatalogi, Procest, Pipelinq, and other consumers.

## Affected Projects
- [x] Project: `openregister` — Schema entity (onDelete config on relation properties), DeleteObject service (graph walking, cascading, blocking), ObjectService (canDelete pre-flight), API controller (proper error responses)

## Scope

### In Scope
- `onDelete` configuration on schema relation properties (`$ref` properties)
- Five deletion actions: CASCADE, RESTRICT, SET_NULL, SET_DEFAULT, NO_ACTION
- Pre-flight `canDelete()` method that walks the entire relation graph before mutating
- Chain-aware graph traversal: CASCADE through B triggers analysis of B's dependents, which may RESTRICT
- Efficient schema analysis: only check schemas that have `onDelete` config on their relation properties
- Proper API responses: HTTP 409 Conflict with details when deletion is blocked
- Soft-delete aware: works with the existing soft-delete mechanism
- Batch deletion support: `canDelete()` and cascading work for bulk deletes

### Out of Scope
- Relation creation/modification integrity (separate concern)
- Schema deletion cascading (deleting a schema itself)
- Register deletion cascading (deleting a register itself)
- UI for configuring onDelete (admin UI is separate)
- Cross-register referential integrity (only within same register for now)

## Approach
1. Extend schema property definitions with an `onDelete` field that accepts: `CASCADE`, `RESTRICT`, `SET_NULL`, `SET_DEFAULT`, `NO_ACTION` (default)
2. Build a `ReferentialIntegrityService` that:
   - Scans all schemas in the same register for properties referencing the target object's schema
   - Builds a dependency graph from the `onDelete` configurations
   - Walks the graph recursively to detect cascading chains and RESTRICT blockers
3. Add a `canDelete(ObjectEntity $object): DeletionAnalysis` method that returns the full analysis (what would be cascaded, what blocks, what would be nullified) without performing any mutation
4. Integrate into `DeleteObject` to enforce the graph analysis before soft-deleting
5. Cache schema relation analysis per-request to avoid redundant schema parsing during batch operations

## Cross-Project Dependencies
- May interact with **Schema Hooks** change (onDelete hooks could fire before referential integrity checks)

## Rollback Strategy
1. Remove `onDelete` from schema property definitions (backward compatible — field is simply ignored if not present)
2. Revert DeleteObject to current behavior (no graph walking)
3. Remove ReferentialIntegrityService
4. No data loss — `onDelete` is configuration on schemas, not object data
