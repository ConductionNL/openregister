# Tasks

- [x] 1.1 `#[McpTool]` gains `public readonly ?string $reach = null` (lib/Mcp/Attribute/McpTool.php)
- [x] 1.2 `AttributeToolScanner` rejects a reach outside `ToolReachResolver::ORDER` at scan time and forwards a declared one under `REACH_KEY` (lib/Mcp/AttributeToolScanner.php; AttributeToolScannerTest::testDescriptorForwardsDeclaredReach, testUndeclaredReachStaysOmittedNeverDefaulted, testUnknownReachIsRejectedAndLogged)
- [x] 1.3 `AttributeToolProvider::getTools()` carries `reach` through its rebuild (AttributeToolProviderTest::testGetToolsForwardsDeclaredReachAndOmitsAnUndeclaredOne)
- [x] 1.4 The declared reach arrives at `ToolReachResolver` and `ToolGrantResolver` on both surfaces (AttributeToolDualSurfaceTest::testDeclaredReachArrivesAtTheResolverOnBothSurfaces)
