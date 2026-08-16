# Tenant Quotas

## Purpose
Define enforcement of per-organisation resource quotas (storage, bandwidth, API requests) to prevent any single tenant from monopolizing shared resources in a SaaS deployment. The Organisation entity already has `storageQuota`, `bandwidthQuota`, and `requestQuota` fields; this spec defines their enforcement, tracking, and overage handling.

**Source**: SaaS resource management; BIO availability requirements; fair-use policies for shared government platforms.

## ADDED Requirements

### Requirement: Request quota MUST be enforced via middleware before controller execution
A `TenantQuotaMiddleware` MUST check the active organisation's request quota before any controller method executes. Quota counters MUST be cached in APCu for performance.

#### Scenario: Request within quota is allowed
- **WHEN** Organisation "Gemeente Utrecht" has `requestQuota: 10000` (per hour) and current usage is 5000
- **AND** a new API request arrives scoped to this Organisation
- **THEN** the middleware MUST allow the request to proceed
- **AND** the APCu counter for this Organisation MUST be incremented by 1

#### Scenario: Request exceeding quota is rejected
- **WHEN** Organisation "Gemeente Utrecht" has `requestQuota: 10000` (per hour) and current usage is 10000
- **AND** a new API request arrives scoped to this Organisation
- **THEN** the middleware MUST return HTTP 429 Too Many Requests
- **AND** the response MUST include `Retry-After` header with seconds until quota reset
- **AND** the response body MUST include `{"error": "Request quota exceeded", "quota": 10000, "resetAt": "<ISO8601>"}`

#### Scenario: Null request quota means unlimited
- **WHEN** Organisation "Gemeente Utrecht" has `requestQuota: null`
- **AND** an API request arrives
- **THEN** the middleware MUST allow the request without quota checking

### Requirement: Storage quota MUST be enforced on object creation and file upload
Storage quota MUST be checked when creating or updating objects that would increase the organisation's total storage usage.

#### Scenario: Object creation within storage quota
- **WHEN** Organisation "Gemeente Utrecht" has `storageQuota: 1073741824` (1 GB) and current usage is 500 MB
- **AND** a new object with 100 KB of data is created
- **THEN** the object MUST be created successfully
- **AND** the organisation's storage usage counter MUST be updated

#### Scenario: Object creation exceeding storage quota is rejected
- **WHEN** Organisation "Gemeente Utrecht" has `storageQuota: 1073741824` (1 GB) and current usage is 1023 MB
- **AND** a new object with 10 MB of data is created
- **THEN** the API MUST return HTTP 507 Insufficient Storage
- **AND** the response MUST include `{"error": "Storage quota exceeded", "quota": 1073741824, "used": <bytes>, "required": <bytes>}`

### Requirement: Bandwidth quota MUST be tracked per response payload
Outgoing response bandwidth MUST be tracked per organisation and enforced against the `bandwidthQuota` (bytes per hour).

#### Scenario: Response within bandwidth quota
- **WHEN** Organisation "Gemeente Utrecht" has `bandwidthQuota: 10737418240` (10 GB/hour) and current hourly usage is 5 GB
- **AND** a response of 1 MB is sent
- **THEN** the response MUST be sent normally
- **AND** the bandwidth counter MUST be incremented by the response size

#### Scenario: Bandwidth quota exceeded
- **WHEN** Organisation "Gemeente Utrecht" has exceeded its `bandwidthQuota`
- **AND** a new API request arrives
- **THEN** the middleware MUST return HTTP 429 Too Many Requests with `Retry-After` header
- **AND** the response MUST indicate bandwidth quota exceeded

### Requirement: Usage counters MUST be persisted via background job
APCu-based counters MUST be flushed to the `openregister_tenant_usage` database table by a background job for dashboard display and historical tracking.

#### Scenario: Background job persists usage data
- **WHEN** the `TenantUsageSyncJob` runs (every 5 minutes)
- **THEN** it MUST read all APCu counters for all organisations
- **AND** it MUST upsert rows in `openregister_tenant_usage` with columns: `organisation_uuid`, `period` (hourly bucket), `request_count`, `bandwidth_bytes`, `storage_bytes`
- **AND** it MUST reset the APCu hourly counters after successful persistence

#### Scenario: Usage data is available for dashboard
- **WHEN** an administrator calls `GET /api/organisations/{uuid}/usage`
- **THEN** the response MUST include current-hour usage (from APCu if available, database otherwise)
- **AND** the response MUST include historical usage for the last 30 days (from database)
- **AND** the response MUST include quota limits and percentage utilization

### Requirement: Database migration MUST create tenant usage tracking table

#### Scenario: Migration creates usage table
- **WHEN** the database migration runs
- **THEN** a table `openregister_tenant_usage` MUST be created with columns: `id` (bigint, primary key), `organisation_uuid` (varchar, indexed), `period` (datetime, indexed), `request_count` (bigint, default 0), `bandwidth_bytes` (bigint, default 0), `storage_bytes` (bigint, default 0), `created` (datetime), `updated` (datetime)
- **AND** a composite index MUST exist on (`organisation_uuid`, `period`)
