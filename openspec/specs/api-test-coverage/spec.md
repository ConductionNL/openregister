---
status: draft
---

# API Integration Test Coverage to 100%

## Purpose
Achieve 100% API route coverage with Newman integration tests and measure server-side code coverage from those tests using PCOV. Every API route defined in `appinfo/routes.php` SHALL have at least one Newman test covering the success path and one covering the error path. The app defines **386 API routes** across 50 controllers (including 12 Settings sub-controllers) and 9 resource controllers. Existing coverage stands at ~18.9% (71 requests out of 386 routes). This spec defines the full test matrix, collection structure, CI integration, and coverage measurement infrastructure needed to reach 100%.

## Current State

- **386 API routes** defined in `appinfo/routes.php` (354 API endpoints + 22 page routes + 9 resource controller groups generating ~45 auto-routes)
- **8 existing Postman/Newman collections** across three directories:
  - `tests/integration/openregister-crud.postman_collection.json` (5837 lines, ~199 tests per storage mode)
  - `tests/integration/openregister-referential-integrity.postman_collection.json` (818 lines)
  - `tests/integration/magic-mapper-import.postman_collection.json` (295 lines)
  - `tests/postman/openregister-crud-tests.postman_collection.json` (605 lines)
  - `tests/postman/openregister-relations-tests.postman_collection.json` (1772 lines)
  - `tests/postman/openregister-graphql-tests.postman_collection.json` (505 lines)
  - `tests/newman/agent-cms-testing.postman_collection.json` (469 lines)
  - `tests/performance/performance-test-collection.json` (274 lines)
- **Dual-storage testing** in CI: `run-dual-storage-tests.sh` runs collections against both Normal (JSON blob) and Magic Mapper (SQL) storage modes
- **CI pipeline** defined in `.github/workflows/quality.yml` -- Newman runs are delegated to a database-tests workflow matrix (PostgreSQL/MySQL x Normal/MagicMapper)
- **0% PCOV coverage measurement** from integration tests -- no `coverage-prepend.php` exists
- **50 controllers** identified in `lib/Controller/` (38 root + 12 Settings sub-controllers)
- **Public endpoints** exist on: ObjectsController, GraphQLController, McpController, OasController, NamesController, UserController, FilesController (annotated with `@PublicPage`)
- **CORS-enabled endpoints**: McpServerController, GraphQLController, GraphQLSubscriptionController, McpController

## Requirements

### Requirement: Newman collection per API resource group with full CRUD lifecycle

Every resource group (Registers, Schemas, Objects, Organisations, Sources, Configurations, Applications, Agents, Endpoints, Mappings, Consumers, Views, Webhooks, WorkflowEngines, Conversations, AuditTrails, SearchTrails, Deleted, Files, Tags, Notes, Tasks, Names, Bulk, Chat, Dashboard, Search, FileExtraction, FileText, FileSearch, GDPR Entities, OAS, Revert, User, UserSettings, Migration, Tables, Heartbeat, Metrics, Health) SHALL have dedicated test folders within Newman collections covering the complete CRUD lifecycle. Tests SHALL exercise every HTTP verb defined for the resource in `appinfo/routes.php`.

#### Scenario: Full CRUD lifecycle for a resource controller auto-generated routes
- **GIVEN** a resource declared in `routes.php` `'resources'` array (e.g., `'Registers' => ['url' => 'api/registers']`)
- **WHEN** Newman tests run for that resource
- **THEN** they SHALL cover `GET /api/registers` (index), `GET /api/registers/{id}` (show), `POST /api/registers` (create), `PUT /api/registers/{id}` (update), `DELETE /api/registers/{id}` (destroy)
- **AND** the PATCH route defined separately (`PATCH /api/registers/{id}`) SHALL also be tested
- **AND** custom routes (e.g., `GET /api/registers/{id}/schemas`, `GET /api/registers/{id}/stats`, `POST /api/registers/{id}/import`, `GET /api/registers/{id}/export`, `POST /api/registers/{id}/publish`, `POST /api/registers/{id}/depublish`, `POST /api/registers/{id}/publish/github`, `GET /api/registers/{id}/oas`) SHALL each have at least one success and one error test

#### Scenario: Objects controller with all sub-routes tested
- **GIVEN** the Objects controller has 25+ routes including nested resource routes
- **WHEN** Newman tests run for Objects
- **THEN** they SHALL cover: `GET /api/objects` (global list), `GET /api/objects/{register}/{schema}` (scoped list), `POST /api/objects/{register}/{schema}` (create), `GET /api/objects/{register}/{schema}/{id}` (show), `PUT /api/objects/{register}/{schema}/{id}` (update), `PATCH /api/objects/{register}/{schema}/{id}` (patch), `POST /api/objects/{register}/{schema}/{id}` (postPatch for multipart), `DELETE /api/objects/{register}/{schema}/{id}` (destroy), `GET /api/objects/{register}/{schema}/{id}/can-delete`, `POST /api/objects/{register}/{schema}/{id}/merge`, `GET /api/objects/{register}/{schema}/{id}/contracts`, `GET /api/objects/{register}/{schema}/{id}/uses`, `GET /api/objects/{register}/{schema}/{id}/used`, `POST /api/objects/{register}/{schema}/{id}/lock`, `POST /api/objects/{register}/{schema}/{id}/unlock`, `POST /api/objects/{register}/{schema}/{id}/revert`, `GET /api/objects/{register}/{schema}/{id}/audit-trails`, `GET /api/objects/{register}/{schema}/export`, `POST /api/objects/validate`, `POST /api/objects/vectorize/batch`, `GET /api/objects/vectorize/count`, `GET /api/objects/vectorize/stats`, `GET /api/objects/{register}/{schema}/{id}/files/download`, `DELETE /api/objects/clear-blob`, `POST /api/migrate`

