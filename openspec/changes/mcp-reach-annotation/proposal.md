## Why

An agent grant matrix decides on two axes per tool: the CRUD `scope` and the `reach`, the widest set of principals a call can affect or disclose to (`self`, `user`, `instance`, `external`, `ToolReachLevels::ORDER`). A curated tool already declares its reach on `#[McpTool(reach: ...)]`, and `ToolReachResolver` reads it from the descriptor. A tool derived from a schema's `x-openregister-mcp` block cannot: the dialect validator refuses `reach` as an unknown key, so the reach of every derived tool is inferred from its verb, and an app that knows better (pipelinq `mcp-full-action-coverage`) has nowhere to say so.

## What changes

- A verb entry in `x-openregister-mcp` MAY declare `reach`, one of `ToolReachLevels::ORDER`. Any other value is refused at save time with `mcp-bad-reach`.
- `SchemaDerivedToolProvider` carries a declared `reach` onto the tool's descriptor under `ToolReachLevels::REACH_KEY`, the key `ToolReachResolver` already reads. A verb that declares none gets no key, so the inference stays as it is.

## What does not change

- `reach` is advisory, like `scope` and the hints: it never gates an invocation. RBAC stays the gate.
- `#[McpTool(reach: ...)]` and its scanner check are already on main and are untouched.
- No public method signature changes.
