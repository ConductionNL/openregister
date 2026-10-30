# ai-mcp delta

## ADDED Requirements

### Requirement: REQ-ATTR-006 — A curated attribute tool declares its reach

`#[McpTool]` MUST accept an optional `reach` parameter whose value, when set, is one of `ToolReachResolver::ORDER` (`self`, `user`, `instance`, `external`). `AttributeToolScanner` MUST reject a declared `reach` outside that vocabulary at scan time (logged, the offending tool skipped, sibling tools unaffected), and MUST forward a valid declared `reach` into the descriptor under `ToolReachResolver::REACH_KEY` only when the author set it, never inferred. `AttributeToolProvider::getTools()` MUST carry the key through unchanged, so the value reaches `ToolReachResolver::resolve()` on both the JSON-RPC and the chat/facade surface. An undeclared `reach` on a two-segment curated id MUST still resolve to `external` (agent-capability-reach), and a declared `reach` MUST NOT remove a tool from the gated set that `ToolGrantResolver::isWriteOrDestructive()` already gates.

#### Scenario: A declared reach appears in the descriptor
- **GIVEN** app `dossiq` exposes `#[McpTool(readOnlyHint: true, scope: 'read', reach: 'user')] getWorkload(string $userId)`
- **WHEN** `AttributeToolScanner` builds the descriptor
- **THEN** the descriptor MUST include `reach: 'user'`
@e2e exclude Descriptor-shape assertion with no UI surface; asserted by AttributeToolScannerTest.

#### Scenario: An undeclared reach stays omitted and fails closed
- **GIVEN** a method `#[McpTool(scope: 'update')] reassignCase(string $id)` on app `dossiq`
- **WHEN** the descriptor is built and its reach resolved
- **THEN** the descriptor MUST NOT carry a `reach` key
- **AND** `ToolReachResolver::resolve('dossiq.reassignCase', $descriptor)` MUST return `external`
@e2e exclude Fail-closed default reachable only by constructing a descriptor; asserted by unit tests.

#### Scenario: An unrecognised reach is rejected at scan time
- **GIVEN** a method `#[McpTool(reach: 'everyone')]`
- **WHEN** `AttributeToolScanner` scans the declaring class
- **THEN** no tool MUST be registered for that method
- **AND** a warning MUST be logged naming the invalid `reach`
@e2e exclude Malformed-attribute path with no UI surface; asserted by AttributeToolScannerTest.

#### Scenario: A declared reach arrives at the resolver on both surfaces
- **GIVEN** the `getWorkload` descriptor is registered via `AttributeToolProvider`
- **WHEN** it is read from `McpToolsService::listTools()` and from `ToolRegistryFacade::listTools()`
- **THEN** `ToolReachResolver::resolve()` MUST return `user` for both entries
- **AND** `ToolGrantResolver::requiresGrant()` MUST return false for a tool that is also read-only
@e2e exclude Wiring assertion through the real registry, bridge and facade chain; asserted by AttributeToolDualSurfaceTest.

