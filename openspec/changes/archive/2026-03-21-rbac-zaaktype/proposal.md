# RBAC per Zaaktype

## Problem
Define zaaktype-scoped authorization as an abstract extension of OpenRegister's existing RBAC system. This spec does NOT introduce a new authorization engine — it defines how the existing PermissionHandler and MagicRbacHandler conditional rules can be configured to enforce zaaktype-level access control, as required by the ZGW Autorisaties API. The core RBAC infrastructure (schema-level permissions, property-level filtering, database-level SQL conditions, admin bypass, conditional matching with operators) is already fully implemented. This spec documents how that infrastructure maps to zaaktype-scoped CRUD permissions, ZGW Autorisaties API compliance (including vertrouwelijkheidaanduiding enforcement), role-to-zaaktype mapping with per-zaaktype role differentiation, cross-zaaktype coordinator access, permission-aware UI rendering, audit logging of zaaktype-level access decisions, and multi-tenant zaaktype isolation — enabling fine-grained data compartmentalization across departments that is required by 86% of analyzed government tenders.
**Tender demand**: 86% of analyzed government tenders require RBAC per zaaktype. Dimpact ZAC implements 51+ individual permissions across 5 policy domains with per-zaaktype role differentiation via PABC. Valtimo uses PBAC with conditional permission records evaluated at query time. OpenRegister achieves equivalent functionality through Nextcloud group-based authorization on schemas with conditional matching, avoiding external policy engines.

## Proposed Solution
Implement RBAC per Zaaktype following the detailed specification. Key requirements include:
- Requirement: Authorization policies MUST be configurable per schema (zaaktype)
- Requirement: Authorization policies MUST support user-level overrides for delegation
- Requirement: Role-to-zaaktype mapping MUST support per-zaaktype role differentiation
- Requirement: The system MUST enforce a zaaktype x operation x role permission matrix
- Requirement: The system MUST support vertrouwelijkheidaanduiding (confidentiality levels) per zaaktype

## Scope
This change covers all requirements defined in the rbac-zaaktype specification.

## Success Criteria
- Define read-only access for a group on a specific zaaktype
- Define full CRUD access for a group on a zaaktype
- Deny access to unauthorized users for a zaaktype
- Separate read and write permissions per zaaktype
- Multiple groups authorized for the same zaaktype action
