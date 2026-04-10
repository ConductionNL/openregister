# MCP Discovery

## Problem
Provides AI agents and MCP-compatible clients with two complementary interfaces to the OpenRegister platform: a tiered REST-based discovery API for token-efficient API exploration, and a full MCP standard protocol endpoint implementing JSON-RPC 2.0 over Streamable HTTP for native tool and resource access. Together these interfaces allow any LLM or MCP client to discover capabilities, establish sessions, and perform CRUD operations on registers, schemas, and objects without prior knowledge of the API surface.

## Proposed Solution
Implement MCP Discovery following the detailed specification. Key requirements include:
- Requirement: Tier 1 Discovery Catalog
- Requirement: Tier 2 Capability Detail with Live Data
- Requirement: Capability Coverage
- Requirement: Token Efficiency
- Requirement: MCP Standard Protocol Endpoint (JSON-RPC 2.0)

## Scope
This change covers all requirements defined in the mcp-discovery specification.

## Success Criteria
- Agent discovers available capabilities
- Capability entry structure
- Authentication object in discovery response
- CORS preflight for public discovery
- Internal server error handling