#### Scenario: Schema controller with upload and discovery routes tested
- **GIVEN** the Schemas controller has custom routes beyond CRUD
- **WHEN** Newman tests run
- **THEN** they SHALL cover: standard CRUD (index, show, create, update, destroy, patch) PLUS `POST /api/schemas/upload` (JSON Schema upload), `PUT /api/schemas/{id}/upload` (update from upload), `GET /api/schemas/{id}/download`, `GET /api/schemas/{id}/related`, `GET /api/schemas/{id}/stats`, `GET /api/schemas/{id}/explore`, `POST /api/schemas/{id}/update-from-exploration`, `POST /api/schemas/{id}/publish`, `POST /api/schemas/{id}/depublish`

### Requirement: Error response testing for all HTTP error codes (400, 401, 403, 404, 409, 422, 500)

Every API endpoint SHALL have tests that verify correct error responses. The error response body SHALL always be JSON with at minimum a `message` field. No error response SHALL leak stack traces, file paths, or internal class names.

#### Scenario: 400 Bad Request on invalid input data
- **GIVEN** any POST or PUT endpoint that accepts JSON input
- **WHEN** the request body contains invalid data (wrong types, missing required fields, malformed JSON)
- **THEN** the response SHALL return HTTP 400
- **AND** the body SHALL contain `{"message": "..."}` with a human-readable validation error
- **AND** no internal PHP class names or stack traces SHALL appear in the response

#### Scenario: 401 Unauthorized on unauthenticated access to protected routes
- **GIVEN** any endpoint NOT annotated with `@PublicPage`
- **WHEN** the request is sent without authentication headers
- **THEN** the response SHALL return HTTP 401
- **AND** the response body SHALL indicate authentication is required

#### Scenario: 403 Forbidden when RBAC denies access
- **GIVEN** RBAC is enabled in settings and a user lacks permission for an action
- **WHEN** the user calls a restricted endpoint (e.g., `DELETE /api/registers/{id}` without admin role)
- **THEN** the response SHALL return HTTP 403
- **AND** the body SHALL contain a descriptive permission-denied message

#### Scenario: 404 Not Found on non-existent resources
- **GIVEN** any show, update, patch, or delete endpoint
- **WHEN** called with a non-existent ID (e.g., `GET /api/registers/99999`)
- **THEN** the response SHALL return HTTP 404

#### Scenario: 409 Conflict on duplicate or dependency violations
- **GIVEN** a resource with uniqueness constraints or referential integrity
- **WHEN** a create or update would violate the constraint (e.g., duplicate UUID, delete with active dependencies)
- **THEN** the response SHALL return HTTP 409

#### Scenario: 422 Unprocessable Entity on schema validation failure
- **GIVEN** an object creation endpoint with JSON Schema validation enabled on the schema
- **WHEN** the submitted data fails schema validation (wrong property types, missing required properties, pattern violations)
- **THEN** the response SHALL return HTTP 422
- **AND** the body SHALL contain structured validation errors listing each failing property

#### Scenario: 500 Internal Server Error responses do not leak internals
- **GIVEN** any API endpoint
- **WHEN** an unexpected server error occurs
- **THEN** the response SHALL return HTTP 500
- **AND** the body SHALL contain a generic error message
- **AND** no PHP file paths, class names, or stack traces SHALL appear

### Requirement: Pagination, sorting, and filtering tests on all list endpoints

Every `GET` endpoint that returns a collection (index routes) SHALL be tested with pagination parameters, sort parameters, and filter parameters. These tests verify the NL API Design Rules compliance for collection endpoints.

#### Scenario: Pagination with limit and offset
- **GIVEN** a register with 25+ objects
- **WHEN** `GET /api/objects/{register}/{schema}?_limit=10&_offset=0` is called
- **THEN** the response SHALL return exactly 10 items
- **AND** the response SHALL include pagination metadata (`total`, `page`, `pages` or equivalent)
- **AND WHEN** `_offset=10` is used
- **THEN** the response SHALL return the next 10 items with no overlap

#### Scenario: Pagination with page and limit
- **GIVEN** a register with 25+ objects
- **WHEN** `GET /api/objects/{register}/{schema}?_page=2&_limit=10` is called
- **THEN** the response SHALL return items 11-20

#### Scenario: Sorting ascending and descending
- **GIVEN** a register with objects having varying `title` values
- **WHEN** `GET /api/objects/{register}/{schema}?_order[title]=asc` is called
- **THEN** the results SHALL be sorted alphabetically A-Z by title
- **AND WHEN** `_order[title]=desc` is used
- **THEN** results SHALL be sorted Z-A

#### Scenario: Filtering by property value
- **GIVEN** objects with a `status` property having values `draft`, `published`, `archived`
- **WHEN** `GET /api/objects/{register}/{schema}?status=published` is called
- **THEN** only objects with `status=published` SHALL be returned

#### Scenario: Filtering by date range
- **GIVEN** objects created on different dates
- **WHEN** `GET /api/objects/{register}/{schema}?_created[after]=2024-01-01&_created[before]=2024-12-31` is called
- **THEN** only objects created within that range SHALL be returned

#### Scenario: Empty collection returns valid response
- **GIVEN** a register/schema combination with zero objects
- **WHEN** `GET /api/objects/{register}/{schema}` is called
- **THEN** the response SHALL return HTTP 200 with an empty array and `total: 0`

### Requirement: Authentication matrix testing (admin, regular user, public, no-auth)

