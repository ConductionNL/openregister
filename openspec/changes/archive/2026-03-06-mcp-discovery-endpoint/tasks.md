# Tasks: mcp-discovery-endpoint

## 1. Routes & Controller Scaffold

### Task 1.1: Register MCP routes
- **spec_ref**: `specs/mcp-discovery/spec.md#requirement-versioned-url-path`
- **files**: `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN routes.php is updated WHEN the app loads THEN `/api/mcp/v1/discover` and `/api/mcp/v1/discover/{capability}` are registered
- [x] Implement
- [x] Test

### Task 1.2: Create McpController with discover() and discoverCapability()
- **spec_ref**: `specs/mcp-discovery/spec.md#requirement-tier-1-discovery-catalog`, `specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail`
- **files**: `openregister/lib/Controller/McpController.php`
- **acceptance_criteria**:
  - GIVEN McpController exists WHEN `GET /api/mcp/v1/discover` is called THEN it returns JSON with capabilities array
  - GIVEN McpController exists WHEN `GET /api/mcp/v1/discover/objects` is called with auth THEN it returns detailed capability JSON
  - GIVEN an unauthenticated request WHEN `GET /api/mcp/v1/discover` is called THEN it succeeds (public)
  - GIVEN an unauthenticated request WHEN `GET /api/mcp/v1/discover/objects` is called THEN it returns 401
- [x] Implement
- [x] Test

## 2. Discovery Service

### Task 2.1: Create McpDiscoveryService with Tier 1 catalog
- **spec_ref**: `specs/mcp-discovery/spec.md#requirement-tier-1-discovery-catalog`, `specs/mcp-discovery/spec.md#requirement-capability-coverage`, `specs/mcp-discovery/spec.md#requirement-token-efficiency`
- **files**: `openregister/lib/Service/McpDiscoveryService.php`
- **acceptance_criteria**:
  - GIVEN the service is instantiated WHEN `getCatalog()` is called THEN it returns a JSON-serializable array with version, name, authentication, base_url, and capabilities
  - GIVEN the capabilities array WHEN inspected THEN it contains entries for: registers, schemas, objects, search, files, audit, bulk, webhooks, chat, views
  - GIVEN the Tier 1 JSON WHEN serialized THEN it is under 3000 characters
- [x] Implement
- [x] Test

### Task 2.2: Add Tier 2 capability builders with live data
- **spec_ref**: `specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail`, `specs/mcp-discovery/spec.md#requirement-live-data-in-tier-2`
- **files**: `openregister/lib/Service/McpDiscoveryService.php`
- **acceptance_criteria**:
  - GIVEN the service WHEN `getCapabilityDetail('objects')` is called THEN it returns endpoints array with method, path, description, parameters AND a context object with live register/schema data
  - GIVEN the service WHEN `getCapabilityDetail('schemas')` is called THEN context includes schema list with id, title, property_count
  - GIVEN the service WHEN `getCapabilityDetail('nonexistent')` is called THEN it returns null (controller handles 404)
- [x] Implement
- [x] Test

## 3. Error Handling & CORS

### Task 3.1: Handle unknown capability with 404 + available list
- **spec_ref**: `specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail` (unknown capability scenario)
- **files**: `openregister/lib/Controller/McpController.php`
- **acceptance_criteria**:
  - GIVEN an authenticated request WHEN `GET /api/mcp/v1/discover/nonexistent` is called THEN response is 404 with `error` and `available` array
- [x] Implement
- [x] Test

### Task 3.2: CORS handled by @CORS annotation
- **spec_ref**: `specs/mcp-discovery/spec.md#requirement-tier-1-discovery-catalog` (CORS scenario)
- **files**: `openregister/lib/Controller/McpController.php`
- **acceptance_criteria**:
  - GIVEN the controller uses @CORS annotation WHEN a cross-origin request is made THEN CORS headers are included
- [x] Implement (via @CORS annotation on both controller methods)
- [x] Test

## 4. Integration Testing with Claude

### Task 4.1: Test Tier 1 discovery via curl
- **acceptance_criteria**:
  - GIVEN the endpoint is deployed WHEN `curl http://localhost:8080/index.php/apps/openregister/api/mcp/v1/discover` is called THEN it returns the catalog JSON without auth
- [x] Test

### Task 4.2: Test Tier 2 with live data via curl
- **acceptance_criteria**:
  - GIVEN registers and schemas exist WHEN `curl -u admin:admin http://localhost:8080/index.php/apps/openregister/api/mcp/v1/discover/objects` is called THEN it returns endpoints + live register/schema context
- [x] Test

### Task 4.3: End-to-end agent test — Claude uses discovery to perform API operations
- **acceptance_criteria**:
  - GIVEN Claude calls Tier 1 WHEN it reads the capabilities THEN it can pick the right capability area
  - GIVEN Claude calls Tier 2 for "objects" WHEN it reads the endpoints + context THEN it can construct valid API calls to list/create/update objects
- [x] Test

## Verification
- [x] All tasks checked off
- [x] PHPCS passes (0 errors) on new files
- [x] PHPMD passes on new files
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements
