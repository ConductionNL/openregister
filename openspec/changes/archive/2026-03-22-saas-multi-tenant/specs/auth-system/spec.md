# Auth System (Delta for SaaS Multi-Tenant)

## MODIFIED Requirements

### Requirement: The system MUST support multiple authentication methods with unified identity resolution
OpenRegister MUST accept authentication via Nextcloud session cookies, HTTP Basic Auth, Bearer JWT tokens, OAuth2 bearer tokens, and API keys. All methods MUST resolve to a Nextcloud user identity (via `OCP\IUserSession::setUser()`) before any RBAC evaluation occurs, ensuring that authorization decisions are independent of the authentication method used. **Additionally, after identity resolution, the system MUST validate that the resolved user belongs to an active Organisation before proceeding with RBAC evaluation. If the user has no active Organisation or the active Organisation is not in `active` status, the request MUST be rejected.**

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

#### Scenario: Authenticated user without active organisation is rejected
- **GIVEN** a user is authenticated via any method (session, Basic Auth, JWT, API key)
- **WHEN** the resolved user has no active Organisation or the active Organisation has `status` != `active`
- **THEN** the system MUST return HTTP 403 Forbidden with `{"error": "No active organisation. Contact your administrator."}`
- **AND** this check MUST occur after authentication but before any RBAC evaluation
- **AND** public endpoints (those not requiring authentication) MUST be exempt from this check
