# Design: migrate-auth-system

## Architecture Overview

Move OpenConnector's auth layer into OpenRegister as a self-contained authentication module. The module validates incoming API requests and generates outgoing tokens for source connections.

```
External Client              OpenRegister
  |                              |
  |-- Authorization: Bearer JWT -|-> AuthorizationService::authorizeJwt()
  |                              |     |-> ConsumerMapper::findByName(issuer)
  |                              |     |-> JWSVerifier (web-token/jwt-framework)
  |                              |     |-> IUserSession::setUser()
  |                              |
  |<-- 200 + data --------------|
```

## Database Schema

### `openregister_consumers` table

| Column | Type | Description |
|--------|------|-------------|
| id | integer (PK, auto) | Internal ID |
| uuid | string(36) | Public UUID |
| name | string(255) | Consumer name / JWT issuer |
| description | text | Description |
| domains | text (JSON) | Allowed CORS domains |
| ips | text (JSON) | Allowed IP addresses |
| authorization_type | string(50) | 'none', 'basic', 'bearer', 'apiKey', 'oauth2', 'jwt' |
| authorization_configuration | text (JSON) | Public key, algorithm, API keys, etc. |
| user_id | string(64) | Nextcloud user to impersonate |
| created | datetime | Creation timestamp |
| updated | datetime | Last update timestamp |

## File Structure

```
openregister/lib/
  Controller/
    ConsumersController.php        # CRUD API for consumers
  Db/
    Consumer.php                   # Entity
    ConsumerMapper.php             # DB mapper
  Exception/
    AuthenticationException.php    # Structured error
  Service/
    AuthorizationService.php       # Validate incoming requests
    AuthenticationService.php      # Generate outgoing tokens
  Twig/
    AuthenticationExtension.php    # Twig functions
    AuthenticationRuntime.php      # Twig runtime
  Migration/
    VersionXDateYYYYMMDD.php       # Create consumers table
```

## API Design

### Consumer Management

```
GET    /api/consumers           # List all consumers
POST   /api/consumers           # Create consumer
GET    /api/consumers/{id}      # Get consumer
PUT    /api/consumers/{id}      # Update consumer
DELETE /api/consumers/{id}      # Delete consumer
```

All consumer endpoints require admin authentication.

## Integration Points

### How other apps use auth

Apps like Procest don't call AuthorizationService directly. Instead:

1. **ZGW endpoints** receive a JWT in the `Authorization` header
2. The ZGW controller calls OpenRegister's `AuthorizationService::authorizeJwt()`
3. AuthorizationService finds the Consumer by JWT issuer (`iss` claim)
4. Validates signature using the consumer's configured public key/secret
5. Sets the Nextcloud user session to the consumer's `userId`
6. The request proceeds with that user's permissions

### Composer dependency

Add to `openregister/composer.json`:
```json
"web-token/jwt-framework": "^3"
```

## Migration Notes

### Changes from OpenConnector version
- Namespace: `OCA\OpenConnector\Service` -> `OCA\OpenRegister\Service`
- Table prefix: `openconnector_consumers` -> `openregister_consumers`
- Remove Rule-based auth dispatch (OpenConnector-specific) — apps call auth services directly
- Remove EndpointService coupling — auth is a standalone service
- Keep all JWT algorithms (HS256/384/512, RS256/384/512, PS256/384/512)
