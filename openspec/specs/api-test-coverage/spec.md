---
status: draft
---

# API Integration Test Coverage to 100%

Achieve 100% API route coverage with Newman integration tests and measure server-side code coverage from those tests using PCOV. Every API route defined in `appinfo/routes.php` SHALL have at least one Newman test covering the success path and one covering the error path.

## Current State

- **376 API routes** defined in `appinfo/routes.php`
- **71 Newman requests** in the existing Postman collection
- **18.9% route coverage** — only CRUD operations on core resources are tested
- **0% code coverage measurement** from integration tests — PCOV is not configured for API requests
- Existing collections: `openregister-crud.postman_collection.json`, `openregister-referential-integrity.postman_collection.json`, `magic-mapper-import.postman_collection.json`
- CI runs Newman against 4 database/storage combinations (PostgreSQL/MySQL x Normal/MagicMapper)

## Code Coverage from Integration Tests

### Requirement: PCOV coverage collection during Newman tests

Integration tests exercise the full stack — from HTTP request through controller, service, mapper, and back. By enabling PCOV during Newman test runs, every PHP line executed during API requests gets recorded.

#### Scenario: PCOV prepend script collects coverage per request

- **GIVEN** a PHP prepend script that starts PCOV coverage collection
- **AND** a shutdown function that writes the coverage data to a `.cov` file
- **WHEN** Newman sends API requests to the Nextcloud instance
- **THEN** each request generates a coverage file in a designated directory
- **AND** after the test run completes, `phpcov merge` combines all `.cov` files into a single `clover.xml`

**Implementation:**

```php
// tests/integration/coverage-prepend.php
<?php
if (!extension_loaded('pcov')) return;
pcov\start(PCov\COLLECT);
register_shutdown_function(function() {
    pcov\stop();
    $data = pcov\collect(PCov\COLLECT, pcov\includes());
    if (empty($data)) return;
    $id = uniqid('cov_', true);
    $dir = '/tmp/openregister-coverage';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents("$dir/$id.cov", serialize($data));
});
```

#### Scenario: Docker container configured for coverage collection

- **GIVEN** the Nextcloud Docker container
- **WHEN** running integration tests with coverage enabled
- **THEN** `php.ini` SHALL have `auto_prepend_file` set to the coverage prepend script
- **AND** PCOV extension SHALL be enabled (`pcov.enabled=1`)
- **AND** the coverage directory SHALL be writable

#### Scenario: Coverage merge produces clover report

- **GIVEN** multiple `.cov` files from Newman test requests
- **WHEN** `phpcov merge --clover=coverage/api-clover.xml /tmp/openregister-coverage/` is run
- **THEN** a clover XML report is produced
- **AND** `coverage-guard.php` can validate coverage hasn't regressed

### Requirement: Dual coverage reporting (unit + API)

#### Scenario: Combined coverage report

- **GIVEN** unit test coverage in `coverage/unit-clover.xml`
- **AND** API test coverage in `coverage/api-clover.xml`
- **WHEN** both reports are merged with `phpcov merge`
- **THEN** a combined `coverage/clover.xml` shows total project coverage
- **AND** the combined coverage SHALL be higher than either individual report

## Route Coverage Requirements

### Requirement: Test all core CRUD resources

Every resource with standard CRUD endpoints SHALL have tests for all operations.

#### Scenario: Full CRUD lifecycle per resource

- **GIVEN** a resource (registers, schemas, objects, organisations, views, etc.)
- **WHEN** Newman tests run
- **THEN** they SHALL cover:
  - `GET /api/{resource}` — list (empty, with data, with pagination, with filters)
  - `GET /api/{resource}/{id}` — show (exists, not found)
  - `POST /api/{resource}` — create (valid, missing required fields, invalid data)
  - `PUT /api/{resource}/{id}` — update (valid, not found, invalid data)
  - `DELETE /api/{resource}/{id}` — delete (exists, not found, has dependencies)

### Currently Tested Resources (partial coverage)

| Resource | Routes | Tested | Coverage |
|----------|--------|--------|----------|
| Registers | 15 | 3 | 20% |
| Schemas | 16 | 3 | 19% |
| Objects | 25 | 9 | 36% |
| Organisations | 13 | 4 | 31% |
| Audit Trail | 7 | 2 | 29% |
| Deleted | 7 | 1 | 14% |
| **Subtotal** | **83** | **22** | **27%** |

### Untested Resource Groups (0% coverage)

| Resource Group | Routes | Priority |
|----------------|--------|----------|
| Settings (12 controllers) | 86 | High |
| Files & Extraction | 30 | High |
| Webhooks & Workflow | 18 | High |
| Dashboard & Analytics | 23 | Medium |
| Search & SearchTrail | 14 | Medium |
| Configuration (GitHub/GitLab) | 15 | Medium |
| Endpoints | 10 | Medium |
| UI Pages | 19 | Low |
| Chat/Conversation | 5 | Low |
| Agents | 4 | Low |
| Other (Tags, Notes, Tasks, etc.) | 15 | Low |
| MCP | 8 | Low |
| **Subtotal** | **247** | |

### Requirement: Test settings controllers

The 12 settings controllers expose 86 routes for admin configuration. These affect system behavior across all features.

#### Scenario: Settings CRUD operations