Every endpoint SHALL be tested with at least two authentication contexts. Public endpoints (`@PublicPage`) SHALL be tested with and without auth. Protected endpoints SHALL be tested with admin credentials and without credentials.

#### Scenario: Admin user can access all endpoints
- **GIVEN** valid admin credentials (admin:admin)
- **WHEN** any API endpoint is called with Basic Auth
- **THEN** the request SHALL succeed (200/201) for valid operations

#### Scenario: Unauthenticated user can access public endpoints
- **GIVEN** endpoints annotated with `@PublicPage` (ObjectsController index/show, GraphQLController execute, McpController discover/discoverCapability, OasController generate/generateAll, NamesController index/show/stats/warmup/create, UserController login, FilesController specific routes)
- **WHEN** the endpoint is called without authentication
- **THEN** the response SHALL return 200 with valid data (no 401)

#### Scenario: Unauthenticated user is blocked from protected endpoints
- **GIVEN** any endpoint NOT annotated with `@PublicPage` (Settings controllers, Dashboard, AuditTrail, Webhooks, etc.)
- **WHEN** the endpoint is called without authentication
- **THEN** the response SHALL return HTTP 401

#### Scenario: Regular user access with RBAC enabled
- **GIVEN** a non-admin user with specific organisation membership
- **AND** RBAC is enabled via `PUT /api/settings/rbac`
- **WHEN** the user calls endpoints outside their permission scope
- **THEN** the response SHALL return HTTP 403
- **AND WHEN** the user calls endpoints within their scope
- **THEN** the response SHALL return 200 with data filtered to their organisation

### Requirement: GraphQL endpoint integration testing

The GraphQL API endpoints (`POST /api/graphql`, `GET /api/graphql/explorer`, `GET /api/graphql/subscribe`) SHALL be tested for schema introspection, query execution, mutation execution, error handling, and subscription lifecycle.

#### Scenario: GraphQL introspection query returns valid schema
- **GIVEN** registers and schemas exist with published data
- **WHEN** `POST /api/graphql` is called with `{"query": "{ __schema { types { name } } }"}`
- **THEN** the response SHALL return HTTP 200 with a valid GraphQL schema containing dynamically generated types from OpenRegister schemas

#### Scenario: GraphQL query returns objects
- **GIVEN** a register with schema and objects
- **WHEN** `POST /api/graphql` is called with a query for objects of that schema
- **THEN** the response SHALL return matching objects in GraphQL format with `data` wrapper

#### Scenario: GraphQL mutation creates an object
- **GIVEN** a register and schema
- **WHEN** `POST /api/graphql` is called with a mutation to create an object
- **THEN** the response SHALL return the created object with generated UUID

#### Scenario: GraphQL query with invalid syntax returns error
- **GIVEN** any state
- **WHEN** `POST /api/graphql` is called with `{"query": "{ invalid syntax }"}`
- **THEN** the response SHALL return HTTP 200 with an `errors` array per GraphQL spec (errors are returned in-band)

#### Scenario: GraphQL explorer returns HTML interface
- **GIVEN** the GraphQL API is available
- **WHEN** `GET /api/graphql/explorer` is called
- **THEN** the response SHALL return HTML content with the GraphiQL or similar explorer interface

#### Scenario: GraphQL subscription endpoint accepts SSE connection
- **GIVEN** a valid subscription query
- **WHEN** `GET /api/graphql/subscribe` is called with appropriate headers
- **THEN** the response SHALL use `text/event-stream` content type for Server-Sent Events
- **AND** the endpoint is annotated with `@CORS` so cross-origin requests SHALL be accepted

### Requirement: MCP endpoint integration testing

The MCP (Model Context Protocol) endpoints SHALL be tested for both the discovery API (`GET /api/mcp/v1/discover`, `GET /api/mcp/v1/discover/{capability}`) and the standard JSON-RPC 2.0 protocol endpoint (`POST /api/mcp`). Both discovery endpoints are `@PublicPage` + `@CORS` annotated.

#### Scenario: MCP discovery returns tiered API documentation
- **GIVEN** OpenRegister is running with registers and schemas
- **WHEN** `GET /api/mcp/v1/discover` is called without authentication
- **THEN** the response SHALL return HTTP 200 with a JSON object describing available capabilities (registers, schemas, objects)
- **AND** the response SHALL be LLM-friendly with structured descriptions

#### Scenario: MCP capability-specific discovery
- **GIVEN** a valid capability name (e.g., `registers`, `schemas`, `objects`)
- **WHEN** `GET /api/mcp/v1/discover/{capability}` is called
- **THEN** the response SHALL return detailed API documentation for that specific capability

#### Scenario: MCP discovery with invalid capability returns 404
- **GIVEN** a non-existent capability name
- **WHEN** `GET /api/mcp/v1/discover/nonexistent` is called
- **THEN** the response SHALL return HTTP 404

#### Scenario: MCP standard protocol handles JSON-RPC requests
- **GIVEN** the MCP server endpoint at `POST /api/mcp`
- **WHEN** a valid JSON-RPC 2.0 request is sent (e.g., `{"jsonrpc": "2.0", "method": "initialize", "params": {}, "id": 1}`)
- **THEN** the response SHALL return a valid JSON-RPC 2.0 response with `jsonrpc`, `result`, and `id` fields

#### Scenario: MCP tools/list returns available tools
- **GIVEN** an initialized MCP session
- **WHEN** `{"jsonrpc": "2.0", "method": "tools/list", "id": 2}` is sent
- **THEN** the response SHALL list available tools (registers, schemas, objects) with their parameter schemas

