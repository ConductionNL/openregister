# auth-system Specification

## Purpose
Define the authentication and authorization system for OpenRegister, supporting Nextcloud session auth, Basic Auth for API consumers, JWT bearer tokens for external systems, and SSO integration via SAML/OIDC. The auth system MUST map external identities to Nextcloud users and enforce consistent access control across all access methods.

**Source**: Core OpenRegister capability; 67% of tenders require SSO/identity integration.

## ADDED Requirements

### Requirement: The system MUST support multiple authentication methods
OpenRegister MUST accept authentication via Nextcloud session, HTTP Basic Auth, and Bearer JWT tokens.

#### Scenario: Nextcloud session authentication
- GIVEN a user is logged into Nextcloud via browser
- WHEN they access OpenRegister pages or API endpoints
- THEN the request MUST be authenticated using the Nextcloud session cookie
- AND the user's Nextcloud identity MUST be used for RBAC checks

#### Scenario: Basic Auth for API consumers
- GIVEN an external system sends a request with `Authorization: Basic base64(user:pass)`
- WHEN the credentials match a valid Nextcloud user
- THEN the request MUST be authenticated as that user
- AND RBAC rules MUST apply based on the user's groups and permissions

#### Scenario: Bearer JWT token for API consumers
- GIVEN an API consumer configured in OpenRegister with a JWT token
- WHEN the consumer sends a request with `Authorization: Bearer {token}`
- THEN the token MUST be validated (signature, expiry, audience)
- AND the consumer MUST be mapped to a Nextcloud user for RBAC purposes

#### Scenario: Reject invalid credentials
- GIVEN a request with invalid Basic Auth credentials
- THEN the system MUST return HTTP 401 Unauthorized
- AND rate limiting MUST apply to prevent brute force attacks

### Requirement: API consumers MUST be configurable entities
Administrators MUST be able to create API consumer definitions that represent external systems.

#### Scenario: Create an API consumer
- GIVEN the admin navigates to OpenRegister API consumer settings
- WHEN they create a consumer:
  - Name: `Zaaksysteem Extern`
  - Description: `Integration with the external case management system`
  - Mapped user: `api-zaaksysteem` (Nextcloud user)
  - Auth type: `JWT`
  - JWT secret: auto-generated
- THEN the consumer MUST be created with a unique client ID
- AND the JWT secret MUST be displayed once for the admin to copy
- AND subsequent API requests with a valid JWT MUST authenticate as `api-zaaksysteem`

#### Scenario: Revoke an API consumer
- GIVEN an active API consumer `Zaaksysteem Extern`
- WHEN the admin revokes the consumer
- THEN all existing tokens MUST become invalid immediately
- AND subsequent requests MUST receive HTTP 401

### Requirement: The system MUST support SSO via SAML and OIDC
OpenRegister MUST integrate with Nextcloud's SSO capabilities for enterprise identity providers.

#### Scenario: SAML authentication flow
- GIVEN Nextcloud is configured with a SAML identity provider (e.g., Azure AD)
- WHEN a user authenticates via SAML
- THEN the user MUST be mapped to a Nextcloud user
- AND OpenRegister MUST use the mapped user for authentication and RBAC
- AND group memberships from SAML assertions MUST be synced to Nextcloud groups

#### Scenario: OIDC authentication flow
- GIVEN Nextcloud is configured with an OpenID Connect provider
- WHEN a user authenticates via OIDC
- THEN the OIDC claims MUST be mapped to Nextcloud user attributes
- AND OpenRegister MUST use the mapped user identity

### Requirement: Rate limiting MUST protect against abuse
All authentication endpoints MUST implement rate limiting to prevent brute force and denial of service.

#### Scenario: Rate limit Basic Auth failures
- GIVEN 10 failed Basic Auth attempts from the same IP in 60 seconds
- THEN subsequent requests from that IP MUST receive HTTP 429 Too Many Requests
- AND the cooldown period MUST be configurable (default: 5 minutes)

#### Scenario: Rate limit per API consumer
- GIVEN API consumer `Zaaksysteem Extern` is configured with rate limit 1000 requests/hour
- WHEN the consumer exceeds 1000 requests in an hour
- THEN subsequent requests MUST receive HTTP 429
- AND the response MUST include `Retry-After` header

