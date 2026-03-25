# Multi-Tenancy & SaaS

## Overview

OpenRegister supports multi-tenant deployments where a single Nextcloud installation serves multiple independent organisations. Each organisation has isolated data, separate quotas, independent configuration, and a distinct lifecycle. The multi-tenancy model is built on the `Organisation` entity with `MultiTenancyTrait` applied to all data mappers — ensuring data never leaks across organisation boundaries.

## Architecture

### Organisation Entity

Every piece of data in OpenRegister is scoped to an organisation:

```
Organisation
  ├── Registers (namespace isolation)
  │     └── Schemas
  │           └── Objects
  ├── Webhooks
  ├── Views
  ├── Configurations
  ├── Sources
  └── Agents / Applications
```

The `organisation` field on every entity is the partition key. `MultiTenancyTrait.applyOrganisationFilter()` is called in every mapper query to inject a `WHERE organisation = :org` condition automatically.

### Active Organisation Resolution

The active organisation is resolved per-request in `OrganisationService`:

1. From the authenticated user's organisation membership (Nextcloud group → organisation mapping)
2. From a `X-Organisation` header (for multi-org users with explicit context switching)
3. From the JWT/OAuth2 claim (for external API clients)

Admins can act on behalf of any organisation by passing `X-Organisation: {uuid}`.

### RBAC Dynamic Variable

Row-level RBAC conditions use `$organisation` as a dynamic variable:

```json
{
  "conditions": [
    { "field": "organisation", "operator": "=", "value": "$organisation" }
  ]
}
```

This resolves to the active organisation UUID at query time, ensuring objects are filtered to the requesting organisation.

## Tenant Isolation

### Data Isolation

- All SQL queries include `AND organisation = :org` via `MultiTenancyTrait`
- Cross-tenant queries are impossible via the normal API surface
- Admin-only endpoints (`?organisation=*`) require explicit admin privilege
- Soft-deleted objects are also organisation-scoped

### File Isolation

Files attached to objects are stored in an organisation-scoped path:

```
/organisations/{org-slug}/openregister/{register}/{schema}/{object-uuid}/
```

Nextcloud's file permission system enforces access at the storage layer as a second line of defence.

### Webhook Isolation

Webhooks are organisation-scoped via `MultiTenancyTrait` on `WebhookMapper`. Each organisation's webhooks only receive events from their own objects.

### Search Isolation

All search backends (PostgreSQL, Solr, Elasticsearch) receive organisation filter parameters. There is no cross-tenant data leakage through search indexes.

## Tenant Lifecycle

### Provisioning

New tenants are created via the tenant lifecycle API:

```
POST /api/organisations
{
  "name": "Gemeente Voorbeeld",
  "slug": "gemeente-voorbeeld",
  "plan": "professional",
  "adminUser": "admin@gemeente-voorbeeld.nl"
}
```

Provisioning creates:
- Organisation entity with UUID
- Default registers and schemas from the configured organisation template
- Admin user with organisation-admin role
- Initial quota allocation

### Organisation Templates

Administrators can define templates for rapid tenant onboarding — a bundle of registers, schemas, and default configuration that is copied to a new tenant on creation.

### Suspension and Deletion

| State | Effect |
|-------|--------|
| Active | Normal operation |
| Suspended | API returns `403 Forbidden`; data is retained |
| Deleted (soft) | Data marked as deleted; retained for 30 days |
| Deleted (permanent) | All data physically purged after retention period |

Suspension and deletion are performed via the tenant lifecycle API and produce audit trail entries.

## Quota Management

Quotas are enforced per organisation:

| Resource | Quota Field | Enforcement Point |
|----------|-------------|-------------------|
| Objects | `quota.maxObjects` | Before `SaveObject` |
| Storage (files) | `quota.maxStorageBytes` | Before file upload |
| API requests/day | `quota.maxDailyRequests` | Rate limiter middleware |
| Schemas | `quota.maxSchemas` | Before `Schema::create()` |
| Webhooks | `quota.maxWebhooks` | Before `Webhook::create()` |

When a quota is exceeded, the API returns `429 Too Many Requests` or `507 Insufficient Storage` with a `Retry-After` header or quota increase instructions.

### Quota Plans

Quota profiles can be named plans:

```json
{
  "plans": {
    "starter":      { "maxObjects": 10000, "maxStorageBytes": 1073741824 },
    "professional": { "maxObjects": 500000, "maxStorageBytes": 53687091200 },
    "enterprise":   { "maxObjects": -1, "maxStorageBytes": -1 }
  }
}
```

`-1` means unlimited. Plans are assigned at provisioning and can be upgraded via the admin API.

## API

```
GET    /api/organisations                     List organisations (admin only)
POST   /api/organisations                     Create a new organisation (provision)
GET    /api/organisations/{id}                Get organisation metadata
PUT    /api/organisations/{id}                Update organisation settings
POST   /api/organisations/{id}/suspend        Suspend a tenant
POST   /api/organisations/{id}/reactivate     Reactivate a suspended tenant
DELETE /api/organisations/{id}                Soft-delete a tenant
GET    /api/organisations/{id}/quota          Get quota usage
PUT    /api/organisations/{id}/quota          Update quota limits
GET    /api/organisations/{id}/statistics     Usage statistics
```

## Related Features

- [Access Control (RBAC)](access-control.md) — `$organisation` dynamic variable in row-level conditions
- [Object Storage & Lifecycle](object-storage.md) — all objects carry `organisation` field
- [Webhooks & Notifications](webhooks-and-notifications.md) — webhook isolation per organisation
- [Content Versioning & Audit Trail](versioning-and-audit.md) — audit entries are organisation-scoped
