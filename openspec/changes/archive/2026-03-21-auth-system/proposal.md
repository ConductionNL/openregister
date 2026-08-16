# Authentication and Authorization System

## Problem
Define the authentication and authorization system for OpenRegister, supporting Nextcloud session auth, Basic Auth for API consumers, JWT bearer tokens for external systems, API key auth for MCP and service-to-service integration, and SSO integration via SAML/OIDC. The auth system MUST map all external identities to Nextcloud users via the Consumer entity and enforce consistent RBAC across every access method (REST, GraphQL, MCP, public endpoints), ensuring that a single identity model drives schema-level, property-level, and row-level security decisions.
**Source**: Core OpenRegister capability; 67% of tenders require SSO/identity integration; 86% require RBAC per zaaktype.

## Proposed Solution
Implement Authentication and Authorization System following the detailed specification. Key requirements include:
- Requirement: The system MUST support multiple authentication methods with unified identity resolution
- Requirement: API consumers MUST be configurable entities that bridge external systems to Nextcloud identities
- Requirement: The RBAC model MUST enforce schema-level, property-level, and row-level access control using Nextcloud groups
- Requirement: The role hierarchy MUST include admin bypass, owner privileges, public access, and authenticated access
- Requirement: Group-based access MUST support conditional matching with dynamic variables

## Scope
This change covers all requirements defined in the auth-system specification.

## Success Criteria
- Nextcloud session authentication for browser users
- Basic Auth for API consumers
- JWT Bearer token for external systems
- API key authentication for MCP and service-to-service calls
- Reject invalid credentials with appropriate HTTP status
