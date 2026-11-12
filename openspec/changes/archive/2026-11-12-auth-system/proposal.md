# Authentication and Authorization System

## Problem
Define the authentication and authorization system for OpenRegister, supporting Nextcloud session auth, Basic Auth for API consumers, JWT bearer tokens for external systems, API key auth for MCP and service-to-service integration, and SSO integration via SAML/OIDC. The auth system MUST map all external identities to Nextcloud users via the Consumer entity and enforce consistent RBAC across every access method (REST, GraphQL, MCP, public endpoints), ensuring that a single identity model drives schema-level, property-level, and row-level security decisions.

## Proposed Solution
Define the authentication and authorization system for OpenRegister, supporting Nextcloud session auth, Basic Auth for API consumers, JWT bearer tokens for external systems, API key auth for MCP and service-to-service integration, and SSO integration via SAML/OIDC. The auth system MUST map all external identities to Nextcloud users via the Consumer entity and enforce consistent RBAC across every access method (REST, GraphQL, MCP, public endpoints), ensuring that a single identity model drives sc
