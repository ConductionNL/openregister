## 1. Database Migration

- [x] 1.1 Create migration `Version1Date20260322000000` adding `status` (varchar(20), default 'active'), `environment` (varchar(20), default 'production'), `provisioned_at` (datetime, nullable), `suspended_at` (datetime, nullable), `deprovisioned_at` (datetime, nullable) columns to `openregister_organisations` table
- [x] 1.2 Create `openregister_tenant_usage` table with columns: `id`, `organisation_uuid` (indexed), `period` (datetime, indexed), `request_count`, `bandwidth_bytes`, `storage_bytes`, `created`, `updated` and composite index on (`organisation_uuid`, `period`)
- [x] 1.3 Update `Organisation` entity class with new properties: `status`, `environment`, `provisionedAt`, `suspendedAt`, `deprovisionedAt` with proper type declarations and jsonSerialize

## 2. Tenant Lifecycle Service

- [x] 2.1 Create `TenantLifecycleService` with state machine: `provisioning` -> `active` -> `suspended` -> `deprovisioning` -> `archived`, with `reactivate` path from `suspended` back to `active`
- [x] 2.2 Implement `provision()` method that creates default groups (prefixed with org slug), sets default authorization RBAC, and transitions to `active`
- [x] 2.3 Implement `suspend()` and `reactivate()` methods with timestamp tracking and event dispatching (`OrganisationSuspendedEvent`, `OrganisationActivatedEvent`)
- [x] 2.4 Implement `deprovision()` method that creates configuration backup export and transitions to `deprovisioning` state
- [x] 2.5 Create `TenantDeprovisionJob` background job that soft-deletes all objects for deprovisioning organisations and transitions to `archived`
- [x] 2.6 Create `TenantPurgeJob` background job that permanently deletes archived organisations after configurable retention period (default 90 days)

## 3. Tenant Quota Middleware

- [x] 3.1 Create `TenantQuotaMiddleware` implementing `OCP\AppFramework\Middleware` with `beforeController()` check for request quota (APCu counter) and organisation status
- [x] 3.2 Implement APCu-based request counter with hourly buckets keyed by `or_quota_{orgUuid}_{hourBucket}`
- [x] 3.3 Implement bandwidth tracking in `afterController()` by measuring response content length
- [x] 3.4 Implement storage quota check in `ObjectService::saveObject()` before persisting objects
- [x] 3.5 Register `TenantQuotaMiddleware` in `Application.php` via `registerMiddleware()`
- [x] 3.6 Create `TenantUsageSyncJob` background job that flushes APCu counters to `openregister_tenant_usage` table every 5 minutes

## 4. Environment OTAP Support

- [x] 4.1 Add environment validation to `OrganisationMapper::insert()` and `update()` — enforce immutability of environment field after activation
- [x] 4.2 Implement environment-aware quota multipliers in `TenantQuotaMiddleware` (development: 10x request/5x bandwidth, test: 5x/3x, acceptance: 2x/2x)
- [x] 4.3 Create `POST /api/organisations/{uuid}/reset-data` endpoint — allowed only for `test` and `development` environments
- [x] 4.4 Implement configuration promotion via `POST /api/organisations/{sourceUuid}/promote` with target validation (must follow OTAP order)
- [x] 4.5 Create promotion snapshot mechanism using existing `ConfigurationService::ExportHandler` with environment-aware UUID remapping

## 5. Tenant Isolation Hardening

- [x] 5.1 Add `saasMode` flag to multitenancy configuration — when enabled, organisation boundary MUST NOT be bypassed even with `adminOverride: true`
- [x] 5.2 Modify `MultiTenancyTrait::applyOrganisationFilter()` to enforce hard boundary in SaaS mode regardless of admin status
- [x] 5.3 Modify `MagicOrganizationHandler::applyOrganizationFilter()` to enforce hard boundary in SaaS mode
- [x] 5.4 Add cross-tenant access audit logging to `MultiTenancyTrait::verifyOrganisationAccess()` — log denied attempts with userId, source/target org, entity details
- [x] 5.5 Add cross-tenant admin override audit logging — when admin override grants cross-tenant access (non-SaaS mode), create audit trail entry

## 6. Auth System Tenant Validation

- [x] 6.1 Add post-authentication organisation validation in `AuthorizationService` — after identity resolution, verify user has active Organisation with `status: active`
- [x] 6.2 Return HTTP 403 with `"No active organisation"` message when validation fails, exempting public endpoints
- [x] 6.3 Add organisation status check to middleware — suspended/deprovisioning orgs return 403, provisioning orgs allow admin-only access

## 7. Admin API Endpoints

- [x] 7.1 Create `PUT /api/organisations/{uuid}/suspend` endpoint in `OrganisationController`
- [x] 7.2 Create `PUT /api/organisations/{uuid}/activate` endpoint in `OrganisationController`
- [x] 7.3 Create `PUT /api/organisations/{uuid}/deprovision` endpoint in `OrganisationController`
- [x] 7.4 Create `GET /api/organisations/{uuid}/usage` endpoint returning quota utilization and historical data
- [x] 7.5 Create `POST /api/admin/isolation-verify` endpoint that runs cross-tenant isolation verification checks
- [x] 7.6 Create `GET /api/admin/isolation-metrics` endpoint returning tenant isolation health metrics
- [x] 7.7 Register all new routes in `appinfo/routes.php`

## 8. Testing and Verification

- [x] 8.1 Write unit tests for `TenantLifecycleService` state machine transitions (valid and invalid)
- [x] 8.2 Write unit tests for `TenantQuotaMiddleware` quota enforcement (within quota, exceeded, null quota)
- [x] 8.3 Write integration tests for organisation boundary enforcement in SaaS mode
- [x] 8.4 Write unit tests for environment-aware configuration promotion (valid OTAP order, invalid reverse promotion)
- [x] 8.5 Verify no regressions with opencatalogi and softwarecatalog by running their test suites
- [x] 8.6 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix all issues
