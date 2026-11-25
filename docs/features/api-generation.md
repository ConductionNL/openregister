# OpenAPI & GraphQL APIs

## Overview

OpenRegister auto-generates complete OpenAPI 3.1.0 specifications and a GraphQL API from register and schema definitions at runtime. This means API documentation is always in sync with the live data model — no manual maintenance, no drift. Both APIs reuse the same underlying services for RBAC enforcement, relation resolution, and search.

## OpenAPI Generation

### Auto-Generated Specification

For each register, OpenRegister generates a complete OpenAPI 3.1.0 specification covering:

- All CRUD endpoints for every schema in the register
- Query parameters for search (`_search`), filtering, sorting, pagination, and facets
- Request and response schemas derived from JSON Schema property definitions
- Authentication schemes (session, bearer token, OAuth2)
- RBAC scopes per operation (group-based, from schema authorization blocks)
- Example payloads using `x-openapi-examples`
- NL API Design Rules compliance markers

### Download Formats

```
GET /api/registers/{id}/oas            OpenAPI JSON (default)
GET /api/registers/{id}/oas?format=yaml    OpenAPI YAML
GET /api/registers/{id}/oas/ui         Swagger UI (interactive browser)
```

A combined spec covering all registers is available at:

```
GET /api/oas                           Combined OpenAPI spec for all registers
```

### JSON Schema to OpenAPI Type Mapping

| JSON Schema | OpenAPI |
|-------------|---------|
| `type: string` | `type: string` |
| `type: string, format: date` | `type: string, format: date` |
| `type: string, format: date-time` | `type: string, format: date-time` |
| `type: string, format: uuid` | `type: string, format: uuid` |
| `type: integer` | `type: integer` |
| `type: number` | `type: number, format: float` |
| `type: boolean` | `type: boolean` |
| `type: array` | `type: array, items: ...` |
| `type: object` | `$ref: #/components/schemas/...` |
| `enum: [...]` | `type: string, enum: [...]` |

### RBAC Scopes in OAS

Authorization blocks from schema definitions are extracted into `components.securitySchemes`:

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
paths:
  /api/objects/register/bezwaarschriften:
    get:
      security:
        - oauth2: [juridisch-team]
    delete:
      security:
        - oauth2: [admin]
```

### Auto-Regeneration

The spec regenerates automatically when:

- A schema property is added, changed, or removed
- Schema authorization blocks are updated
- A new schema is added to a register

A background job keeps a cached spec version to avoid regeneration on every request.

### OAS Validation

Generated specs are validated against the OpenAPI 3.1.0 schema before serving. Validation failures are logged and the last valid spec is returned. Schema authors are notified of validation errors via Nextcloud notifications.

## GraphQL API

### Auto-Generated Schema

The GraphQL schema is derived dynamically from register schema definitions at runtime. `SchemaGenerator.generate()` iterates all registers and schemas and builds corresponding GraphQL types.

### Type Generation

For each schema, `SchemaGenerator` creates:

- A GraphQL `ObjectType` (PascalCase name, singularized from schema slug)
- Query fields: `{schema}List(filter, sort, pagination)` and `{schema}(id)` for single-object lookup
- Mutation fields: `create{Schema}`, `update{Schema}`, `delete{Schema}`
- Subscription fields: `{schema}Events` for real-time updates via SSE

```graphql
type Meldingen {
  _uuid: UUID!
  _created: DateTime
  _updated: DateTime
  _owner: String
  titel: String
  status: String
  prioriteit: Int
}

type Query {
  meldingenList(filter: MeldingenFilter, sort: MeldingenSort, first: Int, after: String): MeldingenConnection
  melding(id: UUID!): Meldingen
}

type Mutation {
  createMelding(input: MeldingenInput!): Meldingen
  updateMelding(id: UUID!, input: MeldingenInput!): Meldingen
  deleteMelding(id: UUID!): Boolean
}