### Requirement: Authentication events MUST be audited
All authentication attempts (success and failure) MUST be logged for security monitoring.

#### Scenario: Log successful authentication
- GIVEN user `admin` authenticates via Basic Auth
- THEN an audit log entry MUST record: timestamp, user, auth method, IP address, success

#### Scenario: Log failed authentication
- GIVEN an invalid JWT token is presented
- THEN an audit log entry MUST record: timestamp, consumer ID, auth method, IP address, failure reason

### Requirement: The system MUST support public (unauthenticated) API access
Specific schemas MUST be configurable to allow unauthenticated read access for public data.

#### Scenario: Public schema access
- GIVEN schema `producten` is marked as publicly accessible
- WHEN an unauthenticated request reads producten objects
- THEN the objects MUST be returned without requiring authentication
- AND write operations MUST still require authentication

#### Scenario: Mixed public/private register
- GIVEN register `catalogi` with schema `producten` (public) and schema `interne-notities` (private)
- WHEN an unauthenticated request lists schemas
- THEN only `producten` MUST be visible
- AND `interne-notities` MUST NOT be discoverable

### Current Implementation Status
- **Implemented:**
  - `Consumer` entity (`lib/Db/Consumer.php`) with fields: uuid, name, description, domains (CORS), IPs, authType, secret, mappedUserId — supports JWT, Basic Auth, OAuth2, API Key
  - `ConsumerMapper` (`lib/Db/ConsumerMapper.php`) for CRUD operations on consumers
  - `ConsumersController` (`lib/Controller/ConsumersController.php`) for API consumer management
  - `AuthenticationService` (`lib/Service/AuthenticationService.php`) handling multi-method authentication
  - `AuthorizationService` (`lib/Service/AuthorizationService.php`) with ConsumerMapper integration for RBAC checks
  - `SecurityService` (`lib/Service/SecurityService.php`) for security enforcement
  - Twig authentication extensions (`lib/Twig/AuthenticationExtension.php`, `lib/Twig/AuthenticationRuntime.php`) providing `oauthToken` function for mapping templates
  - Nextcloud session auth works natively via the Nextcloud framework
  - Public endpoint support via `@PublicPage` annotations on controllers
- **NOT implemented:**
  - Explicit rate limiting per API consumer (configured limits, `Retry-After` headers)
  - Authentication event auditing (success/failure logging to audit trail)
  - SAML/OIDC integration within OpenRegister (relies on Nextcloud's SSO apps, but no explicit mapping/sync code)
  - JWT token auto-generation and one-time display workflow
  - Consumer revocation with immediate token invalidation
- **Partial:**
  - Rate limiting exists at Nextcloud level (bruteforce protection) but not configurable per consumer within OpenRegister
  - Public schema access exists via public API endpoints but mixed public/private schema discovery filtering is not explicitly implemented

### Standards & References
- **OAuth 2.0 (RFC 6749)** — Authorization framework
- **JWT (RFC 7519)** — JSON Web Token for API consumer authentication
- **SAML 2.0** — Via Nextcloud's user_saml app
- **OpenID Connect Core 1.0** — Via Nextcloud's user_oidc app
- **BIO (Baseline Informatiebeveiliging Overheid)** — Authentication and access control requirements
- **DigiD/eHerkenning** — Dutch government authentication standards (via SAML/OIDC)
- **RFC 6585** — HTTP 429 Too Many Requests for rate limiting
- **Nextcloud AppFramework** — `@PublicPage`, `@NoCSRFRequired`, `@CORS` annotations

### Specificity Assessment
- The spec covers the major auth methods well with clear scenarios.
- Missing: API endpoint definitions for consumer CRUD; JWT claim structure (required claims, audience, issuer); consumer entity schema (which fields are required vs optional).
- Ambiguous: how JWT validation works (symmetric vs asymmetric keys, key rotation); how SAML group-to-Nextcloud-group mapping is configured specifically for OpenRegister.
- Open questions:
  - Should API consumers be manageable via API or only via the admin UI?
  - What is the relationship between OpenRegister's Consumer entity and Nextcloud's built-in app passwords?
  - Should rate limiting be per-IP, per-consumer, or both? What are sensible defaults?
