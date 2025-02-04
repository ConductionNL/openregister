# Access Control

Access Control provides enterprise-grade permissions management through integration with Nextcloud RBAC (Role-Based Access Control) and Keycloak.

## Overview

The access control system integrates with:
- ADFS (Active Directory Federation Services) for user and group management via Keycloak
- Nextcloud RBAC for role-based permissions
- FCS (Federal Cloud Services) compliance requirements
- Verwerkingen registers for process tracking

## Permission Levels

Access can be controlled at multiple levels:
- Register level - Control access to entire registers
- Schema level - Manage permissions for specific register/schema combinations  
- Object level - Set permissions on individual objects
- Property level - Fine-grained control over specific object properties

## Permission Types

Permissions are granted through:
1. **User Rights**
   - CRUD (Create, Read, Update, Delete) operations
   - Inherited from ADFS groups via Keycloak
   - Role-based access control through Nextcloud

2. **Contract Rights** 
   - Application-level permissions
   - Process-specific authorizations
   - Compliance with FCS requirements
   - Integration with verwerkingen registers

## Implementation

Access control is implemented through:

1. **User Authentication**
   - Direct integration with Keycloak for identity management
   - ADFS synchronization for user and group information
   - Single Sign-On (SSO) capabilities

2. **Permission Management**
   - CRUD-level permissions for all system entities
   - Hierarchical permission inheritance
   - Fine-grained access control at multiple levels

3. **Process Integration**
   - Compliance with FCS guidelines
   - Integration with verwerkingen registers for process tracking
   - Application-specific permission contracts

## Related Features

- [Register Management](register-management.md) - Manage register access
- [Object Locking](object-locking.md) - Control modifications