#### Scenario: MCP tools/call executes a tool action
- **GIVEN** an initialized MCP session and existing registers
- **WHEN** `{"jsonrpc": "2.0", "method": "tools/call", "params": {"name": "registers", "arguments": {"action": "list"}}, "id": 3}` is sent
- **THEN** the response SHALL return the list of registers

#### Scenario: MCP invalid JSON-RPC returns error
- **GIVEN** the MCP server endpoint
- **WHEN** an invalid JSON-RPC request is sent (missing `jsonrpc` field, invalid method)
- **THEN** the response SHALL return a JSON-RPC error response with appropriate error code (-32600 Invalid Request, -32601 Method not found, -32700 Parse error)

### Requirement: Webhook delivery and lifecycle testing

The Webhooks controller exposes 11 routes for webhook management. Tests SHALL cover the complete lifecycle from creation through triggering and log inspection.

#### Scenario: Webhook CRUD lifecycle
- **GIVEN** valid webhook data with a target URL and event filter
- **WHEN** `POST /api/webhooks` is called
- **THEN** the webhook SHALL be created with HTTP 201
- **AND WHEN** `GET /api/webhooks/{id}` is called
- **THEN** the webhook details SHALL be returned
- **AND WHEN** `PUT /api/webhooks/{id}` is called with updated filters
- **THEN** the webhook SHALL be updated
- **AND WHEN** `DELETE /api/webhooks/{id}` is called
- **THEN** the webhook SHALL be deleted with HTTP 200

#### Scenario: Webhook test delivery
- **GIVEN** a created webhook with a valid URL
- **WHEN** `POST /api/webhooks/{id}/test` is called
- **THEN** a test delivery SHALL be attempted
- **AND** the response SHALL indicate delivery success or failure

#### Scenario: Webhook event listing
- **GIVEN** the webhooks system is active
- **WHEN** `GET /api/webhooks/events` is called
- **THEN** the response SHALL list all available event types that can be subscribed to (e.g., `object.created`, `object.updated`, `object.deleted`, `schema.created`, etc.)

#### Scenario: Webhook delivery logs
- **GIVEN** a webhook that has been triggered by an object creation
- **WHEN** `GET /api/webhooks/{id}/logs` is called
- **THEN** the response SHALL return delivery log entries with status, timestamp, response code, and payload
- **AND WHEN** `GET /api/webhooks/{id}/logs/stats` is called
- **THEN** the response SHALL return aggregated delivery statistics

#### Scenario: Webhook log retry
- **GIVEN** a webhook delivery that failed (logged with non-2xx response)
- **WHEN** `POST /api/webhooks/logs/{logId}/retry` is called
- **THEN** the delivery SHALL be re-attempted and a new log entry SHALL be created

#### Scenario: Webhook triggered by object mutation
- **GIVEN** a webhook subscribed to `object.created` events for a specific register/schema
- **WHEN** `POST /api/objects/{register}/{schema}` creates a new object
- **THEN** the webhook delivery log SHALL show a new entry with the created object payload
- **AND** the delivery SHALL use CloudEvents 1.0 format with `specversion`, `type`, `source`, `id`, `time`, and `data` fields

### Requirement: Multi-tenancy isolation testing

With multi-tenancy enabled, organisation-scoped data SHALL be strictly isolated. Tests SHALL verify that users in Organisation A cannot see or modify Organisation B's data.

#### Scenario: Objects isolated by organisation
- **GIVEN** multi-tenancy is enabled via `PUT /api/settings/multitenancy`
- **AND** two organisations exist (OrgA, OrgB)
- **AND** each organisation has objects in the same register/schema
- **WHEN** a user in OrgA calls `GET /api/objects/{register}/{schema}`
- **THEN** only OrgA's objects SHALL be returned
- **AND** OrgB's objects SHALL NOT appear in the results

#### Scenario: Cross-organisation object access blocked
- **GIVEN** multi-tenancy is enabled
- **AND** an object belongs to OrgA
- **WHEN** a user in OrgB calls `GET /api/objects/{register}/{schema}/{id}` for that object
- **THEN** the response SHALL return HTTP 404 (not 403, to avoid revealing the object exists)

#### Scenario: Organisation switching updates data scope
- **GIVEN** a user who is a member of both OrgA and OrgB
- **WHEN** the user calls `POST /api/organisations/{orgB-uuid}/set-active`
- **AND THEN** calls `GET /api/objects/{register}/{schema}`
- **THEN** the results SHALL reflect OrgB's data, not OrgA's

#### Scenario: Admin can view cross-organisation data
- **GIVEN** multi-tenancy is enabled
- **WHEN** an admin user calls list endpoints
- **THEN** the admin SHALL see data across all organisations (unless organisation scope is explicitly set)

### Requirement: Performance baseline tests with response time thresholds

Newman tests SHALL include response time assertions to detect performance regressions. Thresholds are based on documented baselines from the existing performance test collection.

#### Scenario: Single object retrieval under 500ms
- **GIVEN** an existing object
- **WHEN** `GET /api/objects/{register}/{schema}/{id}` is called
- **THEN** the response time SHALL be under 500ms
- **AND** the response SHALL return HTTP 200

#### Scenario: List endpoint with 10 items under 2 seconds
- **GIVEN** a register/schema with 10+ objects
- **WHEN** `GET /api/objects/{register}/{schema}?_limit=10` is called
- **THEN** the response time SHALL be under 2000ms

#### Scenario: List with extends under 5 seconds for 10 items
- **GIVEN** objects with relationship extends configured
- **WHEN** `GET /api/objects/{register}/{schema}?_limit=10&_extend=true` is called
- **THEN** the response time SHALL be under 5000ms (per the performance test baseline: "10 items + extends: < 1s" target, 5s timeout)

