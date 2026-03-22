---
status: draft
---
# Authentication and Authorization System

## Purpose
Define the authentication and authorization system for OpenRegister, supporting Nextcloud session auth, Basic Auth for API consumers, JWT bearer tokens for external systems, API key auth for MCP and service-to-service integration, and SSO integration via SAML/OIDC. The auth system MUST map all external identities to Nextcloud users via the Consumer entity and enforce consistent RBAC across every access method (REST, GraphQL, MCP, public endpoints), ensuring that a single identity model drives schema-level, property-level, and row-level security decisions.

**Source**: Core OpenRegister capability; 67% of tenders require SSO/identity integration; 86% require RBAC per zaaktype.

## ADDED Requirements

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
- **`rbac-scopes`** — Maps Nextcloud groups to OAuth2 scopes in generated OAS; depends on the same group-based authorization model defined here
- **`rbac-zaaktype`** — Implements schema-level RBAC per zaaktype/objecttype; uses `PermissionHandler` defined here
- **`row-field-level-security`** — Extends the authorization model with row-level and field-level security; uses `MagicRbacHandler` and `PropertyRbacHandler` defined here
- **ADR: Security and Authentication** — Architecture decision record for the security model (not yet created; to be defined at `openspec/architecture/adr-007-security-and-auth.md`)

## Specificity Assessment
- **Highly specific and largely implemented**: The core multi-auth system, RBAC hierarchy (admin > owner > group > authenticated > public), and three-level authorization (schema, property, row) are fully implemented with clear code references.
- **Well-documented Consumer entity**: The Consumer entity fields, auth types, and resolution flow are clearly specified with implementation details.
- **Code-grounded scenarios**: All scenarios reference specific methods, classes, and behaviors verified against the actual implementation.
- **Missing implementations clearly identified**: IP allow-list enforcement, per-consumer rate limiting, RSA JWT verification, and audit log integration are explicitly marked as not implemented.
- **No open design questions**: The architecture is settled — all auth methods resolve to Nextcloud users, all RBAC uses Nextcloud groups, all layers are composable.
