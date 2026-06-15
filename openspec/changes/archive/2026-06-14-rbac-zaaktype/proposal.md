# RBAC per Zaaktype

## Problem
Define zaaktype-scoped authorization as an abstract extension of OpenRegister's existing RBAC system. This spec does NOT introduce a new authorization engine — it defines how the existing PermissionHandler and MagicRbacHandler conditional rules can be configured to enforce zaaktype-level access control, as required by the ZGW Autorisaties API.

## Proposed Solution
Define zaaktype-scoped authorization as an abstract extension of OpenRegister's existing RBAC system. This spec does NOT introduce a new authorization engine — it defines how the existing PermissionHandler and MagicRbacHandler conditional rules can be configured to enforce zaaktype-level access control, as required by the ZGW Autorisaties API. The core RBAC infrastructure (schema-level permissions, property-level filtering, database-level SQL conditions, admin bypass, conditional matching with o