#### Scenario: Search endpoint under 3 seconds
- **GIVEN** indexed objects in the search backend
- **WHEN** `GET /api/search?q=test` is called
- **THEN** the response time SHALL be under 3000ms

#### Scenario: Settings endpoints under 1 second
- **GIVEN** any settings controller
- **WHEN** `GET /api/settings/*` is called
- **THEN** the response time SHALL be under 1000ms

### Requirement: Settings controller coverage (12 controllers, ~90 routes)

The 12 Settings sub-controllers expose configuration endpoints that affect system behavior. Every settings domain SHALL have GET (read), PUT/PATCH (update), and action endpoints (test, warmup, clear) tested.

#### Scenario: Solr settings lifecycle
- **GIVEN** the SolrSettings, SolrOperations, and SolrManagement controllers
- **WHEN** settings operations are performed
- **THEN** tests SHALL cover: `GET /api/settings/solr` (read), `PUT /api/settings/solr` (update), `POST /api/settings/solr/test` (test connection), `POST /api/settings/solr/warmup` (warmup index), `POST /api/settings/solr/memory-prediction`, `POST /api/settings/solr/test-schema-mapping`, `POST /api/settings/solr/inspect`, `POST /api/solr/manage/{operation}`, `POST /api/solr/setup`, `GET /api/solr/fields`, `POST /api/solr/fields/create-missing`, `POST /api/solr/fields/fix-mismatches`, `DELETE /api/solr/fields/{fieldName}`, `GET /api/solr/collections`, `POST /api/solr/collections`, `DELETE /api/solr/collections/{name}`, `POST /api/solr/collections/{name}/clear`, `POST /api/solr/collections/{name}/reindex`, `GET /api/solr/configsets`, `POST /api/solr/configsets`, `DELETE /api/solr/configsets/{name}`, `POST /api/solr/collections/copy`, `PUT /api/solr/collections/assignments`, `GET /api/solr/dashboard/stats`, `GET /api/settings/solr-info`, `GET /api/settings/solr-facet-config`, `POST /api/settings/solr-facet-config`, `GET /api/solr/discover-facets`, `GET /api/solr/facet-config`, `POST /api/solr/facet-config`

#### Scenario: LLM settings lifecycle
- **GIVEN** the LlmSettings controller
- **WHEN** settings operations are performed
- **THEN** tests SHALL cover: `GET /api/settings/llm`, `POST /api/settings/llm`, `PATCH /api/settings/llm`, `PUT /api/settings/llm`, `POST /api/vectors/test-embedding`, `POST /api/llm/test-chat`, `GET /api/llm/ollama-models`, `GET /api/vectors/check-model-mismatch`, `DELETE /api/vectors/clear-all`

#### Scenario: Cache settings lifecycle
- **GIVEN** the CacheSettings controller
- **WHEN** cache operations are performed
- **THEN** tests SHALL cover: `GET /api/settings/cache` (stats), `DELETE /api/settings/cache` (clear), `POST /api/settings/cache/warmup-names`, `GET /api/settings/cache/warmup-interval`, `PUT /api/settings/cache/warmup-interval`, `DELETE /api/settings/cache/appstore`

#### Scenario: Configuration settings (RBAC, multi-tenancy, organisation, retention, objects)
- **GIVEN** the ConfigurationSettings controller
- **WHEN** configuration operations are performed
- **THEN** tests SHALL cover: `GET/PATCH/PUT /api/settings/rbac`, `GET/PATCH/PUT /api/settings/multitenancy`, `GET/PATCH/PUT /api/settings/organisation`, `GET/PATCH/PUT /api/settings/retention`, `GET /api/settings/objects`, `POST/PATCH/PUT /api/settings/objects/vectorize`

#### Scenario: File settings lifecycle
- **GIVEN** the FileSettings controller
- **WHEN** file settings operations are performed
- **THEN** tests SHALL cover: `GET/PATCH/PUT /api/settings/files`, `GET /api/settings/files/stats`, `POST /api/settings/files/test-dolphin`, `POST /api/settings/files/test-presidio`, `POST /api/settings/files/test-openanonymiser`, `POST /api/solr/warmup/files`, `POST /api/solr/files/{fileId}/index`, `POST /api/solr/files/reindex`, `GET /api/solr/files/stats`

#### Scenario: Security, validation, n8n, and API token settings
- **GIVEN** the SecuritySettings, ValidationSettings, N8nSettings, and ApiTokenSettings controllers
- **WHEN** their operations are performed
- **THEN** tests SHALL cover: `POST /api/settings/security/unblock-ip`, `POST /api/settings/security/unblock-user`, `POST /api/settings/security/unblock`, `POST /api/settings/validate-all-objects`, `POST /api/settings/mass-validate`, `POST /api/settings/mass-validate/memory-prediction`, `GET /api/settings/n8n`, `POST/PATCH/PUT /api/settings/n8n`, `POST /api/settings/n8n/test`, `POST /api/settings/n8n/initialize`, `GET /api/settings/n8n/workflows`, `GET /api/settings/api-tokens`, `POST /api/settings/api-tokens`, `POST /api/settings/api-tokens/test/github`, `POST /api/settings/api-tokens/test/gitlab`

### Requirement: File operations testing (upload, download, extraction, search, anonymization)

File operations span multiple controllers (FilesController, FileExtractionController, FileTextController, FileSearchController) with routes nested under objects and standalone. Tests SHALL cover the complete file lifecycle from upload to text extraction to search.

