---
retrofit_extensions: [REQ-006]
---

## ADDED Requirements

### Requirement: The system MUST convert tool definitions to LLPhant FunctionInfo objects for LLM function calling

When an agent has tools configured (via the agent's `tools` field listing tool IDs the agent may invoke), the system MUST translate each tool's array-shaped function definition into a `LLPhant\Chat\FunctionInfo\FunctionInfo` instance that LLPhant accepts via `setTools()`. `ToolManagementHandler::convertFunctionsToFunctionInfo($functions, $tools)` performs this translation, MUST preserve `name` / `description` / parameters / required fields, MUST resolve each function's source `ToolInterface` instance by scanning the supplied tools (so LLPhant can invoke `$toolInstance->{$func['name']}(...)`), and MUST handle nested `object` and `array` parameter types by carrying their `properties` / `items` schemas through to the `Parameter` constructor's `itemsOrProperties` argument.

#### Scenario: Scalar parameter is converted to a Parameter

- **GIVEN** a function definition `{ name: 'searchObjects', description: 'Search', parameters: { properties: { query: { type: 'string', description: 'q' } }, required: ['query'] } }`
- **AND** a tool instance whose `getFunctions()` returns a function with `name === 'searchObjects'`
- **WHEN** `convertFunctionsToFunctionInfo($functions, $tools)` is called
- **THEN** the returned `FunctionInfo` MUST have `name === 'searchObjects'`, `description === 'Search'`, exactly one `Parameter` (`name: 'query'`, `type: 'string'`, `description: 'q'`, `enum: []`, `format: null`), `required === ['query']`
- **AND** the `FunctionInfo`'s instance target MUST be the supplied tool, so LLPhant can call `$tool->searchObjects(...)` directly

#### Scenario: Object and array parameter types carry their nested schemas

- **GIVEN** a function definition whose `parameters.properties` includes `filters: { type: 'object', properties: { tag: { type: 'string' } } }` and `ids: { type: 'array', items: { type: 'integer' } }`
- **WHEN** `convertFunctionsToFunctionInfo` is called
- **THEN** the `filters` `Parameter` MUST be constructed with `itemsOrProperties` equal to the `properties` map `{ tag: { type: 'string' } }`
- **AND** the `ids` `Parameter` MUST be constructed with `itemsOrProperties` equal to the `items` schema `{ type: 'integer' }`
- **AND** when an `object` parameter omits `properties`, or an `array` parameter omits `items`, `itemsOrProperties` MUST default to `[]` rather than `null`

#### Scenario: Tool instance bound by name match across all supplied tools

- **GIVEN** three tools are supplied, and only tool B's `getFunctions()` contains a function named `runReport`
- **WHEN** `convertFunctionsToFunctionInfo` converts a function definition with `name === 'runReport'`
- **THEN** the produced `FunctionInfo`'s instance target MUST be tool B
- **AND** if no supplied tool exposes a function with that name, the `FunctionInfo` MUST still be created with a `null` tool instance (LLPhant will surface the resulting invocation failure at call time rather than at conversion time)