- **GIVEN** any settings controller (CacheSettings, SolrSettings, LlmSettings, etc.)
- **WHEN** `GET /api/settings/{domain}` is called
- **THEN** it SHALL return current settings with 200
- **AND WHEN** `PUT /api/settings/{domain}` is called with valid data
- **THEN** it SHALL update and return the new settings
- **AND WHEN** called with invalid data
- **THEN** it SHALL return 400 with validation error

### Requirement: Test file operations

#### Scenario: File upload, download, and management

- **GIVEN** the Files controller and FileExtraction controller
- **WHEN** file operations are performed
- **THEN** tests SHALL cover:
  - Upload (multipart POST) — valid file, too large, invalid type
  - Download — exists, not found, no permission
  - Delete — exists, not found
  - Text extraction status and results
  - Vectorization triggers

### Requirement: Test webhook lifecycle

#### Scenario: Webhook CRUD and delivery testing

- **GIVEN** the Webhooks controller
- **WHEN** webhook operations are performed
- **THEN** tests SHALL cover:
  - Create webhook with valid URL and events
  - Update webhook filters
  - Trigger webhook via object creation → verify delivery log
  - Delete webhook
  - View webhook logs

### Requirement: Test search and filtering

#### Scenario: Advanced search across storage modes

- **GIVEN** the Search controller and Objects controller filtering
- **WHEN** search requests are made
- **THEN** tests SHALL cover:
  - Full-text search (with and without Solr)
  - Filter by property value
  - Filter by date range
  - Pagination (limit, offset)
  - Sorting (asc, desc)
  - Faceted search results

### Requirement: Test authorization and multi-tenancy

#### Scenario: RBAC enforcement on API routes

- **GIVEN** RBAC is enabled in settings
- **WHEN** a user without permission calls a restricted endpoint
- **THEN** the API SHALL return 403

#### Scenario: Multi-tenancy isolation

- **GIVEN** multi-tenancy is enabled
- **AND** two organisations exist with separate data
- **WHEN** a user in Organisation A requests objects
- **THEN** they SHALL only see Organisation A's objects

### Requirement: Test error responses

#### Scenario: Standard error format across all endpoints

- **GIVEN** any API endpoint
- **WHEN** an error occurs (400, 403, 404, 500)
- **THEN** the response SHALL contain:
  - Correct HTTP status code
  - JSON body with `message` field
  - No sensitive internal details (stack traces, file paths)

### Requirement: Test public endpoints

#### Scenario: Unauthenticated access to public routes

- **GIVEN** routes marked with `@PublicPage` or configured as public
- **WHEN** accessed without authentication
- **THEN** they SHALL return data normally
- **AND WHEN** non-public routes are accessed without authentication
- **THEN** they SHALL return 401

## Newman Collection Organization

### Requirement: Modular collection structure

Tests SHALL be organized in separate Postman collections by domain:

| Collection | Routes Covered | Est. Requests |
|------------|----------------|---------------|
| `openregister-crud.postman_collection.json` (exists) | Core CRUD | ~100 |
| `openregister-settings.postman_collection.json` (new) | All settings endpoints | ~120 |
| `openregister-files.postman_collection.json` (new) | File operations | ~50 |
| `openregister-webhooks.postman_collection.json` (new) | Webhook lifecycle | ~30 |
| `openregister-search.postman_collection.json` (new) | Search & filtering | ~40 |
| `openregister-auth.postman_collection.json` (new) | RBAC & multi-tenancy | ~40 |
| `openregister-advanced.postman_collection.json` (new) | Dashboard, config, MCP, agents | ~60 |
| `openregister-referential-integrity.postman_collection.json` (exists) | Cascading deletes | ~30 |

### Requirement: Postman test patterns

#### Scenario: Every request has assertions

- **GIVEN** any Postman request in a collection
- **THEN** it SHALL have a `Tests` script that:
  - Asserts the HTTP status code
  - Validates the response body structure (JSON schema or key checks)
  - Stores IDs/UUIDs in collection variables for subsequent requests
  - Logs descriptive messages on failure

#### Scenario: Cleanup after test runs

- **GIVEN** a test collection that creates resources
- **THEN** it SHALL have cleanup requests at the end that delete created resources
- **AND** the collection SHALL be idempotent (can be run multiple times)

## Composer Scripts

### Requirement: Add API coverage commands

```json
{
  "test:api:coverage": "Run Newman tests with PCOV coverage collection",
  "test:api:all": "Run all Newman collections",
  "coverage:api": "Generate API coverage report from collected .cov files",
  "coverage:combined": "Merge unit + API coverage into combined report"
}
```

## CI Integration

### Requirement: Coverage reporting in CI pipeline

#### Scenario: API coverage in PR comments

- **GIVEN** the CI pipeline runs Newman tests
- **WHEN** coverage collection is enabled
- **THEN** the PR comment SHALL include API code coverage percentage
- **AND** coverage regressions SHALL fail the pipeline

## Estimated Scope

| Category | New Requests | New Collections |
|----------|-------------|-----------------|
| Expand existing CRUD collection | ~30 | 0 |
| Settings endpoints | ~120 | 1 |
| File operations | ~50 | 1 |
| Webhooks & workflow | ~30 | 1 |
| Search & filtering | ~40 | 1 |
| Authorization & multi-tenancy | ~40 | 1 |
| Advanced features (dashboard, config, MCP) | ~60 | 1 |
| Coverage infrastructure | — | 0 |
| **Total** | **~370 new requests** | **6 new collections** |
