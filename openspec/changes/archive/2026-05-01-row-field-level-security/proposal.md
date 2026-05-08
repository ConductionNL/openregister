# Row and Field Level Security

## Problem
Implement dynamic per-record access rules based on field values (row-level security / RLS) and per-field visibility and editability rules based on user roles (field-level security / FLS). Beyond schema-level RBAC that controls access to entire object types, the system MUST support row-level security where access to individual objects depends on the object's own properties (e.g., department, classification level, owner), and field-level security where different users see different fields of the same object.

## Proposed Solution
Implement dynamic per-record access rules based on field values (row-level security / RLS) and per-field visibility and editability rules based on user roles (field-level security / FLS). Beyond schema-level RBAC that controls access to entire object types, the system MUST support row-level security where access to individual objects depends on the object's own properties (e.g., department, classification level, owner), and field-level security where different users see different fields of the s
