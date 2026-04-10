## Why

OpenRegister already has basic multi-tenancy through Organisation entities, the MultiTenancyTrait, and MagicOrganizationHandler. However, for true SaaS deployment (where multiple municipalities or organisations share one Nextcloud instance), critical gaps exist: there is no environment-level isolation (OTAP/DTAP), no per-tenant resource quotas enforcement, no tenant provisioning/deprovisioning workflow, and no data export/portability guarantees. Government tenders consistently require demonstrable tenant isolation (BIO/ISO 27001), environment promotion (development to production), and data sovereignty. This change hardens the existing multi-tenancy into production-grade SaaS isolation.

## What Changes

- **Tenant lifecycle management**: Add provisioning and deprovisioning API for organisations with automated setup of default schemas, groups, and configurations
- **Environment tagging (OTAP)**: Add environment type (Ontwikkeling/Test/Acceptatie/Productie) to Organisation entity, enabling environment-aware configuration and data promotion between environments
- **Resource quota enforcement**: Enforce the existing `storageQuota`, `bandwidthQuota`, and `requestQuota` fields on Organisation with middleware-level checks and usage tracking
- **Tenant data isolation hardening**: Add database-level isolation verification, cross-tenant access audit logging, and automated penetration-style isolation tests
- **Configuration promotion**: Enable exporting and importing organisation configurations (schemas, mappings, sources, webhooks) between OTAP environments
- **Tenant usage dashboard**: Per-organisation usage metrics (storage, requests, objects) for administrators

## Capabilities

### New Capabilities
- `tenant-lifecycle`: Provisioning, deprovisioning, and suspension of tenant organisations with automated setup and teardown workflows
- `environment-otap`: OTAP environment tagging on organisations with environment-aware behavior and configuration promotion between environments
- `tenant-quotas`: Enforcement of storage, bandwidth, and request quotas per organisation with usage tracking and overage handling
- `tenant-isolation-audit`: Cross-tenant access audit logging, isolation verification, and automated isolation testing

### Modified Capabilities
- `auth-system`: Add tenant-context validation to all authentication flows — ensure resolved identity is always scoped to an active organisation before RBAC evaluation
- `row-field-level-security`: Extend RLS to enforce hard tenant boundaries at the database query level, preventing any cross-tenant data leakage even for admin users in SaaS mode

## Impact

- **Database**: New columns on `openregister_organisations` (environment, status, usage tracking fields); new `openregister_tenant_usage` table for quota tracking
- **API**: New `/api/tenants` endpoints for lifecycle management; modified Organisation CRUD to enforce OTAP rules
- **Middleware**: Request-level quota checking via Nextcloud middleware
- **Configuration service**: Extended to support environment-aware export/import
- **Dependent apps**: opencatalogi, softwarecatalog must respect tenant isolation — no breaking changes, but they inherit stricter filtering from OpenRegister's MultiTenancyTrait
- **Performance**: Quota checks add ~1ms per request (APCu-cached counters); no impact on query performance