#### Scenario: File upload and download via object attachment
- **GIVEN** an existing object
- **WHEN** `POST /api/objects/{register}/{schema}/{id}/files` is called with file data
- **THEN** the file SHALL be created with HTTP 201
- **AND WHEN** `GET /api/objects/{register}/{schema}/{id}/files` is called
- **THEN** the file list SHALL include the uploaded file
- **AND WHEN** `GET /api/objects/{register}/{schema}/{id}/files/{fileId}` is called
- **THEN** the file metadata SHALL be returned
- **AND WHEN** `GET /api/files/{fileId}/download` is called
- **THEN** the file content SHALL be returned with appropriate Content-Type

#### Scenario: File publish and depublish
- **GIVEN** an uploaded file attached to an object
- **WHEN** `POST /api/objects/{register}/{schema}/{id}/files/{fileId}/publish` is called
- **THEN** the file SHALL be marked as published
- **AND WHEN** `POST /api/objects/{register}/{schema}/{id}/files/{fileId}/depublish` is called
- **THEN** the file SHALL be depublished

#### Scenario: File text extraction
- **GIVEN** an uploaded file (PDF, DOCX, or TXT)
- **WHEN** `POST /api/files/{fileId}/extract` is called
- **THEN** text SHALL be extracted from the file
- **AND WHEN** `GET /api/files/{fileId}/text` is called
- **THEN** the extracted text SHALL be returned
- **AND WHEN** `DELETE /api/files/{fileId}/text` is called
- **THEN** the extracted text SHALL be removed

#### Scenario: File search (keyword, semantic, hybrid)
- **GIVEN** files with extracted and indexed text
- **WHEN** `POST /api/search/files/keyword` is called with a search query
- **THEN** matching files SHALL be returned
- **AND WHEN** `POST /api/search/files/semantic` is called
- **THEN** semantically similar files SHALL be returned
- **AND WHEN** `POST /api/search/files/hybrid` is called
- **THEN** results from both keyword and semantic search SHALL be combined

#### Scenario: File anonymization
- **GIVEN** a file with extracted text containing PII
- **WHEN** `POST /api/files/{fileId}/anonymize` is called
- **THEN** detected PII entities SHALL be replaced with placeholders

#### Scenario: GDPR entities management
- **GIVEN** files with detected PII entities
- **WHEN** `GET /api/entities` is called
- **THEN** all detected entities SHALL be listed
- **AND WHEN** `GET /api/entities/types` and `GET /api/entities/categories` are called
- **THEN** the available entity types and categories SHALL be returned
- **AND WHEN** `GET /api/entities/stats` is called
- **THEN** entity detection statistics SHALL be returned

### Requirement: Concurrent request testing for race conditions

API endpoints that modify shared state SHALL be tested with concurrent requests to verify data integrity under load.

#### Scenario: Concurrent object updates do not corrupt data
- **GIVEN** an existing object
- **WHEN** two simultaneous `PUT /api/objects/{register}/{schema}/{id}` requests are sent with different field values
- **THEN** one SHALL succeed with HTTP 200 and the other SHALL either succeed or return HTTP 409 (conflict)
- **AND** the final object state SHALL be consistent (no partial field mix from both requests)

#### Scenario: Locked object prevents concurrent modification
- **GIVEN** an object that has been locked via `POST /api/objects/{register}/{schema}/{id}/lock`
- **WHEN** a second user attempts `PUT /api/objects/{register}/{schema}/{id}`
- **THEN** the response SHALL return HTTP 409 or 423 (Locked)
- **AND** the lock holder can still modify the object
- **AND WHEN** `POST /api/objects/{register}/{schema}/{id}/unlock` is called by the lock holder
- **THEN** other users can modify the object again

#### Scenario: Concurrent bulk operations handle partial failures
- **GIVEN** a bulk save request with 50 objects
- **WHEN** `POST /api/bulk/{register}/{schema}/save` is called
- **THEN** the response SHALL report which objects succeeded and which failed
- **AND** successfully saved objects SHALL be queryable immediately

### Requirement: Search and advanced filtering tests (full-text, faceted, vector)

Search functionality spans multiple controllers (SearchController, SolrController, FileSearchController). Tests SHALL cover basic keyword search, faceted search, semantic/vector search, and hybrid search.

#### Scenario: Basic keyword search
- **GIVEN** indexed objects
- **WHEN** `GET /api/search?q=keyword` is called
- **THEN** matching objects SHALL be returned ranked by relevance

#### Scenario: Semantic vector search
- **GIVEN** objects with vector embeddings
- **WHEN** `POST /api/search/semantic` is called with a natural language query
- **THEN** semantically similar objects SHALL be returned

#### Scenario: Hybrid search combines keyword and semantic
- **GIVEN** indexed objects with embeddings
- **WHEN** `POST /api/search/hybrid` is called
- **THEN** results SHALL combine keyword and semantic relevance scores

#### Scenario: Vector statistics
- **GIVEN** objects with varying vectorization states
- **WHEN** `GET /api/vectors/stats` is called
- **THEN** the response SHALL include counts of vectorized vs non-vectorized objects

#### Scenario: Test vector embedding
- **GIVEN** LLM/embedding settings are configured
- **WHEN** `POST /api/vectors/test` is called with sample text
- **THEN** the response SHALL return a vector embedding array

### Requirement: CI integration with automated Newman runs and PCOV coverage

Newman tests SHALL run automatically in the CI pipeline for every pull request. Coverage SHALL be collected via PCOV during Newman runs and reported alongside unit test coverage.

