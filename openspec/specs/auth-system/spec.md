---
status: implemented
retrofit_extensions:
  - REQ-001
---

# Authentication and Authorization System

## Purpose

@e2e exclude authentication/authorization backend — covered by PHPUnit
Define the authentication and authorization system for OpenRegister, supporting Nextcloud session auth, Basic Auth for API consumers, JWT bearer tokens for external systems, API key auth for MCP and service-to-service integration, and SSO integration via SAML/OIDC. The auth system MUST map all external identities to Nextcloud users via the Consumer entity and enforce consistent RBAC across every access method (REST, GraphQL, MCP, public endpoints), ensuring that a single identity model drives schema-level, property-level, and row-level security decisions.

**Source**: Core OpenRegister capability; 67% of tenders require SSO/identity integration; 86% require RBAC per zaaktype.
## Requirements
### Requirement: The system MUST support multiple authentication methods with unified identity resolution
OpenRegister MUST accept authentication via Nextcloud session cookies, HTTP Basic Auth, Bearer JWT tokens, OAuth2 bearer tokens, and API keys. All methods MUST resolve to a Nextcloud user identity (via `OCP\IUserSession::setUser()`) before any RBAC evaluation occurs, ensuring that authorization decisions are independent of the authentication method used.

#### Scenario: Nextcloud session authentication for browser users
- **GIVEN** a user is logged into Nextcloud via browser session
- **WHEN** they access OpenRegister pages or API endpoints
- **THEN** the request MUST be authenticated using the Nextcloud session cookie via `IUserSession`
- **AND** the user's Nextcloud identity and group memberships MUST be used for all subsequent RBAC checks

#### Scenario: Basic Auth for API consumers
- **GIVEN** an external system sends a request with `Authorization: Basic base64(user:pass)`
- **WHEN** the credentials are validated against Nextcloud's user backend via `IUserManager::checkPassword()`
- **THEN** the request MUST be authenticated as that Nextcloud user
- **AND** `AuthorizationService::authorizeBasic()` MUST call `$this->userSession->setUser($user)` so that downstream RBAC uses the resolved identity
- **AND** if the credentials are invalid, an `AuthenticationException` MUST be thrown

#### Scenario: JWT Bearer token for external systems
- **GIVEN** an API consumer configured in OpenRegister with `authorizationType: jwt`
- **WHEN** the consumer sends `Authorization: Bearer {jwt-token}`
- **THEN** `AuthorizationService::authorizeJwt()` MUST parse the token, extract the `iss` claim, look up the matching Consumer via `ConsumerMapper::findAll(['name' => issuer])`, verify the HMAC signature (HS256/HS384/HS512) using the Consumer's `authorizationConfiguration.publicKey`, validate `iat` and `exp` claims, and call `$this->userSession->setUser()` with the Consumer's mapped Nextcloud user (`Consumer::getUserId()`)

#### Scenario: API key authentication for MCP and service-to-service calls
- **GIVEN** an API consumer configured with `authorizationType: apiKey` and a map of valid keys to user IDs in `authorizationConfiguration`
- **WHEN** a request includes the API key in the designated header
- **THEN** `AuthorizationService::authorizeApiKey()` MUST look up the key, resolve it to a Nextcloud user via `IUserManager::get()`, and set the user session
- **AND** if the key is not found or the mapped user does not exist, an `AuthenticationException` MUST be thrown

#### Scenario: Reject invalid credentials with appropriate HTTP status
- **GIVEN** a request with invalid Basic Auth credentials, an expired JWT, or an unrecognized API key
- **THEN** the system MUST return HTTP 401 Unauthorized
- **AND** the response body MUST NOT leak information about whether the username exists
- **AND** the `SecurityService` MUST record the failed attempt for rate limiting purposes

### Requirement: API consumers MUST be configurable entities that bridge external systems to Nextcloud identities
Administrators MUST be able to create, update, and revoke Consumer entities that define how external systems authenticate with OpenRegister. Each Consumer MUST map to exactly one Nextcloud user for RBAC resolution.

#### Scenario: Create a JWT API consumer
- **GIVEN** the admin navigates to OpenRegister consumer management
- **WHEN** they create a consumer with:
  - `name`: `Zaaksysteem Extern` (also serves as JWT `iss` claim for matching)
  - `description`: `Integration with the external case management system`
  - `authorizationType`: `jwt`
  - `authorizationConfiguration`: `{ "publicKey": "shared-secret", "algorithm": "HS256" }`
  - `userId`: `api-zaaksysteem` (existing Nextcloud user)
  - `domains`: `["zaaksysteem.gemeente.nl"]` (for CORS)
  - `ips`: `["10.0.1.0/24"]` (for IP allow-listing)
- **THEN** the Consumer entity MUST be persisted with an auto-generated UUID
- **AND** subsequent JWT requests with `iss: "Zaaksysteem Extern"` MUST authenticate as `api-zaaksysteem`

#### Scenario: Create an API key consumer
- **GIVEN** the admin creates a consumer with `authorizationType: apiKey`
- **WHEN** `authorizationConfiguration` contains `{ "keys": { "sk_live_abc123": "api-user-1" } }`
- **THEN** requests with header matching `sk_live_abc123` MUST authenticate as Nextcloud user `api-user-1`

#### Scenario: Revoke a consumer
- **GIVEN** an active consumer `Zaaksysteem Extern`
- **WHEN** the admin deletes the consumer via `ConsumersController`
- **THEN** subsequent JWT requests with `iss: "Zaaksysteem Extern"` MUST fail with `AuthenticationException("The issuer was not found")`
- **AND** the HTTP response MUST be 401 Unauthorized

#### Scenario: Consumer with IP restrictions
- **GIVEN** consumer `Zaaksysteem Extern` has `ips: ["10.0.1.0/24"]`
- **WHEN** a valid JWT request arrives from IP `192.168.1.50` (outside the allowed range)
- **THEN** the system MUST reject the request with HTTP 403 Forbidden
- **AND** a security event MUST be logged

#### Scenario: Consumer with CORS domain restrictions
- **GIVEN** consumer `Zaaksysteem Extern` has `domains: ["zaaksysteem.gemeente.nl"]`
- **WHEN** a cross-origin request arrives with `Origin: https://evil.example.com`
- **THEN** `AuthorizationService::corsAfterController()` MUST NOT include `Access-Control-Allow-Origin` for the unauthorized origin
- **AND** `Access-Control-Allow-Credentials` MUST NOT be set to `true` (throws `SecurityException` if detected)

