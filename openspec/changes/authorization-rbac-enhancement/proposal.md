# Proposal: authorization-rbac-enhancement

## Summary

Implement fine-grained role-based access control (RBAC) for OpenRegister, enabling per-schema, per-register, and per-object authorization with row-level security and team-based access control. This extends Nextcloud's built-in group system with OpenRegister-specific permission scopes that match the granularity required by Dutch government organisations.

## Demand Evidence

**Cluster: Authorization/RBAC** -- 205 tenders, 876 requirements
**Cluster: Role-based access** -- 200 tenders, 629 requirements
**Combined**: 405 tenders, 1505 requirements (highest demand across all clusters)

### Sample Requirements from Tenders

1. **Gemeente Berkelland**: "Autorisaties kunnen door een beheerinterface eenvoudig worden geconfigureerd. Het hele rollen- en rechtenmodel van de oplossing kan op een plek geconfigureerd worden."
2. **Gemeente Hilversum**: "A. Beschrijf waar en op welke wijze autorisatie binnen de Oplossing plaatsvindt. B. Beschrijf op welke wijze de inrichting de mogelijkheid biedt om op verschillende niveaus te autoriseren. C. Beschrijf hoe rollen en rechten worden beheerd."
3. **Gemeente Winterswijk**: "Beheer: Centraal beheer autorisaties, inrichting Active Directory. Beschrijf waar en op welke wijze autorisatie plaatsvindt, mogelijkheid om op verschillende niveaus te autoriseren, rol van Active Directory."
4. **Gemeente Berkelland**: "Gebruikers met de juiste autorisaties kunnen altijd handmatig gegevens zoals bewaartermijn en vernietigingsdatum aanpassen."
5. **Gemeente Berkelland**: "Op basis van zaaktypen en door verschillende beheerders (delegatie) is het mogelijk om configuraties, rollen en rechten te stapelen, overerven, kopieren."

## Scope

### In Scope

- **Permission model**: Define CRUD+L (Create, Read, Update, Delete, List) permissions at three levels: register, schema, and individual object
- **Role definitions**: Configurable roles with named permission sets (e.g., "Viewer", "Editor", "Manager", "Archivist") that can be assigned per scope
- **Row-level security**: Filter query results based on user permissions -- users only see objects they are authorised to access
- **Team-based access**: Assign permissions to Nextcloud groups (teams), with support for hierarchical group inheritance
- **Delegation**: Allow schema/register managers to delegate permission management to sub-administrators
- **Permission inheritance**: Register-level permissions cascade to schemas unless overridden; schema-level permissions cascade to objects unless overridden
- **Field-level visibility**: Optional configuration to hide sensitive fields from users without specific roles (e.g., BSN, financial data)
- **Authorization admin UI**: Central interface for managing all roles, permissions, and assignments across registers and schemas
- **LDAP/AD group mapping**: Map external directory groups to OpenRegister roles automatically
- **Public access scopes**: Define which schemas/registers are publicly accessible (unauthenticated) vs. restricted

### Out of Scope

- Authentication (handled by Nextcloud's auth system)
- Single sign-on configuration (Nextcloud-level concern)
- CSV import/export (already exists)
- Multi-tenant isolation (separate change: `saas-multi-tenant`)

## Acceptance Criteria

1. Permissions can be assigned at register, schema, and object level with CRUD+L granularity
2. Roles are configurable with named permission sets and can be reused across scopes
3. API queries automatically filter results based on the requesting user's permissions (row-level security)
4. Nextcloud groups can be assigned roles, and group membership changes are reflected immediately
5. Permission inheritance works top-down (register -> schema -> object) with explicit override capability
6. A central admin UI shows all permission assignments and allows bulk management
7. Field-level visibility can be configured per schema to hide sensitive properties from unauthorised users
8. Delegation allows register managers to grant/revoke permissions within their scope without requiring system admin access
9. Public access can be toggled per schema/register without affecting authenticated user permissions
10. Performance: row-level security filtering adds less than 50ms overhead to typical list queries

## Dependencies

- OpenRegister Schema, Register, and Object entities
- Nextcloud IGroupManager and IUserManager for group/user resolution
- Nextcloud IAppConfig for permission storage (or dedicated permission table)
- Existing archived changes: `auth-system`, `rbac-scopes`, `rbac-zaaktype` -- this proposal consolidates and extends those concepts

## Standards & Regulations

- BIO (Baseline Informatiebeveiliging Overheid) -- section 9 (access control)
- AVG/GDPR Article 25 (data protection by design -- principle of least privilege)
- NEN-ISO 27001:2013 / 27002:2013 (access control domain)
- NORA (Nederlandse Overheid Referentie Architectuur) -- authorization principles

## Notes

- OpenRegister already has CSV import/export with ID support
- The archived changes `auth-system`, `rbac-scopes`, and `rbac-zaaktype` covered aspects of this; this proposal consolidates them into a single comprehensive RBAC enhancement
- This is the highest-demand capability across all analysed clusters (1505 combined requirements) and should be prioritised accordingly
