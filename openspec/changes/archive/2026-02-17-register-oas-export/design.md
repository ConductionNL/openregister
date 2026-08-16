# Design: register-oas-export

## Architecture Overview

The OAS export is already functional via `OasService::createOas()` → `OasController`. This change modifies `OasService` to:
1. Produce strictly valid OpenAPI 3.1.0 output (Redocly-passing)
2. Read RBAC configuration from schema properties and map Nextcloud groups to OAuth2 scopes
3. Apply per-operation `security` requirements based on which groups can perform each CRUD action

No new controllers, mappers, or database tables are needed. The change is confined to `OasService` and its base template `BaseOas.json`.

```
┌──────────────┐     ┌────────────┐     ┌──────────────────┐
│ OasController│────▶│ OasService │────▶│ RegisterMapper   │
│  (unchanged) │     │ (modified) │     │ SchemaMapper     │
└──────────────┘     └─────┬──────┘     └──────────────────┘
                           │
                     ┌─────▼──────┐
                     │ BaseOas.json│
                     │ (modified)  │
                     └────────────┘
```

## API Design

No new endpoints. The existing endpoints remain unchanged:

### `GET /api/registers/{id}/oas`
**Response:** OpenAPI 3.1.0 JSON — now with dynamic scopes and per-operation security.

Example of what changes in the output:

```json
{
  "components": {
    "securitySchemes": {
      "basicAuth": { "type": "http", "scheme": "basic" },
      "oauth2": {
        "type": "oauth2",
        "flows": {
          "authorizationCode": {
            "authorizationUrl": "/apps/oauth2/authorize",
            "tokenUrl": "/apps/oauth2/api/v1/token",
            "scopes": {
              "admin": "Full administrative access",
              "redacteuren": "Access for redacteuren group",
              "public": "Public (unauthenticated) access"
            }
          }
        }
      }
    }
  },
  "paths": {
    "/softwarecatalogus/module": {
      "get": {
        "security": [
          { "oauth2": ["public"] },
          { "basicAuth": [] }
        ]
      },
      "post": {
        "security": [
          { "oauth2": ["redacteuren", "admin"] },
          { "basicAuth": [] }
        ]
      }
    }
  }
}
```

### `GET /api/registers/oas`
Same as above but aggregates scopes across all registers.

## Database Changes

None. RBAC configuration is already stored in schema property definitions (JSON column). No migrations needed.

## Nextcloud Integration

- **Controllers:** `OasController` — no changes
- **Services:** `OasService` — modified to extract RBAC groups and generate scopes
- **Mappers/Entities:** `RegisterMapper`, `SchemaMapper` — read-only, no changes. `Schema::getPropertiesWithAuthorization()` and `Schema::hasPropertyAuthorization()` already exist.
- **Events/Hooks:** None

The existing `PropertyRbacHandler` is NOT injected into `OasService`. Instead, `OasService` reads the raw authorization config from schema properties directly — it doesn't need runtime user context, just the static RBAC rules.

## File Structure

```
lib/
  Service/
    OasService.php              ← Modified: scope extraction, per-operation security, validation fixes
    Resources/
      BaseOas.json              ← Modified: clean up base template, remove hardcoded read/write scopes
```

## Security Considerations

- **OAS endpoints remain public** (`@PublicPage`): The OAS file documents what groups _can_ do, it doesn't grant access. This is standard practice — API specs are meant to be publicly discoverable.
- **No runtime RBAC changes**: The actual API still enforces permissions via `PropertyRbacHandler` at request time. The OAS just _documents_ these permissions.
- **Group names are exposed in OAS scopes**: Nextcloud group names will be visible in the OAS output. This is intentional — API consumers need to know which group to request. If group names are sensitive, the register owner should be aware that publishing OAS exposes them.

## NL Design System

Not applicable — this change is backend-only (JSON output).

## Decisions

### 1. Read RBAC from schema properties directly vs. injecting PropertyRbacHandler

**Decision:** Read raw `authorization` config from `Schema::getProperties()` directly.

**Why:** `PropertyRbacHandler` is designed for runtime user-context checks (does _this user_ have access?). For OAS generation we need the _static_ rules (which groups are configured?), not a user-specific evaluation. Reading the raw config is simpler and doesn't require user session context.

**Alternative:** Inject `PropertyRbacHandler` and add a `getConfiguredGroups()` method. Rejected because it couples OAS generation to user session lifecycle.

### 2. Map groups to OAuth2 scopes vs. custom `x-` extensions

**Decision:** Use standard OAuth2 scopes to represent Nextcloud groups.

**Why:** OAuth2 scopes are the standard OAS mechanism for representing "who can do what." Redocly and Swagger UI natively render them. Using `x-openregister-groups` would require custom tooling that doesn't exist.

**Alternative:** `x-rbac` extension fields. Rejected because no tooling supports them and the OAuth2 scope model maps cleanly to group-based access.

### 3. Schema-level vs. property-level RBAC in OAS

**Decision:** Only schema-level (operation-level) RBAC for now. Property-level RBAC is out of scope.

**Why:** OpenAPI 3.1 doesn't have a standard way to express "field X is only visible to group Y." This would require `x-` extensions or complex `oneOf` schema variations per group, which defeats the purpose of clean documentation.

### 4. Scope extraction strategy

**Decision:** Collect all unique groups from all properties' `authorization.read` and `authorization.update` rules across the schemas in a register, then:
- Groups appearing in `read` rules → scopes on GET operations
- Groups appearing in `update` rules → scopes on POST/PUT/DELETE operations
- `admin` group always gets all scopes (admins can do everything)
- If a schema has no RBAC rules, operations get global-level security (current behavior)

## Trade-offs

| Decision | Pro | Con |
|----------|-----|-----|
| Static RBAC extraction | No user context needed, simple | Won't reflect conditional rules (e.g., org-based matches) |
| OAuth2 scopes for groups | Standard, great tooling support | Not a real OAuth2 flow in Nextcloud unless OAuth2 app is configured |
| Public OAS endpoints | API discoverability | Group names visible to anyone |
| Schema-level only | Clean OAS output | Doesn't show property-level restrictions |

## Risks

- **[Conditional RBAC rules lost]** → Mitigation: Document that OAS shows _configured groups_ but conditional rules (e.g., `match: { _organisation: $organisation }`) are not representable in OAS. The OAS represents the "maximum possible access" per group.
- **[Redocly version compatibility]** → Mitigation: Test with Redocly CLI latest. OpenAPI 3.1.0 is well-supported since Redocly 1.0+.
- **[Schemas without RBAC]** → Mitigation: Fall back to global-level security definition (basicAuth + oauth2 with generic scopes). Only schemas with explicit RBAC rules get per-operation security.

## Open Questions

- Should the OAS download endpoint also support YAML format (`Accept: application/yaml`), or is JSON sufficient for Redocly?