#### Scenario: PCOV prepend script collects coverage per HTTP request
- **GIVEN** a PHP prepend script (`tests/integration/coverage-prepend.php`) that starts PCOV coverage collection on each request
- **AND** a shutdown function that writes coverage data to a `.cov` file
- **WHEN** Newman sends API requests to the Nextcloud instance
- **THEN** each request SHALL generate a coverage file in `/tmp/openregister-coverage/`
- **AND** after the test run, `phpcov merge --clover=coverage/api-clover.xml /tmp/openregister-coverage/` SHALL produce a combined report

#### Scenario: Docker container configured for API coverage collection
- **GIVEN** the Nextcloud Docker container
- **WHEN** running integration tests with coverage enabled
- **THEN** `php.ini` SHALL have `auto_prepend_file` set to the coverage prepend script
- **AND** PCOV extension SHALL be enabled (`pcov.enabled=1`, `pcov.directory=/var/www/html/custom_apps/openregister/lib`)
- **AND** the coverage directory SHALL be writable by the web server user

#### Scenario: Dual coverage reporting (unit + API)
- **GIVEN** unit test coverage in `coverage/unit-clover.xml`
- **AND** API test coverage in `coverage/api-clover.xml`
- **WHEN** both reports are merged with `phpcov merge`
- **THEN** a combined `coverage/clover.xml` SHALL show total project coverage
- **AND** the combined coverage SHALL be higher than either individual report

#### Scenario: Newman runs against all database/storage combinations in CI
- **GIVEN** the CI pipeline matrix (PostgreSQL x Normal storage, PostgreSQL x MagicMapper, MySQL x Normal storage, MySQL x MagicMapper)
- **WHEN** Newman collections run in each matrix cell
- **THEN** all tests SHALL pass in all 4 combinations
- **AND** failures in any combination SHALL block the PR merge

#### Scenario: Coverage regression blocks PR merge
- **GIVEN** the current API coverage baseline stored in `.coverage-baseline`
- **WHEN** a PR reduces API route coverage (e.g., adds new routes without tests)
- **THEN** the coverage guard SHALL fail with a descriptive message
- **AND** the PR SHALL be blocked from merging

#### Scenario: Newman collections run in sequence with shared state
- **GIVEN** multiple Newman collections (crud, settings, files, webhooks, search, auth, advanced)
- **WHEN** the CI pipeline runs them
- **THEN** collections SHALL run in dependency order (crud first to create base resources, then dependent collections)
- **AND** collection variables (register IDs, schema IDs, object UUIDs) SHALL be passed between runs

### Requirement: Test data setup and teardown for idempotent test runs

Every Newman collection SHALL be fully idempotent -- runnable multiple times in sequence without failure. Tests SHALL create their own test data at the start and clean up at the end.

#### Scenario: Collection creates test fixtures in setup folder
- **GIVEN** a Newman collection for webhook testing
- **WHEN** the collection runs
- **THEN** the first folder ("Setup") SHALL create all required resources (register, schema, objects, webhook)
- **AND** IDs/UUIDs SHALL be stored in collection variables for use by subsequent requests

#### Scenario: Collection deletes all created resources in teardown folder
- **GIVEN** a Newman collection that has completed its test scenarios
- **WHEN** the last folder ("Teardown") runs
- **THEN** all resources created during the test SHALL be deleted in reverse order (objects first, then schemas, then registers)
- **AND** delete requests for already-deleted resources SHALL not cause test failure (handle 404 gracefully)

#### Scenario: Collection is re-runnable without data conflicts
- **GIVEN** a Newman collection has been run once
- **WHEN** it is run again immediately
- **THEN** all tests SHALL pass without UUID conflicts or duplicate data errors

### Requirement: Postman test script patterns with schema validation

Every Postman request SHALL have a `Tests` script that validates the response. Complex responses SHALL be validated against JSON schemas embedded in the test script.

#### Scenario: Every request asserts HTTP status code
- **GIVEN** any request in a Newman collection
- **THEN** the test script SHALL assert the expected HTTP status code (e.g., `pm.response.to.have.status(200)`)

#### Scenario: Create requests store generated IDs
- **GIVEN** a POST request that creates a resource
- **WHEN** the response returns with a UUID or numeric ID
- **THEN** the test script SHALL extract and store it in a collection variable (e.g., `pm.collectionVariables.set("registerId", jsonData.id)`)

#### Scenario: List responses validated for structure
- **GIVEN** a GET request that returns a list
- **THEN** the test script SHALL verify: the response is valid JSON, the result is an array or has a `results` key containing an array, pagination metadata is present when applicable

#### Scenario: Error responses validated for message field
- **GIVEN** a request expected to return an error (4xx/5xx)
- **THEN** the test script SHALL verify: the response contains a `message` field, the message is a non-empty string, no stack traces or file paths appear in the response body

## Newman Collection Organization

### Requirement: Modular collection structure aligned with API domains

Tests SHALL be organized in separate Postman collections by domain, stored consistently in `tests/integration/`.

