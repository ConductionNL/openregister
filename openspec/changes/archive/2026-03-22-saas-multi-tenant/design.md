## Context

OpenRegister has foundational multi-tenancy via the Organisation entity, MultiTenancyTrait, and MagicOrganizationHandler. Every entity mapper applies organisation-based filtering via `applyOrganisationFilter()`. The Organisation entity already has `storageQuota`, `bandwidthQuota`, and `requestQuota` fields, but these are not enforced. There is no concept of environment types (OTAP) or tenant lifecycle states. The existing `ConfigurationService` supports export/import but is not environment-aware.

Key existing components:
- `Organisation` entity with uuid, users, groups, authorization, quotas, parent hierarchy
- `MultiTenancyTrait` used by all mappers for org filtering
- `MagicOrganizationHandler` for dynamic table org filtering
- `OrganisationService` for active org resolution
- `ConfigurationService` with `ExportHandler`/`ImportHandler` for config transfer

## Goals / Non-Goals

**Goals:**
- Harden tenant isolation to SaaS-grade (BIO/ISO 27001 compliant)
- Add OTAP environment tagging to organisations for environment-aware configuration
- Enforce existing quota fields with middleware-level request checks
- Provide tenant provisioning/deprovisioning API with automated setup
- Enable configuration promotion between OTAP environments
- Add cross-tenant access audit trail

**Non-Goals:**
- Database-per-tenant isolation (stays shared-database, shared-schema with row-level filtering)
- Billing or payment integration
- Custom domain per tenant (handled at reverse proxy level)
- Nextcloud user provisioning (tenants map to existing Nextcloud users)
- Network-level isolation (handled at infrastructure level)

## Decisions

### Decision 1: Extend Organisation entity rather than new Tenant entity
**Choice**: Add `environment`, `status`, and lifecycle fields to the existing Organisation entity.
**Rationale**: Organisation already serves as the tenant boundary. Adding a separate Tenant entity would create a confusing dual-identity model. The Organisation entity already has quota fields and parent hierarchy.
**Alternative considered**: Separate Tenant entity wrapping Organisation — rejected because it adds join complexity and breaks the established MultiTenancyTrait pattern.

### Decision 2: Middleware-based quota enforcement via Nextcloud IMiddleware
**Choice**: Implement `TenantQuotaMiddleware` as a Nextcloud `IMiddleware` that checks request/bandwidth quotas before controller execution.
**Rationale**: Nextcloud's middleware pipeline runs before controllers, ensuring all API endpoints are covered. APCu-cached counters keep overhead minimal (~1ms).
**Alternative considered**: Controller-level checks — rejected because it requires modifying every controller and is easy to miss.

### Decision 3: APCu for quota counters, database for persistence
**Choice**: Track request/bandwidth usage in APCu counters during the request lifecycle, flush to `openregister_tenant_usage` table via background job.
**Rationale**: Per-request database writes for counters would add 5-10ms latency. APCu is per-process but sufficient for rate limiting. Background job syncs every 5 minutes for dashboards. Storage quota is calculated on-demand from actual object storage.
**Alternative considered**: Redis counters — rejected because OpenRegister does not require Redis as a dependency.

### Decision 4: OTAP as an enum field on Organisation
**Choice**: Add `environment` field with values: `development`, `test`, `acceptance`, `production` (default: `production`).
**Rationale**: Simple enum allows environment-aware behavior (e.g., relaxed rate limits in development, stricter audit in production) without complex configuration. Maps directly to Dutch OTAP terminology.
**Alternative considered**: Separate environment configuration entity — rejected as over-engineering for what is essentially a tag.

### Decision 5: Configuration promotion via enhanced ConfigurationService
**Choice**: Extend existing `ExportHandler`/`ImportHandler` to support environment-aware export with environment field remapping and conflict resolution.
**Rationale**: The configuration export/import infrastructure already exists. Adding environment awareness is incremental. Promotion is a directed export from source env to target env with validation.
**Alternative considered**: Git-based configuration management — rejected because it requires external tooling and adds operational complexity.

### Decision 6: Tenant lifecycle states as a state machine
**Choice**: Organisation gets a `status` field: `provisioning` -> `active` -> `suspended` -> `deprovisioning` -> `archived`.
**Rationale**: Clear state transitions prevent accidental data loss and enable graceful suspension (e.g., for non-payment in SaaS). Suspended tenants retain data but lose API access.
**Alternative considered**: Boolean active/inactive — rejected because it does not capture the provisioning and deprovisioning workflows.

## Risks / Trade-offs

- **[Risk] APCu counter loss on Apache restart** -> Mitigation: Counters reset to zero, which means brief under-counting. Background job reconciles from database. Acceptable for rate limiting (fail-open briefly).
- **[Risk] Migration adds columns to heavily-used organisations table** -> Mitigation: Use nullable columns with defaults; no table locks on PostgreSQL for ADD COLUMN with DEFAULT.
- **[Risk] Quota enforcement adds latency to every request** -> Mitigation: APCu lookups are <0.1ms; full middleware overhead measured at ~1ms including org resolution cache hit.
- **[Risk] OTAP environment promotion could overwrite production data** -> Mitigation: Promotion requires explicit confirmation, creates a backup snapshot, and only transfers configuration (not data objects).
- **[Risk] Existing deployments without environment field** -> Mitigation: Default to `production` for all existing organisations; migration is additive-only.

## Migration Plan

1. **Database migration**: Add `environment` (varchar, default 'production'), `status` (varchar, default 'active'), `provisioned_at`, `suspended_at` columns to `openregister_organisations`. Create `openregister_tenant_usage` table.
2. **Backfill**: Set all existing organisations to `environment=production`, `status=active`.
3. **Middleware registration**: Register `TenantQuotaMiddleware` in Application.php.
4. **Background job**: Register `TenantUsageSyncJob` for quota counter persistence.
5. **Rollback**: Remove middleware registration; drop new columns (data-safe since they have defaults).

## Open Questions

- Should environment promotion require a specific Nextcloud group/role, or is admin sufficient?
- Should suspended tenants return HTTP 402 (Payment Required) or 403 (Forbidden)?
- What is the retention period for archived tenant data before permanent deletion?
