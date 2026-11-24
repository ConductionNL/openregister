# Tasks: mcp-reach-annotation

- [x] 1.1 `McpVerbAnnotation` accepts `reach` and refuses a value outside `ToolReachLevels::ORDER` with `mcp-bad-reach`
- [x] 1.2 `SchemaDerivedToolProvider::buildDescriptor()` carries a declared `reach` under `ToolReachLevels::REACH_KEY`; none declared, no key
- [x] 1.3 Unit tests: every level accepted, a wrong value refused (`McpAnnotationValidatorTest`); declared reach carried and none invented (`SchemaDerivedToolProviderTest`)
- [ ] 1.4 Consumer: pipelinq `mcp-full-action-coverage` declares `reach` on its derived tool entries once this is on main