| Collection | Routes Covered | Est. Requests | Status |
|------------|----------------|---------------|--------|
| `openregister-crud.postman_collection.json` | Core CRUD (Registers, Schemas, Objects, Organisations, Views, AuditTrails, Deleted) | ~200 | Exists |
| `openregister-referential-integrity.postman_collection.json` | Cascading deletes, dependency checks | ~30 | Exists |
| `magic-mapper-import.postman_collection.json` | CSV/JSON import into magic mapper | ~15 | Exists |
| `openregister-settings.postman_collection.json` | All 12 settings controllers (~90 routes) | ~150 | New |
| `openregister-files.postman_collection.json` | File upload/download, text extraction, anonymization, GDPR entities | ~70 | New |
| `openregister-webhooks.postman_collection.json` | Webhook CRUD, delivery, logs, retry, workflow engines | ~50 | New |
| `openregister-search.postman_collection.json` | Keyword, semantic, hybrid search, vector operations, search trails | ~60 | New |
| `openregister-auth.postman_collection.json` | RBAC enforcement, multi-tenancy isolation, organisation management | ~50 | New |
| `openregister-graphql.postman_collection.json` | GraphQL queries, mutations, introspection, subscriptions | ~40 | New (upgrade from `tests/postman/`) |
| `openregister-mcp.postman_collection.json` | MCP discovery, JSON-RPC protocol, tool calls | ~30 | New |
| `openregister-advanced.postman_collection.json` | Dashboard, configurations, chat/conversations, agents, endpoints, bulk, OAS, names, tags, notes, tasks, user, migration, tables, health, metrics, heartbeat | ~100 | New |
| `openregister-performance.postman_collection.json` | Response time baselines, load scenarios | ~20 | Exists (move from `tests/performance/`) |

**Total: ~815 requests across 12 collections** (245 existing + ~570 new)

## Composer Scripts

### Requirement: Add API coverage commands to composer.json

```json
{
  "test:api": "Run all Newman collections via run-newman-tests.sh",
  "test:api:crud": "Run core CRUD Newman collection only",
  "test:api:coverage": "Run Newman tests with PCOV coverage collection enabled",
  "coverage:api": "Generate API coverage report from collected .cov files via phpcov merge",
  "coverage:combined": "Merge unit + API coverage into combined report",
  "coverage:api:check": "Validate API coverage meets baseline threshold"
}
```

## Estimated Scope

| Category | New Requests | New Collections |
|----------|-------------|-----------------|
| Expand existing CRUD collection (error paths, pagination, auth matrix) | ~50 | 0 |
| Settings endpoints (12 controllers) | ~150 | 1 |
| File operations (upload, extraction, search, anonymization) | ~70 | 1 |
| Webhooks & workflow engines | ~50 | 1 |
| Search & filtering (keyword, semantic, hybrid, vectors) | ~60 | 1 |
| Authorization & multi-tenancy | ~50 | 1 |
| GraphQL (upgrade from postman/) | ~40 | 1 |
| MCP (discovery + JSON-RPC) | ~30 | 1 |
| Advanced features (dashboard, config, chat, agents, bulk, etc.) | ~100 | 1 |
| Performance baselines | ~20 | 0 (consolidate existing) |
| Coverage infrastructure (PCOV, scripts, CI) | -- | 0 |
| **Total** | **~620 new requests** | **8 new collections** |

### Current Implementation Status
- **Implemented:**
  - Core CRUD collection with ~199 tests per storage mode covering registers, schemas, objects, organisations
  - Referential integrity collection testing cascading deletes
  - Magic mapper import collection for CSV import testing
  - Additional test collections in `tests/postman/` (GraphQL, CRUD, relations)
  - Agent CMS testing collection in `tests/newman/`
  - Performance test collection in `tests/performance/`
  - Dual-storage runner script (`run-dual-storage-tests.sh`)
  - CI pipeline in `.github/workflows/quality.yml` with `enable-newman: false` (delegated to database-tests workflow)
  - Coverage guard integration (`enable-coverage-guard: true` in quality.yml)
- **NOT implemented:**
  - PCOV coverage collection during Newman/API test runs (no `coverage-prepend.php`)
  - Coverage merge or dual reporting (unit + API)
  - Settings endpoint collections (90 routes untested)
  - File operations, webhook lifecycle, search, auth, GraphQL, MCP, and advanced feature test collections
  - Multi-tenancy isolation tests via Newman
  - Concurrent request tests
  - Performance regression baselines in CI
  - Composer scripts for API coverage commands
- **Partial:**
  - Core CRUD resources have ~27% route coverage; most resource groups at 0%
  - GraphQL tests exist in `tests/postman/` but not integrated into CI Newman runs
  - Performance tests exist but not integrated into CI pipeline

### Standards & References
- Newman/Postman collection format v2.1
- OpenAPI 3.0 (routes should align with OAS spec generated by `OasService` via `GET /api/registers/{id}/oas`)
- PHP PCOV extension for code coverage
- PHPUnit clover XML format for coverage reports
- CloudEvents 1.0 specification for webhook delivery format
- JSON-RPC 2.0 specification for MCP standard protocol
- GraphQL specification (June 2018) for query/mutation/subscription testing
- NL API Design Rules (API-01 through API-58) for pagination, filtering, sorting, error format, and HATEOAS compliance
- Nextcloud CI best practices for app testing
- Related spec: `unit-test-coverage` (complementary -- covers PHP-level unit testing with PHPUnit)

### Specificity Assessment
- The spec is highly specific: it lists every route from `appinfo/routes.php` grouped by controller, with exact endpoints per scenario, exact HTTP verbs, and concrete test counts.
- Coverage infrastructure is well-defined with PCOV prepend/merge approach and CI integration points.
- The 12 Settings controllers are enumerated with every route explicitly listed.
- Public endpoints (`@PublicPage`) and CORS endpoints (`@CORS`) are identified from source code annotations for authentication matrix testing.
- Open questions:
  - Should the coverage target be 100% route coverage or 95% (allowing some admin-only debug routes to be excluded)?
  - What webhook target URL should be used in CI for delivery testing? (Options: httpbin.org, local echo server, or mock server)
  - Should GraphQL subscription (SSE) tests run in Newman or require a separate tool? (Newman has limited SSE support)
  - Priority ordering: which collections should be built first? (Recommendation: Settings > Files > Webhooks > Search > Auth > GraphQL > MCP > Advanced)
