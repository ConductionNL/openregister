# Tasks: RBAC per Zaaktype

> Implementation note: the 3-level RBAC engine already ships. This change is the
> zaaktype-scoped *configuration + ZGW-mapping + enforcement-wiring* layer on top
> of the existing primitives (PermissionHandler, MagicRbacHandler, ConditionMatcher).
> It adds: (1) a user-level override (delegation) primitive wired into both the
> single-object verdict and the list/SQL verdict; (2) a `ZaaktypeAuthorizationService`
> for vertrouwelijkheidaanduiding ordinal logic, ZGW Autorisaties → authorization-block
> mapping, and permission-matrix extraction.

- [x] Implement: Authorization policies MUST be configurable per schema (zaaktype) — already shipped (schema `authorization` blocks); verified by allow/deny tests.
- [x] Implement: Authorization policies MUST support user-level overrides for delegation — NEW `user:<uid>` (bare + `{user, match}`) entry support in PermissionHandler + MagicRbacHandler, fail-closed, additive.
- [x] Implement: Role-to-zaaktype mapping MUST support per-zaaktype role differentiation — already shipped via group naming + `expandRoles()` role hierarchy.
- [x] Implement: The system MUST enforce a zaaktype x operation x role permission matrix — `ZaaktypeAuthorizationService::extractPermissionMatrix()` derives the canonical (action x principal) matrix from the enforced authorization block.
- [x] Implement: The system MUST support vertrouwelijkheidaanduiding (confidentiality levels) per zaaktype — `ZaaktypeAuthorizationService` ordinal ordering + `buildConfidentialityMatch()` emits the existing `$in` clause the engine enforces (PHP + SQL).
  > **STATUS CLARIFIED 2026-08-12 — SUPPORTED, BUT NOT CONFIGURED ANYWHERE.**
  > This box is accurate as written: the capability exists end to end.
  > `OperatorEvaluator` implements `$in`, `PermissionHandler` applies conditional
  > `match` clauses, and `buildConfidentialityMatch()` emits exactly that shape.
  >
  > What the box does NOT say, and what a reader reasonably assumes from it, is
  > that confidentiality is being ENFORCED. It is not. Measured on this branch:
  > **zero register files contain any `match` clause at all** — confidentiality
  > or otherwise — and `ZaaktypeAuthorizationService` has **zero callers** in
  > `lib/`, exercised only by its own unit test.
  >
  > So no object is currently filtered by clearance on the normal read path. The
  > gap is CONFIGURATION, not a missing control: enabling it means adding a
  > clause to a schema's `authorization` block, which is a POLICY decision about
  > which group may read up to which level. See docs/rbac-confidentiality.md.
  >
  > Recorded because the unqualified `[x]` reads as a shipped, active control.
- [x] Implement: Cross-zaaktype access MUST be supported for coordinator and management roles — already shipped (a group listed across multiple schemas grants cross-zaaktype access); configuration concern, no new code.
- [x] Implement: Permission checks MUST apply to all API endpoints consistently — already shipped (`checkPermission()` enforced in ObjectService for REST/GraphQL/MCP); user-overrides flow through the same chokepoint.
- [~] Implement: The frontend MUST render permission-aware UI components — DEFERRED (no JS touched this PR). Backend already filters schemas/properties; existing UI consumes it. A dedicated matrix-editor view is a separate frontend change; `extractPermissionMatrix()` supplies its data contract.
- [~] Implement: All zaaktype access decisions MUST be logged in the audit trail — DEFERRED. AuditTrail entity already carries `confidentiality`; discrete `rbac.permission_granted/revoked/access_denied` events require an audit-event emitter that belongs with the audit-trail-immutable spec. Not in scope for this enforcement-config PR.
- [~] Implement: Bulk permission assignment MUST be supported for efficient onboarding — DEFERRED (admin-UI/template feature; no enforcement-layer dependency).
- [x] Implement: Delegation and escalation patterns MUST be supported within zaaktype authorization — owner-based delegation already shipped; user-level override (this PR) covers individual delegation + escalation grants; expiry expressible via `{user, match: {_expires: {$gt: $now}}}`.
- [x] Implement: ZGW Autorisaties API concepts MUST be mapped to OpenRegister primitives — `ZaaktypeAuthorizationService::mapZgwAutorisatie()` / `scopeToAction()` map scope + maxVertrouwelijkheidaanduiding onto authorization blocks; heeftAlleAutorisaties = admin group (already shipped).
- [x] Implement: Zaakcatalogus inheritance MUST be supported for zaaktype authorization defaults — already shipped via register→schema authorization cascade (`resolveAuthorization()`).
- [x] Implement: Multi-tenant zaaktype isolation MUST restrict cross-tenant visibility — already shipped (`MultiTenancyTrait` + `hasConditionalRulesBypassingMultitenancy()`); user-overrides participate in the same `$organisation` match grammar.
- [x] Implement: Admin users MUST bypass all zaaktype authorization policies — already shipped (`in_array('admin', $userGroups)` early return on every path); unchanged.
- [x] Implement: VNG compliance testing MUST validate zaaktype authorization behavior — exhaustive allow/deny matrix + fail-closed + no-widening + ZGW-mapping unit suites added (PermissionHandlerZaaktypeTest, MagicRbacHandlerZaaktypeTest, ZaaktypeAuthorizationServiceTest).
