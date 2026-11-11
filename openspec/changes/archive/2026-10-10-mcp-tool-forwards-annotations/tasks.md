# Tasks

- [x] 1.1 `#[McpTool]` gains `public readonly array $annotations = []` (lib/Mcp/Attribute/McpTool.php)
- [x] 1.2 `AttributeToolScanner` rejects a malformed map at scan time and forwards a non-empty one under `annotations` (lib/Mcp/AttributeToolScanner.php; AttributeToolScannerTest::testDescriptorForwardsDeclaredAnnotations, testUndeclaredAnnotationsStayOmitted, testMalformedAnnotationsAreRejectedAndLogged)
- [x] 1.3 `AttributeToolProvider::getTools()` carries `annotations` through its rebuild (AttributeToolProviderTest::testGetToolsForwardsDeclaredAnnotationsAndOmitsAnEmptyMap)
- [x] 1.4 `McpProviderBridge` carries `annotations` to the facade, so the map arrives on both surfaces (AttributeToolDualSurfaceTest::testDeclaredAnnotationsArriveOnBothSurfaces)
