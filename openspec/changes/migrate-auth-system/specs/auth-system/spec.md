# Spec: Authentication System Migration

## ZGW Standard References (Authentication Context)

The auth system must support ZGW-style JWT authentication as defined in the ZGW API standards:

### JWT Authentication Standard
- **Developer guide (authentication)**: https://vng-realisatie.github.io/gemma-zaken/ontwikkelaars/
- **Autorisaties API OAS**: [api-specificatie/ac/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/ac/openapi.yaml)
  - Raw: https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/ac/openapi.yaml

### ZGW API Standards (all components use this auth)
- **Zaken API OAS**: [api-specificatie/zrc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/zrc/current_version/openapi.yaml)
- **Catalogi API OAS**: [api-specificatie/ztc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/ztc/current_version/openapi.yaml)
- **Besluiten API OAS**: [api-specificatie/brc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/brc/current_version/openapi.yaml)
- **Documenten API OAS**: [api-specificatie/drc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/drc/current_version/openapi.yaml)
- **Notificaties API OAS**: [api-specificatie/nrc/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/nrc/openapi.yaml)

### Standard Documentation (Markdown sources)
- **gemma-zaken repo**: https://github.com/VNG-Realisatie/gemma-zaken
- **Standard index**: [docs/standaard/index.md](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/docs/standaard/index.md)
- **Developer index**: [docs/standaard/versions.md](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/docs/standaard/versions.md)

## Requirements

### Requirement: Supported Authentication Types
The system MUST support:
| Type | Use Case |
|------|----------|
| `jwt` | Standard JWT validation (any issuer) |
| `jwt-zgw` | ZGW-style JWT with `iss`, `iat`, `client_id` claims |
| `basic` | HTTP Basic Auth against Nextcloud users |
| `oauth2` | OAuth2 Bearer token validation |
| `apiKey` | API key in header |
| `none` | No authentication required |

### Requirement: JWT Algorithm Support
MUST support all algorithms from OpenConnector:
- HMAC: HS256, HS384, HS512
- RSA: RS256, RS384, RS512
- PSS: PS256, PS384, PS512

### Requirement: Consumer Entity
Consumer records store API client credentials:
- `name` — Used as JWT issuer identifier
- `authorizationType` — One of the supported types
- `authorizationConfiguration` — JSON with type-specific config (public key, algorithm, API keys)
- `userId` — Nextcloud user to impersonate when authenticated
- `domains` — CORS allowed origins
- `ips` — IP allowlist

### Requirement: Token Generation (Outbound)
For outbound API calls (e.g., mapping service fetching from external sources):
- OAuth2 client_credentials grant
- OAuth2 password grant
- JWT signing with configurable payload templates (Twig)
- Support for client assertions (jwt-bearer)

### Requirement: CORS Support
`corsAfterController()` MUST set appropriate CORS headers based on the Consumer's `domains` configuration.

### Requirement: Stateless Validation
Auth validation MUST NOT require database lookups per-request beyond the initial Consumer lookup by issuer name. JWT signature verification uses the Consumer's stored key.
