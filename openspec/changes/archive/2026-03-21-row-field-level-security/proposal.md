# Row and Field Level Security

## Problem
Implement dynamic per-record access rules based on field values (row-level security / RLS) and per-field visibility and editability rules based on user roles (field-level security / FLS). Beyond schema-level RBAC that controls access to entire object types, the system MUST support row-level security where access to individual objects depends on the object's own properties (e.g., department, classification level, owner), and field-level security where different users see different fields of the same object. Both security layers MUST be enforced consistently across REST, GraphQL, search, export, and MCP access methods, evaluated at the database query level where possible for performance, and composable with schema-level RBAC and multi-tenancy isolation.
**Source**: Gap identified in cross-platform analysis; Directus implements comprehensive row/field-level security with filter-based permissions and dynamic variables ($CURRENT_USER, $CURRENT_ROLE, $NOW). NocoDB provides view-level permissions. 86% of analyzed government tenders require RBAC per zaaktype; 67% require SSO/identity integration with fine-grained data compartmentalization.

## Proposed Solution
Implement Row and Field Level Security following the detailed specification. Key requirements include:
- Requirement: Schemas MUST support row-level security rules via conditional authorization matching
- Requirement: RLS rules MUST support dynamic variable resolution in match conditions
- Requirement: Schemas MUST support field-level security via property authorization blocks
- Requirement: RLS rules MUST apply consistently to all access methods
- Requirement: FLS MUST apply consistently to GraphQL field resolution

## Scope
This change covers all requirements defined in the row-field-level-security specification.

## Success Criteria
- Restrict access by department field using group + match
- Restrict access by classification level using operator conditions
- Owner-based access via $userId dynamic variable
- Object owner always has access regardless of RLS rules
- Multiple authorization rules evaluated with OR logic
