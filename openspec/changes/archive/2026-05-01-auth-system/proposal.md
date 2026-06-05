# Authentication and Authorization System

## Why

OpenRegister is consumed by browsers (Nextcloud session), API clients (Basic, JWT, API key), MCP clients, and SSO-fronted external systems. Without a single, documented identity model, RBAC decisions become inconsistent across access methods (REST, GraphQL, MCP, public endpoints) and external identities cannot be reliably mapped to Nextcloud groups. 67% of analysed tenders require SSO/identity integration and 86% require RBAC per zaaktype, so the auth/authz baseline must be defined as a first-class capability rather than as ad-hoc per-controller logic.

## What Changes

- Document multiple authentication methods that all resolve to a Nextcloud `IUser` before RBAC evaluation: session cookie, HTTP Basic, JWT bearer, OAuth2 bearer, and API key
- Define the `Consumer` entity as the single bridge from external identities (JWT issuer/subject, OAuth client, API key) to a Nextcloud user with optional IP/CORS restrictions
- Define a unified RBAC model layered over Nextcloud groups: schema-level, property-level, and row-level checks all driven by the resolved identity
- Define the role hierarchy: admin bypass, owner privileges (creator), public access (unauthenticated), and authenticated default access
- Define group-based access with conditional matching and dynamic variables (`$organisation`, `$userId`, `$now`)
- Require multi-tenancy isolation: every authenticated request scopes data to the user's active organisation via `MultiTenancyTrait`
- Require public endpoints to use Nextcloud's `#[PublicPage]` annotation framework with mixed-visibility enforcement
- Require SSO via SAML, OIDC, and LDAP through Nextcloud's identity providers (no app-local SSO stack)
- Require rate limiting, authentication audit events, permission caching, per-Consumer CORS/CSRF policy, MCP auth, service-to-service outbound tokens, and input sanitisation against XSS/injection
- Codify all of the above in `specs/auth-system/spec.md` so dependent capabilities (`rbac-scopes`, `row-field-level-security`, `tenant-isolation-audit`) can reference one canonical source

## Problem
Define the authentication and authorization system for OpenRegister, supporting Nextcloud session auth, Basic Auth for API consumers, JWT bearer tokens for external systems, API key auth for MCP and service-to-service integration, and SSO integration via SAML/OIDC. The auth system MUST map all external identities to Nextcloud users via the Consumer entity and enforce consistent RBAC across every access method (REST, GraphQL, MCP, public endpoints), ensuring that a single identity model drives schema-level, property-level, and row-level security decisions.

## Proposed Solution
Define the authentication and authorization system for OpenRegister, supporting Nextcloud session auth, Basic Auth for API consumers, JWT bearer tokens for external systems, API key auth for MCP and service-to-service integration, and SSO integration via SAML/OIDC. The auth system MUST map all external identities to Nextcloud users via the Consumer entity and enforce consistent RBAC across every access method (REST, GraphQL, MCP, public endpoints), ensuring that a single identity model drives sc
