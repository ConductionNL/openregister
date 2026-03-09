# Proposal: migrate-auth-system

## Summary
Migrate the complete authentication and authorization system from OpenConnector into OpenRegister. OpenConnector is being deprecated, and OpenRegister — as the foundation repo for all Conduction apps — should own API authentication. This includes JWT (including ZGW-style), Basic Auth, OAuth 2.0, and API Key validation, plus the Consumer entity for managing API clients.

## Motivation
OpenConnector currently owns all API authentication infrastructure (AuthorizationService, AuthenticationService, Consumer entity, Rule-based auth). Since OpenConnector is being deprecated, this code must move to OpenRegister so that all apps (Procest, OpenCatalogi, Softwarecatalog, etc.) can authenticate API requests — especially for ZGW API compliance which requires JWT-ZGW token validation.

Without this migration, no app can validate incoming ZGW API calls once OpenConnector is removed.

## Affected Projects
- [x] Project: `openregister` — Receives all auth code, new entities, migrations, controllers
- [ ] Reference: `procest` — Will use OpenRegister's auth for ZGW API endpoints
- [ ] Reference: `opencatalogi` — Will use OpenRegister's auth for public API endpoints

## Scope
### In Scope
- **Consumer entity + mapper**: API client registration (name, domains, IPs, auth type, credentials)
- **AuthorizationService**: Validate incoming requests (JWT, JWT-ZGW, Basic, OAuth2, API Key)
- **AuthenticationService**: Generate outgoing tokens (OAuth2 client credentials, JWT signing)
- **AuthenticationException**: Structured error responses for auth failures
- **Twig auth extensions**: `oauthToken()`, `jwtToken()` functions for mapping templates
- **Consumer management API**: CRUD endpoints for `/api/consumers`
- **Database migration**: Create `openregister_consumers` table
- **Composer dependency**: Add `web-token/jwt-framework` ^3

### Out of Scope
- Rule entity migration (Rules are an OpenConnector orchestration concept, not pure auth)
- EndpointService migration (OpenConnector-specific routing)
- Removing auth code from OpenConnector (separate deprecation change)
- UI for consumer management (API-only for now)

## Approach
1. Create `Consumer` entity and `ConsumerMapper` in OpenRegister's `Db/` namespace
2. Create database migration for `openregister_consumers` table
3. Port `AuthorizationService` — adapt to use OpenRegister's ConsumerMapper instead of OpenConnector's
4. Port `AuthenticationService` — for outgoing token generation (used by mapping service)
5. Port `AuthenticationException`
6. Port Twig `AuthenticationExtension` + `AuthenticationRuntime` (adapted for OpenRegister Sources)
7. Create `ConsumersController` with standard CRUD
8. Add `web-token/jwt-framework` to composer.json
9. Wire auth service into OpenRegister's existing API controllers via middleware/annotation

## Cross-Project Dependencies
- **Procest ZGW APIs** depend on this for JWT-ZGW validation (zgw-autorisaties-api change)
- **OpenCatalogi** public endpoints will migrate to use this auth system

## Rollback Strategy
Remove the Consumer entity, migration, auth services, and controller. Remove `web-token/jwt-framework` from composer.json. No existing OpenRegister functionality is modified.

## Source Code Inventory (from OpenConnector)
| File | Lines | Key Content |
|------|-------|-------------|
| `AuthorizationService.php` | 338 | JWT/Basic/OAuth/API Key validation |
| `AuthenticationService.php` | 373 | OAuth2 token fetch, JWT generation |
| `Consumer.php` | 140 | API client entity |
| `ConsumerMapper.php` | 149 | DB operations for consumers |
| `AuthenticationException.php` | 33 | Structured auth errors |
| `AuthenticationExtension.php` | 20 | Twig functions |
| `AuthenticationRuntime.php` | 77 | Twig runtime for token fetch |
| `ConsumersController.php` | ~100 | CRUD API |
| **Total** | **~1,230** | |
