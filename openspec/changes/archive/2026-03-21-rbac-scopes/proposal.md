# RBAC Scopes

## Problem
Validate and extend OpenRegister's existing three-level RBAC system. The core RBAC is already implemented via PermissionHandler (schema-level), MagicRbacHandler (row-level SQL filtering), and PropertyRbacHandler (field-level). This spec documents the existing behavior as requirements and identifies extensions needed for scope management APIs, caching, and audit. Specifically, it maps the existing hierarchical RBAC model (register, schema, object, property) to standard OAuth2 scopes in the generated OpenAPI Specification, and validates that per-operation security requirements are correctly enforced so that API consumers can discover and request the precise group-based permissions they need. The scope system bridges Nextcloud's native group management with standardised OAuth2/OAS security semantics, enabling external API consumers, ZGW-compliant systems, and MCP clients to understand and negotiate access programmatically.
**Source**: Core OpenRegister capability; 67% of tenders require SSO/identity integration; 86% require RBAC per zaaktype; ZGW Autorisaties API compliance.

## Proposed Solution
Implement RBAC Scopes following the detailed specification. Key requirements include:
- Requirement: Scope Model Hierarchy (Register > Schema > Object > Property)
- Requirement: Permission Types (read, create, update, delete, list)
- Requirement: Role Definitions and Hierarchy
- Requirement: Scope Inheritance (Register Permissions Cascade to Schemas)
- Requirement: Conditional Scopes with Dynamic Variables

## Scope
This change covers all requirements defined in the rbac-scopes specification.

## Success Criteria
- Schema-level authorization defines CRUD scopes
- Property-level authorization contributes additional scopes
- Object-level conditional scopes produce group entries without match details
- Schema with no authorization produces no extra scopes
- Scope hierarchy is flattened for OAS (no nesting)