type Subscription {
  meldingenEvents(filter: MeldingenFilter): MeldingenEvent
}
```

### Nested Relation Resolution

Properties with `$ref` to other schemas generate nested GraphQL types. `RelationHandler` resolves referenced objects with DataLoader batching to avoid N+1 queries:

```graphql
query {
  meldingenList(filter: { status: "nieuw" }) {
    nodes {
      _uuid
      titel
      behandelaar {  # resolved from UUID reference
        naam
        email
      }
    }
  }
}
```

### RBAC in GraphQL

GraphQL resolvers reuse the same authorization stack as the REST API:

- `PermissionHandler` for schema-level RBAC
- `PropertyRbacHandler` for field-level security (unauthorized fields return `null`)
- `MagicRbacHandler` for row-level SQL conditions

### Query Complexity Analysis

`QueryComplexityAnalyzer` prevents abuse by rejecting queries that exceed a configurable complexity score. Deeply nested queries with large pagination are rejected with HTTP 400 before execution.

### GraphQL Endpoint

```
POST /api/graphql           Execute a GraphQL query, mutation, or subscription
GET  /api/graphql           GraphQL playground (introspection UI)
GET  /api/graphql/schema    Download the GraphQL SDL schema
```

### Subscriptions via SSE

GraphQL subscriptions are delivered over Server-Sent Events:

```
GET /api/graphql/subscriptions?query=subscription{meldingenEvents{_uuid,titel,status}}
```

The `SubscriptionService` maintains an in-memory event buffer per subscription. The `GraphQLSubscriptionListener` bridges Nextcloud PHP events into the SSE buffer.

## NL API Design Rules

All generated REST endpoints comply with the NL API Design Rules:

- Resource-based URL structure (`/api/objects/{register}/{schema}`)
- HTTP methods follow REST semantics (GET, POST, PUT, PATCH, DELETE)
- Pagination via `_start`/`_limit` and `Link` headers
- Versioning via URL path (`/api/v1/...`) or `API-Version` header (see below)
- Error responses follow Problem Details (RFC 7807)
- Dutch field names supported alongside English aliases

### Versioned paths

Every API path is served twice: under `/api/v1/` and at its unversioned path.

- `/index.php/apps/openregister/api/v1/<path>` is the current form. The root `openapi.json` describes it.
- `/index.php/apps/openregister/api/<path>` keeps working and answers the same, but it is deprecated. Its responses carry `Deprecation: @1794441600` (RFC 9745, 12 November 2026) and `Link: <.../api/v1/<path>>; rel="successor-version"`. No sunset date is set yet.
- `filesMultipart` is the deprecated name of `files-multipart`. Its successor is `/api/v1/objects/{register}/{schema}/{id}/files-multipart`. It still answers, but `openapi.json` does not list it, because its spelling breaks the kebab-case path rule.
- Paths that already carry a version (`/api/v2/audit`, `/api/mcp/v1/...`) and paths that speak another standard (MCP, GraphQL, WFS, iCalendar feeds, metrics, health, federation) get no alias and are not deprecated.

`ApiPathAliases` in `lib/Middleware/Support/` adds the aliases to `appinfo/routes.php`, so a new route gets its `/api/v1/` alias automatically.

### Conformance report (ruleset 2.2.1)

Open Register checks its generated documents against the NLGov REST API Design Rules, ruleset **2.2.1**. Every rule of the ruleset gets one result:

| Result | Meaning |
|---|---|
| `pass` | A check ran and found nothing. |
| `fail` | A check ran and found a violation; the findings name the path. |
| `deviation` | Open Register breaks the rule on purpose; the reason is given. |
| `manual` | A functional guideline or module that no machine can decide. |
| `not-probed` | A rule only a live response can show, before anyone probed. |

**The marker.** Every generated document carries `x-nl-api-design-rules` with `version` and the `pass`, `fail`, `deviation` and `manual` lists of its document and functional rules. Response rules are not in the marker, so probing never changes the document's ETag.

**The report.** `GET /api/registers/{id}/oas/conformance` reports one register, `GET /api/registers/oas/conformance` the combined document. Both are public, like the document they are read from, and rate-limited to 30 anonymous calls a minute. The body has `ruleset`, `generatedAt`, `probedAt`, one entry per rule (`id`, `title`, `kind`, `result`, `findings`, and `deviation` where one is declared) and `counts` per result.

**The probe.** `POST /api/registers/{id}/oas/conformance/probe` is for administrators only. It reads two of the register's own responses (a collection and a record that cannot exist), at most five requests of at most 10 seconds each, without following redirects, and checks:

- `/core/version-header`: `API-Version` is a full `major.minor.patch` version. Today it is the negotiated version id, which is digits only, so this rule fails until the API versioning work changes that header.
- `/core/transport/security-headers`: `Cache-Control`, `Content-Security-Policy`, `Strict-Transport-Security`, `X-Content-Type-Options: nosniff` and `X-Frame-Options` are present.
- `/core/date-time/timezone`: every date-time value in the body carries an offset.
- `/core/error-handling/problem-details`: the error is served as `application/problem+json`.

The caller's `Authorization` header is passed on, so the probe reads no more than the caller may. The last result is kept per register and read by the report.

**The occ command.** `occ openregister:api:conformance <register> [--probe] [--output=json]` prints the same report, one line per rule, for CI and operators.

**Declared deviations.** The rule catalogue (`lib/Service/Oas/NlGovRuleCatalogue.php`) is the only place a deviation is declared; there is no setting to add one.

- `/core/uri-version`: the URL pattern `/index.php/apps/{app}/api/{resource}` is fixed for every app (hydra ADR-002); the version is negotiated by the `API-Version` header instead.
- `/core/query-keys-camel-case`: the underscore prefix marks a reserved query key (`_limit`, `_page`, `_extend`, ...) and every client sends them; renaming them breaks the contract.

## Standards

| Standard | Role |
|----------|------|
| OpenAPI 3.1.0 | REST API specification format |
| GraphQL (June 2018) | Query language and execution engine |
| NL API Design Rules | Dutch government API conventions |
| OAuth2 | Scope model in OAS security definitions |
| RFC 7807 | Problem Details for HTTP APIs (error format) |

## Related Features

- [Registers & Schemas](registers-and-schemas.md) — schema definitions drive both OpenAPI and GraphQL generation
- [Access Control (RBAC)](access-control.md) — RBAC groups appear as OAuth2 scopes
- [Search, Filtering & Faceting](search-and-faceting.md) — search parameters documented in OAS
- [Real-Time Updates](realtime-updates.md) — GraphQL subscriptions over SSE
- [AI & MCP Integration](ai-and-mcp.md) — AI agents use the OpenAPI spec for discovery
