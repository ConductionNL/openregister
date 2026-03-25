# Access Control (RBAC)

## Overview

OpenRegister implements a four-level Role-Based Access Control (RBAC) hierarchy: register, schema, row (object), and property. This maps to the OAuth2 scope model generated in the OpenAPI Specification, enabling external API consumers, ZGW-compliant systems, and MCP clients to discover and negotiate access programmatically.

**Tender demand**: 67% of tenders require SSO/identity integration; 86% require RBAC per zaaktype.

## Authorization Hierarchy

```
Register
  └── Schema (zaaktype)
        └── Object (row-level, conditional matching)
              └── Property (field-level)
```

Each level is independently configurable. Register-level authorization cascades to schemas that do not define their own `authorization` block.

## Schema-Level Authorization (CRUD Scopes)

Authorization is defined per schema as a JSON block:

```json
{
  "authorization": {
    "read":   ["juridisch-team", "medewerkers"],
    "create": ["juridisch-team"],
    "update": ["juridisch-team"],
    "delete": ["admin"]
  }
}
```

Groups map directly to Nextcloud user groups. A user who belongs to a listed group has the corresponding permission on all objects in the schema.

An `admin` group bypass is always available for system administrators.

## Row-Level Security (Object-Level Conditions)

Row-level conditions filter which objects a user can see at the database layer, injected as SQL WHERE clauses via `MagicRbacHandler`:

```json
{
  "authorization": {
    "read": [
      {
        "group": "behandelaars",
        "conditions": [
          { "field": "behandelaar", "operator": "=", "value": "$userId" },
          { "field": "status", "operator": "!=", "value": "gesloten" }
        ]
      }
    ]
  }
}
```

### Dynamic Variables in Conditions

| Variable | Resolves to |
|----------|-------------|
| `$userId` | The authenticated user's Nextcloud user ID |
| `$organisation` | The active organisation UUID for multi-tenant deployments |
| `$now` | Current UTC datetime (ISO 8601) |

### Supported Operators

`=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `nin`, `like`, `exists`

## Property-Level Security

Individual schema properties can be restricted per group:

```json
{
  "properties": {
    "bsn": {
      "type": "string",
      "authorization": {
        "read": ["privacybeambten", "admin"],
        "update": ["admin"]
      }
    }
  }
}
```

`PropertyRbacHandler.filterReadableProperties()` strips unauthorized fields from every API response. `PropertyRbacHandler.getUnauthorizedProperties()` is used during updates to prevent unauthorized field writes.

## OAuth2 Scopes in OpenAPI Specification

When an OpenAPI spec is generated for a register, `OasService` extracts all groups from all authorization blocks and maps them to OAuth2 scopes:

```yaml
components:
  securitySchemes:
    oauth2:
      type: oauth2
      flows:
        authorizationCode:
          scopes:
            juridisch-team: "Access to bezwaarschriften schema"
            admin: "Administrative access"
```

Each endpoint's `security` block lists the groups required for that operation, allowing external OAuth2 clients to request exactly the scopes they need.

## Register-Level Cascade

A register can define default authorization that applies to all schemas without their own `authorization` block:

```json
{
  "authorization": {
    "read": ["medewerkers"],
    "create": ["medewerkers"],
    "delete": ["admin"]
  }
}
```

Schemas in this register inherit these permissions unless they override them.

## Named Roles

Registers can define named roles that expand to groups in authorization blocks, reducing repetition:

```json
{
  "roles": {
    "behandelaar": ["juridisch-team", "klantcontact"],
    "manager": ["teamleiders", "directie"]
  },
  "authorization": {
    "read": ["$behandelaar"],
    "delete": ["$manager"]
  }
}
```

## Authentication Methods

All authentication methods resolve to a Nextcloud user via `AuthorizationService`:

| Method | Description |
|--------|-------------|
| Session auth | Standard Nextcloud web session |
| Basic auth | HTTP Basic (dev/test only) |
| Bearer token | Nextcloud app token or API key |
| Consumer entity | API consumer with `userId` mapping — enables machine-to-machine access |
| OAuth2 | Third-party OAuth2 client via Nextcloud OAuth2 |

## Consumer Identity

The `Consumer` entity maps an external API identity (API key, OAuth2 client) to a Nextcloud user ID. This allows service-to-service integrations (n8n workflows, external applications) to act on behalf of a specific user, inheriting that user's RBAC permissions.

## Caching

- `MagicRbacHandler.$cachedActiveOrg` — caches organisation lookup per request
- `ConditionMatcher.$cachedActiveOrg` — caches organisation for condition evaluation
- `OasService.$schemaRbacMap` — caches the group-to-scope map during OAS generation

## ZGW Autorisaties API Compliance

The RBAC system maps to ZGW Autorisaties API requirements:

- Per-zaaktype (schema) authorization with per-operation permissions
- `vertrouwelijkheidaanduiding` (confidentiality level) enforcement via conditional row-level rules
- Cross-zaaktype coordinator access via named roles
- Per-user delegation via `conditions: [{ "field": "behandelaar", "value": "$userId" }]`

## Standards

| Standard | Role |
|----------|------|
| OAuth2 | Scope model in OAS security definitions |
| ZGW Autorisaties API | Zaaktype-scoped authorization compliance |
| BIO (Baseline Informatiebeveiliging Overheid) | Audit logging of access decisions |
| NL API Design Rules | Bearer token authentication conventions |

## Related Features

- [Registers & Schemas](registers-and-schemas.md) — authorization blocks live on schema definitions
- [OpenAPI & GraphQL APIs](api-generation.md) — RBAC scopes appear in generated OAS
- [Content Versioning & Audit Trail](versioning-and-audit.md) — access decisions logged in audit trail
- [Multi-Tenancy & SaaS](multi-tenancy.md) — organisation scoping in RBAC conditions
