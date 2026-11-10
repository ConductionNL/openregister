## ADDED Requirements

### Requirement: REQ-ATTR-007 — A curated attribute tool forwards free-form annotations

`#[McpTool]` MUST accept an optional `annotations` map whose keys are non-empty strings and whose values are scalars. `AttributeToolScanner` MUST reject a malformed map at scan time (logged, the offending tool skipped, sibling tools unaffected) and MUST forward a non-empty map into the descriptor under `annotations`; an empty map MUST NOT be forwarded. `AttributeToolProvider::getTools()` and `McpProviderBridge::getFunctions()` MUST carry the key through unchanged, so a consumer reads the same map on the JSON-RPC surface and on `ToolRegistryFacade::listTools()`. OpenRegister MUST NOT interpret any mark: a mark is a claim, and grant resolution, reach and RBAC MUST NOT change because of one.

#### Scenario: A declared mark appears on both surfaces
- **GIVEN** app `dossiq` exposes `#[McpTool(scope: 'create', action: 'create', annotations: ['citizenIntake' => true])] fileCase(string $title)`
- **WHEN** the descriptor is read from `McpToolsService::listTools()` and from `ToolRegistryFacade::listTools()`
- **THEN** both entries MUST carry `annotations: {citizenIntake: true}`, `scope: 'create'` and `action: 'create'`
@e2e exclude Wiring assertion through the real registry, bridge and facade chain; asserted by AttributeToolDualSurfaceTest.

#### Scenario: No declaration, no key
- **GIVEN** a method `#[McpTool(scope: 'update')] reassignCase(string $id)`
- **WHEN** its descriptor is built
- **THEN** the descriptor MUST NOT carry an `annotations` key
@e2e exclude Descriptor-shape assertion with no UI surface; asserted by AttributeToolScannerTest.

#### Scenario: A malformed map is rejected at scan time
- **GIVEN** a method `#[McpTool(annotations: ['citizenIntake' => ['nested' => true]])]`
- **WHEN** `AttributeToolScanner` scans the declaring class
- **THEN** no tool MUST be registered for that method
- **AND** a warning MUST be logged naming `annotations`
@e2e exclude Malformed-attribute path with no UI surface; asserted by AttributeToolScannerTest.
