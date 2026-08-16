# Tasks: MCP Discovery

- [x] Implement: Tier 1 Discovery Catalog
- [x] Implement: Tier 2 Capability Detail with Live Data
- [x] Implement: Capability Coverage
- [x] Implement: Token Efficiency
- [x] Implement: MCP Standard Protocol Endpoint (JSON-RPC 2.0)
- [x] Implement: MCP Session Management
- [x] Implement: MCP Tool Definitions <!-- Now satisfied via IMcpToolProvider built-ins (RegistersToolProvider/SchemasToolProvider/ObjectsToolProvider) after the ai-chat-companion-orchestrator refactor; observable tools/list + inputSchema behaviour unchanged. -->
- [x] Implement: MCP Resource Definitions
- [x] Implement: MCP Capabilities Negotiation
- [x] Implement: JSON-RPC Notification Handling
- [x] Implement: MCP Authentication via Nextcloud
- [x] Implement: MCP Audit Logging
- [x] Implement: Multi-Register Tool Scoping <!-- Enforced in ObjectsToolProvider::invokeTool() (register+schema required, setRegister/setSchema before each call). -->