### Requirement: The RBAC model MUST enforce schema-level, property-level, and row-level access control using Nextcloud groups
Authorization MUST be evaluated at three levels: schema-level (can this user access this schema at all?), property-level (can this user see/modify specific fields?), and row-level (does this specific object match the user's access conditions?). All levels MUST use Nextcloud group memberships (`OCP\IGroupManager::getUserGroupIds()`) as the primary authorization primitive.

#### Scenario: Schema-level RBAC denies access to unauthorized group
- **GIVEN** schema `bezwaarschriften` has authorization: `{ "read": ["juridisch-team"], "create": ["juridisch-team"], "update": ["juridisch-team"], "delete": ["admin"] }`
- **AND** user `medewerker-1` is in group `kcc-team` (not `juridisch-team`)
- **WHEN** `medewerker-1` sends GET `/api/objects/{register}/bezwaarschriften`
- **THEN** `PermissionHandler::hasPermission()` MUST return `false` for action `read`
- **AND** `PermissionHandler::checkPermission()` MUST throw an Exception with message containing "does not have permission to 'read'"
- **AND** the HTTP response MUST be 403 Forbidden

#### Scenario: Property-level RBAC filters sensitive fields from API responses
- **GIVEN** schema `inwoners` has property `bsn` with authorization: `{ "read": [{ "group": "bsn-geautoriseerd" }], "update": [{ "group": "bsn-geautoriseerd" }] }`
- **AND** user `medewerker-1` is NOT in group `bsn-geautoriseerd`
- **WHEN** `medewerker-1` reads an inwoner object
- **THEN** `PropertyRbacHandler::filterReadableProperties()` MUST omit the `bsn` field from the response
- **AND** all other fields without property-level authorization MUST still be returned

#### Scenario: Row-level RBAC with conditional matching filters query results at the database level
- **GIVEN** schema `meldingen` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user `jan` is in group `behandelaars` with active organisation `org-uuid-1`
- **WHEN** `jan` lists meldingen
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST add a SQL WHERE clause: `t._organisation = 'org-uuid-1'`
- **AND** only meldingen belonging to `org-uuid-1` MUST be returned
- **AND** meldingen from other organisations MUST be filtered at the database query level (not post-fetch)

#### Scenario: Combined schema + property + row-level RBAC
- **GIVEN** schema `dossiers` with schema-level auth allowing `behandelaars`, property-level auth restricting `interneAantekening` to `redacteuren`, and row-level match on `_organisation`
- **WHEN** user `jan` (in `behandelaars`, NOT in `redacteuren`, org `org-1`) reads a dossier from `org-1`
- **THEN** schema-level check MUST pass (jan is in behandelaars)
- **AND** row-level check MUST pass (org matches)
- **AND** property-level check MUST filter out `interneAantekening` from the response

#### Scenario: Schema without authorization configuration allows all access
- **GIVEN** schema `tags` has no `authorization` array (empty or null)
- **WHEN** any authenticated user performs CRUD operations on `tags`
- **THEN** `PermissionHandler::hasGroupPermission()` MUST return `true` (no authorization = open access)

### Requirement: The role hierarchy MUST include admin bypass, owner privileges, public access, and authenticated access
The system MUST support a clear role hierarchy: `admin` > object owner > named groups > `authenticated` > `public`. Each level MUST be consistently evaluated across all handlers.

#### Scenario: Admin group bypasses all authorization checks
- **GIVEN** a user in the Nextcloud `admin` group
- **WHEN** they access any schema, property, or object in OpenRegister
- **THEN** `PermissionHandler::hasPermission()` MUST return `true` immediately after detecting admin group membership via `in_array('admin', $userGroups)`
- **AND** `PropertyRbacHandler::isAdmin()` MUST return `true`, bypassing all property filtering
- **AND** `MagicRbacHandler::applyRbacFilters()` MUST return without adding any WHERE clauses

#### Scenario: Object owner has full CRUD permissions on their own objects
- **GIVEN** user `jan` created object `melding-1` (objectOwner = `jan`)
- **AND** schema `meldingen` restricts write access to group `beheerders`
- **AND** `jan` is NOT in group `beheerders`
- **WHEN** `jan` updates `melding-1`
- **THEN** `PermissionHandler::hasGroupPermission()` MUST return `true` because `$objectOwner === $userId`
- **AND** `MagicRbacHandler` MUST include `t._owner = 'jan'` as an OR condition in SQL queries

#### Scenario: Public access for unauthenticated requests
- **GIVEN** schema `producten` has authorization: `{ "read": ["public"] }`
- **WHEN** an unauthenticated request (no session, no auth header) reads producten objects
- **THEN** `PermissionHandler::hasPermission()` MUST detect `$user === null` and check the `public` group
- **AND** `MagicRbacHandler::processSimpleRule('public')` MUST return `true`
- **AND** write operations MUST still require authentication (no `public` in create/update/delete rules)

#### Scenario: Authenticated pseudo-group grants access to any logged-in user
- **GIVEN** schema `feedback` has authorization: `{ "create": ["authenticated"] }`
- **WHEN** any logged-in Nextcloud user (regardless of specific group membership) creates a feedback object
- **THEN** `PropertyRbacHandler::userQualifiesForGroup('authenticated')` MUST return `true` when `$userId !== null`
- **AND** `MagicRbacHandler::processSimpleRule('authenticated')` MUST return `true` when `$userId !== null`

#### Scenario: Logged-in users inherit public permissions
- **GIVEN** schema `producten` has `read: ["public"]`
- **AND** user `jan` is logged in but not in any special group
- **WHEN** `jan` reads producten
- **THEN** `PermissionHandler::hasPermission()` MUST check public group as fallback after checking user's actual groups
- **AND** access MUST be granted because logged-in users have at least public-level access

### Requirement: Group-based access MUST support conditional matching with dynamic variables
Authorization rules MUST support conditional matching where access depends on both group membership AND runtime conditions evaluated against the object's data. The system MUST resolve dynamic variables including `$organisation`, `$userId`, and `$now`.

#### Scenario: Organisation-scoped access via $organisation variable
- **GIVEN** schema `zaken` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user `jan` is in group `behandelaars` with active organisation UUID `abc-123`
- **WHEN** `jan` queries zaken
- **THEN** `MagicRbacHandler::resolveDynamicValue('$organisation')` MUST return `abc-123` via `OrganisationService::getActiveOrganisation()`
- **AND** the SQL condition MUST be `t._organisation = 'abc-123'`
- **AND** the resolved organisation UUID MUST be cached in `$this->cachedActiveOrg` for subsequent calls within the same request

#### Scenario: User-scoped access via $userId variable
- **GIVEN** schema `taken` has authorization: `{ "read": [{ "group": "medewerkers", "match": { "assignedTo": "$userId" } }] }`
- **AND** user `jan` (UID: `jan`) is in group `medewerkers`
- **WHEN** `jan` queries taken
- **THEN** `MagicRbacHandler::resolveDynamicValue('$userId')` MUST return `jan` via `$this->userSession->getUser()->getUID()`
- **AND** only taken where `assigned_to = 'jan'` MUST be returned

#### Scenario: Time-based access via $now variable
- **GIVEN** schema `publicaties` has authorization: `{ "read": [{ "group": "public", "match": { "publishDate": { "$lte": "$now" } } }] }`
- **WHEN** an unauthenticated user queries publicaties
- **THEN** `MagicRbacHandler::resolveDynamicValue('$now')` MUST return the current datetime in `Y-m-d H:i:s` format
- **AND** only publicaties with `publish_date <= NOW()` MUST be returned

#### Scenario: Multiple match conditions require AND logic
- **GIVEN** a rule: `{ "group": "behandelaars", "match": { "_organisation": "$organisation", "status": "open" } }`
- **WHEN** a user in `behandelaars` queries objects
- **THEN** `MagicRbacHandler::buildMatchConditions()` MUST combine conditions with AND logic
- **AND** both `_organisation` and `status` conditions MUST be satisfied for an object to be returned

#### Scenario: Conditional rule on create operations skips organisation matching
- **GIVEN** property `interneAantekening` has authorization: `{ "update": [{ "group": "public", "match": { "_organisation": "$organisation" } }] }`
- **WHEN** a user creates a new object (no existing object data yet)
- **THEN** `PropertyRbacHandler::checkConditionalRule()` MUST call `$this->conditionMatcher->filterOrganisationMatchForCreate()` to remove `_organisation` from match conditions
- **AND** if the remaining match is empty, access MUST be granted

### Requirement: Multi-tenancy isolation MUST restrict data access to the user's active organisation
The system MUST enforce organisation-level data isolation so that users only see objects belonging to their active organisation, unless RBAC rules explicitly grant cross-organisation access.

#### Scenario: Organisation filtering in MagicMapper queries
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** the register has multi-tenancy enabled
- **WHEN** `jan` queries any schema in that register
- **THEN** `MultiTenancyTrait` MUST add a WHERE clause filtering on the organisation column
- **AND** objects from `org-uuid-2` MUST NOT be returned

#### Scenario: RBAC conditional rules can bypass multi-tenancy
- **GIVEN** schema `catalogi` has RBAC rule: `{ "read": [{ "group": "catalogus-beheerders", "match": { "aanbieder": "$organisation" } }] }`
- **AND** user `jan` is in `catalogus-beheerders`
- **WHEN** `MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()` evaluates the rules
- **THEN** it MUST detect that the match contains a non-`_organisation` field (`aanbieder`)
- **AND** multi-tenancy filtering MUST be bypassed, allowing RBAC to handle access control instead

#### Scenario: Admin users see all organisations
- **GIVEN** a user in the `admin` group
- **WHEN** they query any register
- **THEN** multi-tenancy filtering MUST be bypassed
- **AND** objects from all organisations MUST be visible

### Requirement: Public endpoints MUST use Nextcloud's annotation framework and enforce mixed visibility
Specific schemas and API endpoints MUST be configurable to allow unauthenticated read access using Nextcloud's `@PublicPage` annotation, while ensuring that write operations and private schemas remain protected.

#### Scenario: Public read endpoint via @PublicPage annotation
- **GIVEN** the `ObjectsController` has methods annotated with `@PublicPage` for public object access
- **WHEN** an unauthenticated request hits a public endpoint
- **THEN** Nextcloud's middleware MUST skip the login check
- **AND** `PermissionHandler::hasPermission()` MUST evaluate using the `public` pseudo-group
- **AND** if the schema has `read: ["public"]`, the objects MUST be returned

#### Scenario: Write operations on public endpoints still require authentication
- **GIVEN** schema `producten` is marked as publicly readable (`read: ["public"]`)
- **WHEN** an unauthenticated request attempts POST/PUT/DELETE on producten objects
- **THEN** `PermissionHandler::hasPermission()` MUST check the `public` group for the write action
- **AND** since `public` is not in create/update/delete rules, the request MUST be denied with HTTP 403

#### Scenario: Mixed public/private schemas in the same register
- **GIVEN** register `catalogi` with schema `producten` (read: `["public"]`) and schema `interne-notities` (read: `["redacteuren"]`)
- **WHEN** an unauthenticated request lists schemas or objects
- **THEN** only `producten` MUST be accessible
- **AND** `interne-notities` MUST return HTTP 403 for unauthenticated requests
- **AND** the OAS specification MUST reflect the different security requirements per schema

### Requirement: The system MUST support SSO via SAML, OIDC, and LDAP through Nextcloud's identity providers
OpenRegister MUST integrate with Nextcloud's SSO capabilities transparently, requiring no OpenRegister-specific SSO code. All SSO methods MUST result in a valid Nextcloud user session that OpenRegister can use for RBAC.

#### Scenario: SAML authentication flow
- **GIVEN** Nextcloud is configured with a SAML identity provider via the `user_saml` app
- **WHEN** a user authenticates via SAML
- **THEN** Nextcloud MUST create/map the user to a Nextcloud user account
- **AND** group memberships from SAML assertions MUST be synced to Nextcloud groups (configured in `user_saml`)
- **AND** OpenRegister MUST use the resulting `IUserSession` identity for all RBAC checks without any additional mapping

#### Scenario: OIDC authentication flow
- **GIVEN** Nextcloud is configured with an OpenID Connect provider via the `user_oidc` app
- **WHEN** a user authenticates via OIDC
- **THEN** OIDC claims MUST be mapped to Nextcloud user attributes by Nextcloud's OIDC app
- **AND** OpenRegister MUST use the mapped Nextcloud user identity from `IUserSession`

#### Scenario: LDAP group synchronization
- **GIVEN** Nextcloud is configured with LDAP backend for user and group management
- **WHEN** LDAP groups are synchronized to Nextcloud
- **THEN** the synchronized groups MUST be usable in OpenRegister schema authorization rules
- **AND** RBAC checks via `IGroupManager::getUserGroupIds()` MUST reflect LDAP group memberships

#### Scenario: DigiD/eHerkenning via SAML gateway
- **GIVEN** Nextcloud's SAML app is configured with a DigiD/eHerkenning SAML gateway
- **WHEN** a citizen authenticates via DigiD
- **THEN** the citizen MUST be mapped to a Nextcloud user
- **AND** OpenRegister MUST apply RBAC based on the mapped user's group memberships
- **AND** the BSN from the SAML assertion MUST be available as a user attribute for row-level security matching

### Requirement: Rate limiting MUST protect against brute force attacks and API abuse
The `SecurityService` MUST implement multi-layer rate limiting using APCu/distributed cache to prevent brute force authentication attacks and API abuse, with configurable thresholds and progressive delays.

#### Scenario: Rate limit failed login attempts per username
- **GIVEN** 5 failed login attempts for username `admin` within 900 seconds (15-minute window)
- **THEN** `SecurityService::checkLoginRateLimit()` MUST return `{ allowed: false, reason: "Too many login attempts" }`
- **AND** subsequent attempts MUST be blocked until the lockout expires (default: 3600 seconds / 1 hour)
- **AND** `SecurityService::recordFailedLoginAttempt()` MUST set the `openregister_user_lockout_admin` cache key

#### Scenario: Rate limit failed attempts per IP address
- **GIVEN** 5 failed login attempts from IP `10.0.1.50` within 900 seconds
- **THEN** all subsequent requests from that IP MUST be blocked (regardless of username)
- **AND** `SecurityService::recordFailedLoginAttempt()` MUST set the `openregister_ip_lockout_10.0.1.50` cache key

#### Scenario: Progressive delay for repeated failures
- **GIVEN** rate limiting is active for a user/IP combination
- **WHEN** additional attempts are made
- **THEN** the delay MUST increase progressively: 2s, 4s, 8s, 16s, 32s, capped at 60s (`MAX_PROGRESSIVE_DELAY`)
- **AND** the current delay MUST be stored in cache key `openregister_progressive_delay_{username}_{ip}`

#### Scenario: Successful login clears rate limits
- **GIVEN** user `admin` has 3 failed attempts recorded
- **WHEN** `admin` successfully authenticates
- **THEN** `SecurityService::recordSuccessfulLogin()` MUST clear all rate limit caches: user attempts, user lockout, IP attempts, IP lockout, and progressive delay

#### Scenario: Admin can manually clear rate limits
- **GIVEN** IP `10.0.1.50` is locked out due to suspicious activity
- **WHEN** an administrator calls `SecurityService::clearIpRateLimits('10.0.1.50')`
- **THEN** the IP lockout MUST be immediately cleared
- **AND** a security event `ip_rate_limits_cleared` MUST be logged

### Requirement: Authentication and security events MUST be audited
All authentication attempts (success and failure), lockouts, and security policy changes MUST be logged via `SecurityService::logSecurityEvent()` for security monitoring and compliance.

#### Scenario: Log successful authentication
- **GIVEN** user `admin` authenticates via Basic Auth from IP `10.0.1.50`
- **THEN** `SecurityService::recordSuccessfulLogin()` MUST log event `successful_login` with context: `username`, `ip_address`, `timestamp`

#### Scenario: Log failed authentication
- **GIVEN** an invalid JWT token is presented from IP `10.0.1.50`
- **THEN** `SecurityService::recordFailedLoginAttempt()` MUST log event `failed_login_attempt` with context: `username`, `ip_address`, `reason`, `user_attempts`, `ip_attempts`

#### Scenario: Log user lockout
- **GIVEN** user `admin` reaches 5 failed attempts
- **THEN** `SecurityService` MUST log event `user_locked_out` at WARNING level with context: `username`, `ip_address`, `attempts`, `lockout_until`

#### Scenario: Log IP lockout
- **GIVEN** IP `10.0.1.50` reaches 5 failed attempts
- **THEN** `SecurityService` MUST log event `ip_locked_out` at WARNING level with context: `ip_address`, `attempts`, `lockout_until`

#### Scenario: Log access during lockout
- **GIVEN** user `admin` is currently locked out
- **WHEN** another login attempt arrives
- **THEN** `SecurityService` MUST log event `login_attempt_during_lockout` at WARNING level

### Requirement: Permission evaluation results MUST be cacheable for performance
The system MUST cache frequently evaluated permission results to avoid repeated database queries and group lookups within the same request lifecycle.

#### Scenario: MagicRbacHandler caches active organisation UUID
- **GIVEN** user `jan` with active organisation `org-uuid-1`
- **WHEN** `MagicRbacHandler::getActiveOrganisationUuid()` is called multiple times within one request
- **THEN** the first call MUST resolve via `OrganisationService::getActiveOrganisation()` and store in `$this->cachedActiveOrg`
- **AND** subsequent calls MUST return the cached value without calling OrganisationService again

#### Scenario: Group memberships are resolved once per request
- **GIVEN** a request that triggers multiple RBAC checks across different schemas
- **WHEN** `IGroupManager::getUserGroupIds()` is called
- **THEN** the result SHOULD be cached at the service level to avoid repeated LDAP/database lookups within the same request

#### Scenario: RBAC at SQL level avoids post-fetch filtering
- **GIVEN** schema `meldingen` with RBAC rules
- **WHEN** `MagicRbacHandler::applyRbacFilters()` adds WHERE clauses to the query
- **THEN** filtering MUST happen at the database query level
- **AND** unauthorized objects MUST never be loaded into PHP memory
- **AND** pagination counts MUST reflect only the accessible result set

### Requirement: CORS policy MUST be enforced per Consumer and prevent CSRF
The `AuthorizationService::corsAfterController()` method MUST enforce CORS headers based on the request origin, and MUST prevent CSRF attacks by rejecting `Access-Control-Allow-Credentials: true`.

#### Scenario: Add CORS headers for valid origin
- **GIVEN** a cross-origin request with `Origin: https://zaaksysteem.gemeente.nl`
- **WHEN** `AuthorizationService::corsAfterController()` processes the response
- **THEN** the response MUST include `Access-Control-Allow-Origin: https://zaaksysteem.gemeente.nl`

#### Scenario: Reject CSRF-unsafe CORS configuration
- **GIVEN** a response that includes `Access-Control-Allow-Credentials: true`
- **WHEN** `AuthorizationService::corsAfterController()` inspects the response headers
- **THEN** a `SecurityException` MUST be thrown with message "Access-Control-Allow-Credentials must not be set to true in order to prevent CSRF"

#### Scenario: Security headers added to responses
- **GIVEN** any API response from OpenRegister
- **WHEN** `SecurityService::addSecurityHeaders()` processes the response
- **THEN** the following headers MUST be set: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection: 1; mode=block`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none';`, `Cache-Control: no-store, no-cache, must-revalidate, private`

### Requirement: MCP endpoint authentication MUST use Nextcloud's standard auth mechanisms
The MCP server endpoint (`/api/mcp`) MUST require authentication via Nextcloud's standard mechanisms (session or Basic Auth) and MUST NOT implement a separate authentication layer.

#### Scenario: MCP endpoint requires authentication
- **GIVEN** the MCP endpoint at `/index.php/apps/openregister/api/mcp`
- **WHEN** an unauthenticated request is sent
- **THEN** Nextcloud's middleware MUST reject the request with HTTP 401
- **AND** the `McpServerController` MUST NOT be invoked

#### Scenario: MCP endpoint uses Basic Auth for programmatic access
- **GIVEN** an MCP client configured with Basic Auth credentials (`admin:admin`)
- **WHEN** the client sends a JSON-RPC 2.0 request to the MCP endpoint
- **THEN** Nextcloud MUST authenticate the user via Basic Auth
- **AND** the MCP tools MUST operate in the context of the authenticated user
- **AND** RBAC MUST apply to all register/schema/object operations performed via MCP tools

#### Scenario: MCP session isolation
- **GIVEN** two different MCP clients authenticated as different users
- **WHEN** each client performs operations via the MCP endpoint
- **THEN** each session MUST be isolated using the `Mcp-Session-Id` header
- **AND** RBAC checks MUST use the respective authenticated user's identity

### Requirement: Service-to-service authentication MUST support outbound token generation
The `AuthenticationService` MUST generate outbound authentication tokens (OAuth2 access tokens, signed JWTs) for calls to external services configured as Sources, supporting multiple signing algorithms and OAuth2 grant types.

#### Scenario: Generate OAuth2 client_credentials token for outbound call
- **GIVEN** an external Source configured with OAuth2 client credentials
- **WHEN** `AuthenticationService::fetchOAuthTokens()` is called with grant_type `client_credentials`
- **THEN** the service MUST POST to the configured `tokenUrl` with `client_id` and `client_secret`
- **AND** the resulting `access_token` MUST be returned for use in outbound API calls

#### Scenario: Generate signed JWT for outbound call
- **GIVEN** an external Source configured with JWT authentication
- **WHEN** `AuthenticationService::fetchJWTToken()` is called
- **THEN** the service MUST render the Twig payload template, sign it with the configured algorithm (HS256, HS384, HS512, RS256, RS384, RS512, PS256), and return the compact-serialized JWT

#### Scenario: Generate JWT with x5t certificate thumbprint
- **GIVEN** an external Source requiring x5t header in JWT
- **WHEN** the configuration includes an `x5t` value
- **THEN** the JWT header MUST include `{ "alg": "...", "typ": "JWT", "x5t": "..." }`

### Requirement: Input sanitization MUST prevent XSS and injection attacks
The `SecurityService` MUST sanitize all user inputs to prevent cross-site scripting (XSS) and injection attacks, applying defense-in-depth beyond Nextcloud's built-in protections.

#### Scenario: Sanitize login credentials
- **GIVEN** a login attempt with username containing `<script>alert(1)</script>`
- **WHEN** `SecurityService::validateLoginCredentials()` processes the input
- **THEN** the username MUST be sanitized via `htmlspecialchars()` with ENT_QUOTES
- **AND** null bytes MUST be stripped
- **AND** JavaScript event handlers (`onload=`, `onerror=`, etc.) MUST be removed
- **AND** the sanitized username MUST be truncated to 320 characters maximum

#### Scenario: Reject credentials with invalid characters
- **GIVEN** a username containing `<>"\'/\\` characters
- **WHEN** `SecurityService::validateLoginCredentials()` processes the input
- **THEN** the validation MUST return `{ valid: false, error: "Username contains invalid characters" }`

#### Scenario: Prevent excessively long passwords
- **GIVEN** a login attempt with a password exceeding 1000 characters
- **WHEN** `SecurityService::validateLoginCredentials()` processes the input
- **THEN** the validation MUST return `{ valid: false, error: "Password is too long" }`

### REQ-001: The system SHALL provide an idempotent OCC command to backfill the `__system__` owner sentinel on legacy magic-table rows
The system MUST provide an idempotent OCC command (`occ openregister:backfill-system-owner`) to backfill the `__system__` owner sentinel on legacy magic-table rows. *(Added by retrofit-2026-05-24-2b-command-repair-middleware, archived.)*

`occ openregister:backfill-system-owner` is a one-shot operational command that scans every magic table reachable by combining (register, schema) pairs and, for each row where `_owner = ''`, sets `_owner` to `OrganisationService::SYSTEM_USER_ID_DEFAULT` (the `__system__` sentinel). The command is idempotent by design: re-running on already-backfilled tables produces a per-table `scanned=N updated=0` line and the grand total returns `0` updates.

The command is implemented in `OCA\OpenRegister\Command\BackfillSystemOwnerCommand`, registered through Nextcloud's symfony console wiring. Its three options are: `--dry-run` (count without writing), `--register=<slug|uuid|id>` (limit scope to one register), `--schema=<slug|uuid|id>` (limit scope to one schema). Both mappers are resolved via `RegisterMapper::find()` / `findAll()` and `SchemaMapper::find()` / `findAll()` with `_rbac: false, _multitenancy: false` — the command bypasses RBAC and tenancy so legacy rows belonging to suspended/inactive tenants are still backfilled. Magic-table existence is verified per (register, schema) via `MagicMapper::tableExistsForRegisterSchema()`; missing tables are silently skipped. The actual DML uses `IDBConnection::getQueryBuilder()` with a count query (selecting rows where `_owner = ''`) followed (unless `--dry-run`) by an UPDATE that sets `_owner = OrganisationService::SYSTEM_USER_ID_DEFAULT` on the same predicate.

On failure to resolve a register or schema, the command writes the error message to stderr in `<error>...</error>` tags and exits with `Command::FAILURE`. On success (including the no-op idempotent case) it exits with `Command::SUCCESS`. A final `<info>Done. Tables=N scanned=M updated=K (dry run — no writes performed)</info>` summary is always written; the `(dry run — no writes performed)` suffix is conditional on the `--dry-run` flag.

#### Scenario: Run backfill across all tables
- **GIVEN** an OpenRegister deployment with 3 magic tables, some carrying rows from before #1645 (i.e. `_owner = ''`)
- **WHEN** an admin runs `occ openregister:backfill-system-owner`
- **THEN** the command iterates every register × every schema in the register's `getSchemas()` allow-list
- **AND** for each (register, schema) pair where `MagicMapper::tableExistsForRegisterSchema()` returns true, the rows with `_owner = ''` are counted and then UPDATEd to `_owner = '__system__'` via the query builder
- **AND** for each table a `register-slug/schema-slug (table-name): scanned=N updated=N` line is written
- **AND** the final summary reports `Tables=<count> scanned=<grand-total> updated=<grand-total>`
- **AND** the command exits with `Command::SUCCESS` (0)

#### Scenario: Idempotent re-run on a fully backfilled deployment
- **GIVEN** an OpenRegister deployment where the backfill command was already executed and every magic table now has `_owner != ''`
- **WHEN** the admin re-runs `occ openregister:backfill-system-owner`
- **THEN** every table's count query returns `scanned=0`
- **AND** the UPDATE statement is skipped (early return on `$scanned === 0`)
- **AND** the summary reports `Tables=<count> scanned=0 updated=0`

#### Scenario: Scope to a single register
- **GIVEN** a deployment with 5 registers
- **WHEN** the admin runs `occ openregister:backfill-system-owner --register=meldingen`
- **THEN** `resolveRegisters()` returns the single register matching slug/uuid/id `meldingen` via `RegisterMapper::find()`
- **AND** only that register's tables are scanned
- **AND** unrelated registers are not touched

#### Scenario: Dry-run reports counts without writing
- **GIVEN** a magic table with 100 rows where `_owner = ''`
- **WHEN** the admin runs `occ openregister:backfill-system-owner --dry-run`
- **THEN** `backfillTable()` runs the count query and returns `[100, 0]`
- **AND** the UPDATE statement is NOT executed
- **AND** the per-table line reports `scanned=100 updated=0`
- **AND** the summary suffix is `(dry run — no writes performed)`

#### Scenario: Register or schema lookup failure
- **GIVEN** the admin runs `occ openregister:backfill-system-owner --register=does-not-exist`
- **WHEN** `RegisterMapper::find('does-not-exist', _multitenancy: false)` throws (entity not found)
- **THEN** the throwable's message is written to stderr wrapped in `<error>...</error>`
- **AND** the command exits with `Command::FAILURE` (1)
- **AND** no writes are performed

### Requirement: Register and schema read endpoints MUST remain reachable when OpenRegister is restricted to a user group
When an administrator limits OpenRegister to specific Nextcloud groups (the *"Limit app to groups"* setting, i.e. `occ app:enable openregister --groups <group>`), Nextcloud blocks every non-`#[PublicPage]` route for users outside those groups (dispatch is gated by `IAppManager::isEnabledForUser()`). Because OpenRegister's register, schema, and object **read** endpoints are consumed by other apps on behalf of every user, those read endpoints MUST be marked `#[PublicPage]` so they survive the app-group restriction. Write/management endpoints MUST remain non-public so that the same restriction continues to limit management to the group.

#### Scenario: A user outside the restriction group can read registers and schemas
- **GIVEN** OpenRegister is enabled only for group `data-engineers`
- **AND** user `alice` is authenticated but is **not** a member of `data-engineers`
- **WHEN** `alice` calls `GET /api/registers` and `GET /api/schemas`
- **THEN** both requests MUST succeed (HTTP 200), returning the registers/schemas she is permitted to see under RBAC
- **AND** `GET /api/objects/{register}/{schema}` MUST also succeed, scoped by `ObjectService` RBAC/multitenancy

#### Scenario: A user outside the restriction group cannot manage registers or schemas
- **GIVEN** OpenRegister is enabled only for group `data-engineers`
- **AND** user `alice` is authenticated but is **not** a member of `data-engineers`
- **WHEN** `alice` calls `POST`, `PUT`, `PATCH`, or `DELETE` on `/api/registers` or `/api/schemas`
- **THEN** the request MUST NOT succeed — it is either blocked by the Nextcloud app-group restriction (non-public route) or rejected by the register/schema write authorization check (HTTP 403)

### Requirement: Public read endpoints MUST require an authenticated user except for published resources
Marking register/schema read endpoints `#[PublicPage]` also exposes them to fully anonymous callers. Register and schema **definitions** MUST NOT be served to anonymous callers unless the specific resource is published. A resource is *published* when its `published` field is set (non-null) and its `depublished` field is null. Authenticated users are unaffected and continue to receive results scoped by the existing RBAC / multitenancy rules.

#### Scenario: Anonymous list returns only published registers/schemas
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **WHEN** the caller requests `GET /api/registers` or `GET /api/schemas`
- **THEN** the response MUST contain only resources whose `published` is non-null and `depublished` is null
- **AND** unpublished resources MUST be absent from the result set

#### Scenario: Anonymous show of an unpublished resource is rejected
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **AND** register/schema `X` is not published
- **WHEN** the caller requests `GET /api/registers/X` or `GET /api/schemas/X`
- **THEN** the response MUST be HTTP 401 (authentication required)

#### Scenario: Anonymous show of a published resource succeeds
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **AND** register/schema `Y` is published (`published` set, `depublished` null)
- **WHEN** the caller requests `GET /api/registers/Y` or `GET /api/schemas/Y`
- **THEN** the response MUST be HTTP 200 with the resource definition

#### Scenario: Authenticated read is unaffected by the published gate
- **GIVEN** user `bob` is authenticated
- **WHEN** `bob` reads registers/schemas
- **THEN** the published/unpublished gate MUST NOT apply — `bob` receives every resource permitted by his RBAC scope, published or not

### Requirement: The published-visibility gate MUST derive from server-side entity state
The decision to expose a register or schema to an anonymous caller MUST be derived from the persisted `published`/`depublished` fields on the entity, never from client-supplied request parameters. This prevents an anonymous caller from bypassing the gate by asserting visibility in the request.

#### Scenario: Client cannot assert published state
- **GIVEN** an anonymous caller requests an unpublished register `X` with a query/body parameter claiming `published=true`
- **WHEN** the read-visibility guard evaluates the request
- **THEN** the guard MUST ignore the client-supplied value and use the entity's persisted `published`/`depublished` fields
- **AND** the request MUST be rejected with HTTP 401

### Requirement: Object write operations MUST fail closed for anonymous callers
Creating or updating an object MUST be denied for an anonymous caller (no resolved Nextcloud user) unless the target schema's `authorization` explicitly grants the `public` group the requested write action. A schema with no `authorization` block, or no entry for the action, MUST NOT permit anonymous writes by default. Authenticated callers are out of scope of this requirement (their write authorization is governed by the existing schema RBAC rules).

#### Scenario: Anonymous write to a schema with no authorization rule is denied
- **GIVEN** a schema with no `authorization` block (or no `create`/`update` entry)
- **WHEN** an anonymous caller sends `POST`/`PUT /api/objects/{register}/{schema}`
- **THEN** the request MUST be rejected with HTTP 403
- **AND** no object is created or modified

#### Scenario: Anonymous write to a schema that declares public write is allowed
- **GIVEN** a schema whose `authorization` grants the `public` group the `create` action
- **WHEN** an anonymous caller sends `POST /api/objects/{register}/{schema}`
- **THEN** the request MUST be allowed (the schema opted in to public submissions)

#### Scenario: Authenticated write behaviour is unchanged
- **GIVEN** an authenticated user
- **WHEN** they create or update an object
- **THEN** the authorization outcome MUST be identical to before this change (this requirement only constrains anonymous writes)

### Requirement: SQL/list RBAC match evaluation MUST fail closed on unresolved dynamic variables
When a schema `authorization` rule carries a `match` clause referencing a dynamic variable (e.g. `$organisation`, `$userId`, `$now`) that resolves to `null` for the current principal, the SQL/list-path evaluator MUST treat that match property as unsatisfiable (emit an impossible predicate, `1 = 0`) rather than dropping it. The list path and the single-object find path MUST produce identical authorization verdicts for the same rule and principal.

#### Scenario: Multi-condition match with a null dynamic variable denies on both list and find
- **GIVEN** a read rule `{ "group": "public", "match": { "name": "X", "organisation": "$organisation" } }`
- **AND** a principal for whom `$organisation` resolves to `null`
- **WHEN** the principal lists objects (`GET /api/objects/{register}/{schema}`) and fetches the single object (`GET /api/objects/{register}/{schema}/{uuid}`)
- **THEN** BOTH requests MUST deny access to the object (the unresolved `organisation` predicate is not silently dropped on the list path)

#### Scenario: Rules whose dynamic variables resolve are unaffected
- **GIVEN** the same rule and a principal for whom `$organisation` resolves to a concrete value
- **WHEN** the principal lists and finds objects
- **THEN** access is granted exactly as before — the fail-closed change introduces no new denials for resolvable rules

#### Scenario: Single-condition match parity is preserved
- **GIVEN** a single-condition `match` rule on a dynamic variable that resolves to null
- **WHEN** evaluated on the list and find paths
- **THEN** both paths MUST deny (the SQL path no longer differs from the PHP path)

### Requirement: Schema and register METADATA-READ lookups MUST bypass multi-tenancy; metadata WRITE lookups MUST enforce it

OpenRegister's multi-tenancy isolation lives at the OBJECT-row level via `MultiTenancyTrait` on `MagicMapper` queries (see existing requirement "Multi-tenancy isolation MUST restrict data access to the user's active organisation"). Schema and register **definitions** are a globally-visible catalog — this is already the established contract via `@PublicPage` on `SchemasController::index`/`show`, both of which pass `_multitenancy: false` to `SchemaMapper::find`/`findAll`.

To eliminate inconsistent inheritance of the `_multitenancy: true` default, every code path whose **purpose** is to **resolve a schema or register entity for reading metadata, computing over its data, or rendering it to a consumer** MUST pass `_multitenancy: false` to `SchemaMapper::find` / `SchemaMapper::findAll` / `RegisterMapper::find` / `RegisterMapper::findAll`. Conversely, every code path whose **purpose** is to **authorize an administrative mutation against the entity** (create, update, patch, delete, upload-as-update) MUST keep the default `_multitenancy: true`. The mapper's default of `true` is intentionally the safe-for-mutation default; the policy is per-caller, not per-mapper.

The `Schema "%s" not found.` / `Register "%s" not found.` 404 path is preserved: an unknown ref still results in `DoesNotExistException` regardless of the tenancy argument; nothing else about lookup semantics changes.

#### Scenario: Tenant user lists schemas via the public catalog endpoint
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schemas exist with `organisation = 'org-uuid-1'`, `organisation = 'org-uuid-2'`, and `organisation IS NULL`
- **WHEN** `jan` calls `GET /api/schemas`
- **THEN** the request MUST succeed (HTTP 200)
- **AND** the response MUST contain schemas from all three groups, scoped only by the existing read-accessibility published gate (not by tenancy)
- **AND** `SchemaMapper::findAll(..., _multitenancy: false)` MUST be the underlying call

#### Scenario: Admin without active organisation runs an aggregation that resolves a schema by ref
- **GIVEN** an admin user (in the `admin` Nextcloud group) with NO active organisation set
- **AND** a schema `meldingen` exists with `organisation = 'org-uuid-1'`
- **WHEN** the admin calls an aggregation endpoint whose runner invokes `AggregationRunner::loadSchema(schemaRef: 'meldingen')`
- **THEN** the schema MUST resolve via `SchemaMapper::find('meldingen', _multitenancy: false)`
- **AND** the aggregation runner MUST proceed (no `Schema "meldingen" not found.` 404)
- **AND** any object-row enumeration the runner performs subsequently MUST still be tenant-filtered by `MultiTenancyTrait` against `MagicMapper`, per the existing object-row multi-tenancy requirement

#### Scenario: Background job (system actor) resolves a register for aggregation
- **GIVEN** a scheduled job running as the system actor (no Nextcloud session, no active organisation)
- **AND** a register `zaken` exists with `organisation = 'org-uuid-2'`
- **WHEN** the job invokes `AggregationRunner::loadRegister(registerRef: 'zaken')`
- **THEN** the register MUST resolve via `RegisterMapper::find('zaken', _multitenancy: false)`
- **AND** the job MUST proceed without a `Register "zaken" not found.` 404

#### Scenario: Tenant user reads a single schema (download, related, stats, publish/depublish lookups)
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `producten` exists with `organisation = 'org-uuid-2'`
- **WHEN** `jan` calls `GET /api/schemas/producten/download`, `/related`, `/stats`, or hits the GET lookup inside the publish/depublish flow
- **THEN** the schema MUST resolve via `SchemaMapper::find('producten', ..., _multitenancy: false)`
- **AND** the response MUST be HTTP 200 (subject to the existing read-accessibility published gate when the caller is anonymous)

#### Scenario: Tenant user attempts to UPDATE a schema owned by another tenant
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `producten` exists with `organisation = 'org-uuid-2'`
- **AND** `jan` does NOT have schema-manage permission on `producten`
- **WHEN** `jan` calls `PUT /api/schemas/producten`
- **THEN** the underlying lookup MUST be `SchemaMapper::find('producten')` with default `_multitenancy: true` (mutation-gating lookup MUST NOT bypass tenancy)
- **AND** the mutation MUST be rejected with HTTP 404 (the schema is not in `jan`'s tenant scope and is therefore unresolvable for the purpose of authorizing a mutation) OR HTTP 403 (if the schema is resolvable but `checkSchemaManagePermission` denies)
- **AND** no schema record MUST be modified

#### Scenario: Tenant user attempts to DELETE a schema owned by another tenant
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `interne-notities` exists with `organisation = 'org-uuid-2'`
- **WHEN** `jan` calls `DELETE /api/schemas/interne-notities`
- **THEN** the underlying lookup MUST be `SchemaMapper::find('interne-notities')` with default `_multitenancy: true`
- **AND** the mutation MUST be rejected (404 or 403 per `checkSchemaManagePermission`)
- **AND** no schema record MUST be deleted

#### Scenario: Tenant user attempts to UPLOAD-AS-UPDATE a schema owned by another tenant
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `bezwaarschriften` exists with `organisation = 'org-uuid-2'`
- **WHEN** `jan` calls `POST /api/schemas/bezwaarschriften/upload` with a JSON body
- **THEN** the underlying existing-schema lookup MUST be `SchemaMapper::find($id)` with default `_multitenancy: true`
- **AND** the mutation MUST be rejected (the existing-schema branch fails to resolve, OR the manage-permission check denies)

#### Scenario: Unknown schema ref returns 404 regardless of tenancy state
- **GIVEN** no schema with ref `does-not-exist` is persisted
- **WHEN** any caller (tenant user, admin, or background job) invokes `AggregationRunner::loadSchema(schemaRef: 'does-not-exist')`
- **THEN** `SchemaMapper::find('does-not-exist', _multitenancy: false)` MUST throw `DoesNotExistException`
- **AND** the runner MUST rethrow as `RuntimeException` with message `Schema "does-not-exist" not found.`
- **AND** `AggregationController` MUST translate that into HTTP 404

#### Scenario: Object-row data remains tenant-isolated independently of metadata read
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `meldingen` is resolvable to `jan` via the metadata-read bypass (regardless of the schema's own `organisation`)
- **WHEN** `jan` lists objects under that schema (`GET /api/objects/{register}/meldingen`)
- **THEN** the OBJECT-row query MUST be tenant-filtered by `MultiTenancyTrait` (see the existing object-row multi-tenancy requirement)
- **AND** only objects with `_organisation = 'org-uuid-1'` (plus RBAC matches) MUST be returned
- **AND** schema-definition metadata reads (now bypassing tenancy) MUST NOT widen object-row access in any way

#### Scenario: The metadata-read bypass MUST NOT change the in-memory mapper default
- **GIVEN** a new caller is added that calls `SchemaMapper::find($id)` without specifying `_multitenancy`
- **THEN** the call MUST continue to use the default `_multitenancy: true` (the safe-for-mutation default)
- **AND** the new caller MUST explicitly opt into `_multitenancy: false` if it is a metadata-read path, per this requirement

### Requirement: The system MUST provide a public self-service login and logout surface with brute-force protection

`UserController` MUST expose `login` and `logout` as `@PublicPage` endpoints that complement the Consumer/API authentication methods with an interactive, session-based self-service flow. `login` MUST: validate and sanitize credentials via `SecurityService::validateLoginCredentials()`; enforce per-username and per-IP rate limiting via `SecurityService::checkLoginRateLimit()` (returning HTTP 429 with `retry_after`/`lockout_until` and applying any progressive delay); authenticate via `IUserManager::checkPassword()`; reject disabled accounts; record success/failure via `SecurityService`; on success call `IUserSession::setUser()` and return the sanitized user data with `session_created: true`. A pre-auth memory guard MUST return HTTP 503 when usage already exceeds 80% of the memory limit. Failed authentication MUST return a generic HTTP 401 ("Invalid username or password") that does not reveal whether the username exists. `logout` MUST end the session via `IUserSession::logout()`. Every response MUST pass through `SecurityService::addSecurityHeaders()`.

#### Scenario: Successful login creates a session
- **GIVEN** valid credentials for an enabled user that is not rate-limited
- **WHEN** `login` processes the request
- **THEN** the user MUST be set on the session via `IUserSession::setUser()`
- **AND** the response MUST contain `{message: "Login successful", user, session_created: true}` with security headers

#### Scenario: Rate limit returns 429
- **GIVEN** the username/IP combination is over the failed-attempt threshold
- **WHEN** `login` checks `SecurityService::checkLoginRateLimit()`
- **THEN** the response MUST be HTTP 429 with `retry_after` / `lockout_until`
- **AND** any specified progressive delay MUST be applied before responding

#### Scenario: Failure does not leak username existence
- **GIVEN** an invalid password (or unknown username)
- **WHEN** authentication fails
- **THEN** the response MUST be a generic HTTP 401 "Invalid username or password"
- **AND** the failed attempt MUST be recorded via `SecurityService::recordFailedLoginAttempt()`

#### Scenario: Disabled account is rejected
- **GIVEN** valid credentials for a disabled account
- **THEN** the response MUST be HTTP 401 "Account is disabled" and the failure MUST be recorded

### Requirement: The system MUST let an authenticated user manage their own profile, credentials, and avatar

`UserController` MUST provide self-service profile management gated by an authenticated session (HTTP 401 when `UserService::getCurrentUser()` is null). `me` MUST return the current user's profile (`UserService::buildUserDataArray()`). `updateMe` MUST sanitize the payload, strip leading-underscore internal keys and the immutable `id`/`uid`/`created` fields, and persist via `UserService::updateUserProperties()`. `changePassword` MUST require `currentPassword` and `newPassword`, apply login rate limiting, delegate to `UserService::changePassword()`, and on a 403 ("incorrect current password") record a failed attempt for brute-force protection. `uploadAvatar` MUST read the raw request body, reject an empty body with HTTP 400, and delegate to `UserService::uploadAvatar()`; `deleteAvatar` MUST delegate to `UserService::deleteAvatar()`. All responses MUST carry security headers.

#### Scenario: Unauthenticated access is rejected
- **GIVEN** no authenticated session
- **WHEN** any of `me`, `updateMe`, `changePassword`, `uploadAvatar`, `deleteAvatar` is called
- **THEN** the response MUST be HTTP 401 with `{error: "Not authenticated"}`

#### Scenario: Profile update drops immutable and internal fields
- **GIVEN** an `updateMe` payload containing `id`, `uid`, `created`, and `_internal`
- **WHEN** the controller prepares the data
- **THEN** those keys MUST be removed before `UserService::updateUserProperties()` is called
- **AND** the remaining values MUST be sanitized via `SecurityService::sanitizeInput()`

#### Scenario: Wrong current password is rate-limit tracked
- **GIVEN** `changePassword` with an incorrect `currentPassword` (service throws with code 403)
- **THEN** the response MUST surface the 403
- **AND** a failed attempt MUST be recorded via `SecurityService::recordFailedLoginAttempt()` with reason `password_change_incorrect`

#### Scenario: Avatar upload requires a body
- **GIVEN** an `uploadAvatar` request with an empty body
- **THEN** the response MUST be HTTP 400 with `{error: "No image data provided"}`

### Requirement: The system MUST provide personal-data, activity, notification, token, and account-deactivation self-service

`UserController` MUST expose the remaining authenticated self-service operations, each rejecting an unauthenticated caller with HTTP 401 and carrying security headers:

- `exportData` (GDPR) MUST return the user's personal data as a downloadable JSON attachment (`DataDownloadResponse`, filename `openregister-export-{uid}-{date}.json`); a rate-limit (service code 429) MUST surface as HTTP 429.
- `getActivity` MUST return paginated personal activity history (`_limit`/`_offset`, optional `type`/`_from`/`_to` filters).
- `getNotificationPreferences` / `updateNotificationPreferences` MUST read and write the user's notification preferences (internal `_`-prefixed keys stripped on write; invalid input → HTTP 400).
- `listTokens`, `createToken`, `revokeToken` MUST manage the user's personal API tokens; `createToken` MUST require a non-empty `name` (HTTP 400 otherwise) and return HTTP 201.
- `requestDeactivation`, `getDeactivationStatus`, `cancelDeactivation` MUST drive the account-deactivation lifecycle; a conflicting request (service code 409) MUST surface as HTTP 409.

#### Scenario: Personal data export is a download
- **GIVEN** an authenticated user calls `exportData`
- **WHEN** the export is generated
- **THEN** the response MUST be a `DataDownloadResponse` with `application/json` and filename `openregister-export-{uid}-{date}.json`

#### Scenario: Token creation requires a name
- **GIVEN** a `createToken` request with an empty `name`
- **THEN** the response MUST be HTTP 400 with `{error: "Token name is required"}`
- **AND** a valid request MUST return HTTP 201 with the created token

#### Scenario: Deactivation conflict
- **GIVEN** `requestDeactivation` when a request already exists (service throws code 409)
- **THEN** the response MUST be HTTP 409 with the service's conflict payload

#### Scenario: Notification preference update rejects invalid input
- **GIVEN** `updateNotificationPreferences` with invalid input (service throws `InvalidArgumentException`)
- **THEN** the response MUST be HTTP 400 with the exception message

### Requirement: The system MUST let a user manage their own GitHub personal access token without exposing it

`UserSettingsController` MUST provide per-user GitHub PAT management, each endpoint requiring an authenticated session (HTTP 401 otherwise). `getGitHubTokenStatus` MUST report `{hasToken, isValid, message}` and MUST NOT return the token value itself; when a token exists it MUST be validated via `GitHubHandler::validateToken()`. `setGitHubToken` MUST require a non-empty `token`, validate it via `GitHubHandler` before considering it saved, and reject an invalid token with HTTP 400 ("Invalid GitHub token"). `removeGitHubToken` MUST clear the user's token. The token MUST be stored and validated per `userId` so one user's token is never resolvable by another.

#### Scenario: Status never echoes the token
- **GIVEN** the user has a stored GitHub token
- **WHEN** `getGitHubTokenStatus` is called
- **THEN** the response MUST contain only `{hasToken: true, isValid, message}` and MUST NOT contain the token string

#### Scenario: Invalid token is rejected on save
- **GIVEN** a `setGitHubToken` request whose token fails `GitHubHandler::validateToken()`
- **THEN** the response MUST be HTTP 400 with `{error: "Invalid GitHub token"}`
- **AND** an empty/missing token MUST return HTTP 400 with `{error: "Token is required"}`

#### Scenario: Token operations require authentication
- **GIVEN** no authenticated session
- **WHEN** any of `getGitHubTokenStatus`, `setGitHubToken`, `removeGitHubToken` is called
- **THEN** the response MUST be HTTP 401 with `{error: "User not authenticated"}`

### Requirement: Writes without an active user session MUST be attributed to a system identifier
When an OpenRegister object is created via `ObjectService::saveObject()` and `IUserSession::getUser()` returns `null` (cron job, queue worker, internal service call), the system MUST set `_owner` on the new row to a configured system identifier instead of leaving it empty. The identifier is read from the `openregister.systemUserId` app-config key. The default value is `__system__`, which Nextcloud's user-ID validator rejects for real user creation, guaranteeing the identifier cannot collide with any logged-in user.

#### Scenario: Cron-job write gets system owner
- **GIVEN** a background job (no `IUserSession` user) calls `ObjectService::saveObject(...)` on a register/schema it is allowed to write
- **WHEN** `SaveObject::prepareObjectForCreation()` runs
- **THEN** the persisted `ObjectEntity` MUST have `_owner = '__system__'` (or the configured `openregister.systemUserId` value)
- **AND** the object MUST still receive an `_organisation` value via the existing `OrganisationService::getOrganisationForNewEntity()` fallback to the default organisation

#### Scenario: Logged-in writes are unchanged
- **GIVEN** a user `alice` is in the active `IUserSession`
- **WHEN** `SaveObject::prepareObjectForCreation()` runs
- **THEN** `_owner` MUST be set to `alice` (NOT the system identifier)

#### Scenario: Operator can override the system identifier
- **GIVEN** an operator sets `openregister.systemUserId` to `cron-bot` via OCC or app-config
- **WHEN** a session-less write happens
- **THEN** the persisted row MUST have `_owner = 'cron-bot'`

### Requirement: System-owned rows MUST be visible to admin readers in the RBAC filter
Both `MagicRbacHandler::applyRbacFilters()` and `MagicRbacHandler::buildRbacConditionsSql()` MUST treat rows where `_owner` equals the configured system identifier as visible to:
- any user in the `admin` Nextcloud group (in addition to the existing full RBAC bypass for admins), AND
- any user in any group listed in `openregister.systemReaderGroups` (comma-separated, default empty).

For other users, system-owned rows MUST remain subject to the usual RBAC rule evaluation. The organisation/multitenancy filter is NOT modified — system rows MUST carry an `_organisation` and tenant boundaries hold independently of this carve-out.

#### Scenario: Admin lists call_log and sees system-written rows
- **GIVEN** a `call_log` row exists with `_owner = '__system__'` and `_organisation = <default-org-uuid>`
- **AND** admin user has the default-org-uuid as their active organisation
- **WHEN** admin GETs `/api/objects/openregister/api/objects/openconnector/call_log`
- **THEN** the response `total` MUST include the system-written row
- **AND** the row MUST appear in `results[]`

#### Scenario: Non-admin without reader group does not see system rows from other authorization rules
- **GIVEN** user `bob` is not in `admin` and not in any group listed in `openregister.systemReaderGroups`
- **AND** the schema's `authorization.read` rule does NOT grant `bob` access by group
- **AND** a row exists with `_owner = '__system__'`
- **WHEN** `bob` lists that register/schema
- **THEN** the response MUST NOT include the system-owned row (only rows matching `_owner = 'bob'` or the schema's group rules, exactly as before)

#### Scenario: Configured reader-group member sees system rows
- **GIVEN** `openregister.systemReaderGroups = "log-readers"` and user `carol` is in `log-readers`
- **AND** a row exists with `_owner = '__system__'` in carol's active organisation
- **WHEN** `carol` lists the schema
- **THEN** the system row MUST be visible

#### Scenario: Cross-organisation isolation still holds
- **GIVEN** admin-of-org-A and a system-owned row exists in org-B
- **AND** admin bypass is disabled (SaaS mode) so admin cannot see other orgs
- **WHEN** admin-of-org-A lists the schema
- **THEN** the row MUST NOT appear (the organisation filter excludes it before RBAC evaluates)

### Requirement: The system identifier MUST be discoverable via a single service method
A dedicated service method MUST expose the system identifier so that both `SaveObject` and `MagicRbacHandler` read the same value. The method MUST read `openregister.systemUserId` via `IAppConfig`, falling back to the constant default `__system__` when the key is unset or empty. The companion method MUST return the configured reader groups as a normalised `string[]` (trimmed, no empty entries).

#### Scenario: Default identifier when unset
- **GIVEN** `openregister.systemUserId` is unset
- **WHEN** the service method is called
- **THEN** it MUST return `__system__`

#### Scenario: Reader-groups parse
- **GIVEN** `openregister.systemReaderGroups = " log-readers , audit-readers ,, "`
- **WHEN** the reader-groups method is called
- **THEN** it MUST return `['log-readers', 'audit-readers']` (trimmed, empties removed)

### Requirement: JWT verification algorithm is pinned server-side

When verifying a JWT presented for authorization, OpenRegister SHALL determine
the verification algorithm exclusively from the consumer's stored
`authorizationConfiguration`. It SHALL NOT fall back to the algorithm declared
in the attacker-supplied JWT header. If the consumer configuration does not pin
an algorithm, the token SHALL be rejected.

#### Scenario: Algorithm-confusion attack is rejected

- **WHEN** a consumer is configured for an asymmetric algorithm (RS/PS) with an
  RSA public key, and no explicit `algorithm` override
- **AND** an attacker submits a token with header `alg: HS256` signed using the
  public key as an HMAC secret
- **THEN** verification fails and the request is not authenticated

#### Scenario: Header algorithm must match the pinned class

- **WHEN** the pinned algorithm class is asymmetric (RS/PS)
- **AND** a presented token's header `alg` is an HMAC algorithm (or vice versa)
- **THEN** the token is rejected before signature verification

#### Scenario: Asymmetric tokens are verified asymmetrically

- **WHEN** a consumer is configured for RS256 with a valid RSA public key
- **AND** a correctly RS256-signed token is presented
- **THEN** the signature is verified with the public key via asymmetric
  verification (not HMAC) and authentication succeeds

### Requirement: Basic-auth header parsing is defensive

Parsing of an HTTP Basic authorization header SHALL guard against malformed
base64 input and SHALL preserve passwords that contain a colon.

#### Scenario: Malformed basic header fails cleanly

- **WHEN** a Basic auth header contains invalid base64
- **THEN** the request fails authentication without raising a runtime error

#### Scenario: Colon in password is preserved

- **WHEN** a Basic auth credential's password contains one or more `:` characters
- **THEN** the full password (after the first `:`) is used, not a truncated prefix

## Current Implementation Status
- **Fully implemented:**
  - `Consumer` entity (`lib/Db/Consumer.php`) with fields: uuid, name, description, domains (CORS), ips (IP allow-list), authorizationType (none/basic/bearer/apiKey/oauth2/jwt), authorizationConfiguration (JSON with keys, algorithms, secrets), userId (mapped Nextcloud user), created, updated
  - `ConsumerMapper` (`lib/Db/ConsumerMapper.php`) for CRUD operations on consumers
  - `ConsumersController` (`lib/Controller/ConsumersController.php`) for API consumer management
  - `AuthorizationService` (`lib/Service/AuthorizationService.php`) supporting JWT (HMAC HS256/384/512), Basic Auth, OAuth2 Bearer, and API key validation — all methods resolve to a Nextcloud user via `$this->userSession->setUser()`
  - `AuthenticationService` (`lib/Service/AuthenticationService.php`) for outbound token generation (OAuth2 client_credentials, OAuth2 password, JWT signing with HS/RS/PS algorithms)
  - `SecurityService` (`lib/Service/SecurityService.php`) with APCu-backed rate limiting (5 attempts / 15min window, 1hr lockout), progressive delays (2s-60s), IP and user lockout, XSS sanitization, security headers, and security event logging
  - `PermissionHandler` (`lib/Service/Object/PermissionHandler.php`) for schema-level RBAC with admin bypass, owner privileges, public group, conditional matching with `$organisation` variable
  - `PropertyRbacHandler` (`lib/Service/PropertyRbacHandler.php`) for property-level RBAC with `canReadProperty()`, `canUpdateProperty()`, `filterReadableProperties()`, `getUnauthorizedProperties()`, conditional rule matching, and admin/public/authenticated pseudo-groups
  - `MagicRbacHandler` (`lib/Db/MagicMapper/MagicRbacHandler.php`) for SQL-level RBAC filtering with QueryBuilder integration, raw SQL for UNION queries, dynamic variable resolution ($organisation, $userId, $now), operator conditions ($eq/$ne/$gt/$gte/$lt/$lte/$in/$nin/$exists), and multi-tenancy bypass detection
  - `MultiTenancyTrait` (`lib/Db/MultiTenancyTrait.php`) for organisation-level data isolation
  - `ConditionMatcher` (`lib/Service/ConditionMatcher.php`) and `OperatorEvaluator` (`lib/Service/OperatorEvaluator.php`) for conditional authorization rule evaluation
  - Nextcloud session auth works natively via the Nextcloud AppFramework
  - Public endpoint support via `@PublicPage` annotations on ObjectsController (5 public methods)
  - CORS enforcement in `AuthorizationService::corsAfterController()` with CSRF protection
  - Twig authentication extensions (`lib/Twig/AuthenticationExtension.php`, `lib/Twig/AuthenticationRuntime.php`) for `oauthToken` function in mapping templates
  - MCP endpoint uses Nextcloud's standard Basic Auth via the AppFramework controller pattern

- **Not implemented:**
  - Per-consumer rate limiting (configured request limits per consumer with `Retry-After` headers)
  - Authentication event auditing to Nextcloud's audit log (via `OCP\Log\ILogFactory`) — currently logged via `LoggerInterface` only
  - JWT token auto-generation and one-time display workflow in the consumer creation UI
  - Consumer revocation with immediate token invalidation (deleting a consumer works, but active JWT sessions may not be immediately invalidated if cached)
  - IP allow-list enforcement in `AuthorizationService` (Consumer stores `ips` field but enforcement is not implemented)
  - CORS enforcement per Consumer's `domains` field (currently uses generic origin reflection)
  - RSA/PS256 signature verification for inbound JWT tokens (only HMAC verification is implemented; `AuthorizationService::authorizeJwt()` checks HMAC_MAP only)

- **Partial:**
  - Rate limiting exists via `SecurityService` with APCu-backed counters, but is not integrated into the `AuthorizationService` flow for every authentication method
  - Public schema access exists via `@PublicPage` endpoints but mixed public/private schema discovery filtering is not explicitly implemented in schema listing endpoints
  - Group membership caching relies on Nextcloud's internal caching; no explicit per-request cache in OpenRegister handlers

### Requirement: OAuth2 token scopes MUST translate to RBAC verdicts
For external API consumers authenticating via OAuth2, the access token's `scope` claim MUST be translated into RBAC verdicts before the request reaches `PermissionHandler`. The token's scopes constrain the request to the intersection of (the Nextcloud-user's group-derived RBAC capability) AND (the scopes asserted on the token); a token with narrower scopes than the user's groups MUST NOT widen access, and an unknown scope MUST cause the request to be rejected with HTTP 401. The translation MUST be reversible: the OAS `security: [{ "oauth2": [groups] }, { "basicAuth": [] }]` block already emitted per operation by `OasService::applyRbacToOperation()` MUST be the authoritative scope catalog that token issuers and resource servers agree on.

#### Scenario: OAuth2 token with full scope set authorizes against the user's RBAC capability
- **GIVEN** a Nextcloud user `api-zaaksysteem` is in groups `[behandelaar, leesrechten]` and a Consumer is configured with `authorizationType: oauth2`
- **AND** the user has read access to schema `meldingen` via the `behandelaar` group
- **WHEN** an OAuth2 access token is presented with `scope: "behandelaar leesrechten"` against `GET /api/objects/zaken/meldingen`
- **THEN** the `AuthorizationService` MUST resolve the token to the Nextcloud user, set the user session, and proceed with the standard `PermissionHandler::hasPermission()` check
- **AND** the request MUST succeed with HTTP 200 and return the meldingen the user is authorized to read

#### Scenario: OAuth2 token with narrowed scope reduces access
- **GIVEN** the same user as above with groups `[behandelaar, leesrechten]`
- **WHEN** an OAuth2 token is presented with `scope: "leesrechten"` only (no `behandelaar`)
- **THEN** the request MUST be evaluated as if the user were ONLY in the `leesrechten` group, regardless of the user's broader Nextcloud group membership
- **AND** any RBAC rule that requires `behandelaar` MUST be denied with HTTP 403 even though the underlying user qualifies

#### Scenario: OAuth2 token with unknown scope is rejected
- **GIVEN** an OAuth2 token presents `scope: "behandelaar admin-everything"` where `admin-everything` is not in the OAS-derived scope catalog
- **THEN** the request MUST be rejected with HTTP 401
- **AND** the response body MUST NOT leak which scopes are valid (return a generic `invalid_scope` per RFC 6750 §3.1)

#### Scenario: Token-scope catalog matches the OAS security block
- **GIVEN** `OasService::createOas()` has emitted `components.securitySchemes.oauth2.flows.authorizationCode.scopes` for a deployment
- **WHEN** the auth-system bootstraps the token-scope translator
- **THEN** the translator's accepted scope vocabulary MUST equal the keys of that scopes map
- **AND** any deployment-specific scope added to OAS MUST automatically become acceptable to the translator without code changes

## Standards & References
- **OAuth 2.0 (RFC 6749)** — Authorization framework for Consumer entity auth types
- **JWT (RFC 7519)** — JSON Web Token for API consumer authentication
- **JWS (RFC 7515)** — JSON Web Signature for JWT signing/verification
- **SAML 2.0** — Via Nextcloud's `user_saml` app for enterprise SSO
- **OpenID Connect Core 1.0** — Via Nextcloud's `user_oidc` app for OIDC SSO
- **BIO (Baseline Informatiebeveiliging Overheid)** — Dutch government baseline information security requirements for authentication and access control
- **DigiD/eHerkenning** — Dutch government authentication standards (via SAML/OIDC gateway)
- **RFC 6585** — HTTP 429 Too Many Requests for rate limiting
- **OWASP Authentication Cheat Sheet** — Best practices for credential handling, session management, and brute force protection
- **Nextcloud AppFramework annotations** — `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired`, `@CORS`
- **Nextcloud OCP interfaces** — `IUserSession`, `IUserManager`, `IGroupManager`, `IAppConfig`, `ICacheFactory`, `ISecureRandom`
- **ZGW Autorisaties API (VNG)** — Dutch government authorization patterns (see cross-reference: `rbac-scopes` spec)

## Cross-References
- **`rbac-scopes`** — Maps Nextcloud groups to OAuth2 scopes in generated OAS; depends on the same group-based authorization model defined here. The OAuth2-token-scope-to-RBAC-verdict translation lives here in `auth-system`, not in `rbac-scopes` — `rbac-scopes` consumes the resolved Nextcloud user/groups; `auth-system` owns the token boundary.
- **`rbac-zaaktype`** — Implements schema-level RBAC per zaaktype/objecttype; uses `PermissionHandler` defined here
- **`row-field-level-security`** — Extends the authorization model with row-level and field-level security; uses `MagicRbacHandler` and `PropertyRbacHandler` defined here
- **ADR: Security and Authentication** — Architecture decision record for the security model (not yet created; to be defined at `openspec/architecture/adr-007-security-and-auth.md`)

## Specificity Assessment
- **Highly specific and largely implemented**: The core multi-auth system, RBAC hierarchy (admin > owner > group > authenticated > public), and three-level authorization (schema, property, row) are fully implemented with clear code references.
- **Well-documented Consumer entity**: The Consumer entity fields, auth types, and resolution flow are clearly specified with implementation details.
- **Code-grounded scenarios**: All scenarios reference specific methods, classes, and behaviors verified against the actual implementation.
- **Missing implementations clearly identified**: IP allow-list enforcement, per-consumer rate limiting, RSA JWT verification, and audit log integration are explicitly marked as not implemented.
- **No open design questions**: The architecture is settled — all auth methods resolve to Nextcloud users, all RBAC uses Nextcloud groups, all layers are composable.
