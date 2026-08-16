# MCP Discovery Specification

## Purpose
Provides AI agents with a token-efficient, tiered discovery mechanism for the OpenRegister API. Tier 1 gives a compact catalog of capabilities; Tier 2 gives detailed endpoint docs with live data for a specific capability area.

## ADDED Requirements

### Requirement: Tier 1 Discovery Catalog
The system MUST expose a public endpoint at `/api/mcp/v1/discover` that returns a compact JSON catalog of all capability areas without requiring authentication.

#### Scenario: Agent discovers available capabilities
- GIVEN the MCP discovery endpoint is deployed
- WHEN an unauthenticated client sends `GET /api/mcp/v1/discover`
- THEN the response MUST be HTTP 200 with Content-Type `application/json`
- AND the response MUST include a `version` field with value `"1.0"`
- AND the response MUST include a `name` field identifying the application
- AND the response MUST include an `authentication` object describing how to authenticate
- AND the response MUST include a `base_url` field with the app's base path
- AND the response MUST include a `capabilities` array

#### Scenario: Capability entry structure
- GIVEN the discovery endpoint returns a capabilities array
- WHEN an agent reads a capability entry
- THEN each entry MUST contain `id` (kebab-case string), `name` (human-readable), `description` (one sentence), and `href` (absolute URL to Tier 2 detail)

#### Scenario: CORS preflight for public discovery
- GIVEN the discovery endpoint is public
- WHEN a browser or agent sends an OPTIONS preflight request
- THEN the response MUST include CORS headers allowing cross-origin access

### Requirement: Tier 2 Capability Detail
The system MUST expose an authenticated endpoint at `/api/mcp/v1/discover/{capability}` that returns detailed API documentation and live context data for the specified capability area.

#### Scenario: Agent drills into a specific capability
- GIVEN an authenticated client
- WHEN the client sends `GET /api/mcp/v1/discover/objects`
- THEN the response MUST be HTTP 200
- AND the response MUST include an `endpoints` array with method, path, description, and parameters for each endpoint
- AND the response MUST include a `context` object with live data (e.g., available registers and schemas with IDs and object counts)

#### Scenario: Unknown capability requested
- GIVEN an authenticated client
- WHEN the client sends `GET /api/mcp/v1/discover/nonexistent`
- THEN the response MUST be HTTP 404
- AND the response MUST include an `error` message
- AND the response MUST include an `available` array listing valid capability IDs

#### Scenario: Unauthenticated access to Tier 2
- GIVEN an unauthenticated client
- WHEN the client sends `GET /api/mcp/v1/discover/objects`
- THEN the response MUST be HTTP 401
- AND the response MUST include an `error` field explaining authentication is required

### Requirement: Versioned URL Path
The MCP discovery endpoints MUST use a versioned URL prefix `/api/mcp/v1/` to allow future protocol evolution without breaking existing agent integrations.

#### Scenario: Version prefix in all MCP routes
- GIVEN the MCP discovery feature is deployed
- WHEN routes are registered
- THEN all MCP-related routes MUST be under the `/api/mcp/v1/` prefix

### Requirement: Live Data in Tier 2
Tier 2 responses MUST include a `context` object containing live data from the system so that agents can immediately reference real entity IDs and names without additional lookup calls.

#### Scenario: Objects capability includes register and schema context
- GIVEN an authenticated client requests `/api/mcp/v1/discover/objects`
- WHEN the response is returned
- THEN the `context` object MUST include a `registers` array
- AND each register MUST include `id`, `title`, and a `schemas` array
- AND each schema MUST include `id`, `title`, and `object_count`

#### Scenario: Schemas capability includes schema list
- GIVEN an authenticated client requests `/api/mcp/v1/discover/schemas`
- WHEN the response is returned
- THEN the `context` object MUST include a `schemas` array with `id`, `title`, and `property_count` for each schema

### Requirement: Capability Coverage
The discovery catalog MUST cover at minimum these capability areas: registers, schemas, objects, search, files, audit, bulk, webhooks, chat, views.

#### Scenario: All core capabilities present
- GIVEN the discovery endpoint is called
- WHEN the capabilities array is returned
- THEN it MUST contain entries with IDs: `registers`, `schemas`, `objects`, `search`, `files`, `audit`, `bulk`, `webhooks`, `chat`, `views`

### Requirement: Token Efficiency
The Tier 1 response MUST be optimized for minimal token consumption by AI agents. Descriptions MUST be concise (one sentence each) and the total response SHOULD be under 500 tokens when serialized.

#### Scenario: Compact response size
- GIVEN the discovery endpoint is called
- WHEN the response is serialized to JSON
- THEN the total character count MUST be under 3000 characters (approximately 500 tokens)
