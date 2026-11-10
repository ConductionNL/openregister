---
kind: code
depends_on: [or-mcp-derived-tool-provider]
---

## Why

The `x-openregister-mcp` dialect (`or-mcp-schema-dialect`) and its derived
provider (`or-mcp-derived-tool-provider`) cover the *coarse CRUD* half of
**ADR-063 (MCP as Platform Abstraction, hydra, this wave)**: schema-declared
`{appId}.{schema}.{verb}` tools. But real apps have non-CRUD behaviour the CRUD
template cannot express — pipelinq's `createLead` (a write with side effects),
`logContactmoment`, and `pipelineForecast` (an aggregation). Today those live as
hand-coded `IMcpToolProvider` dispatch (pipelinq's `TOOL_DESCRIPTORS` + if/else).
ADR-063's second pillar replaces that with **annotation-declared service tools**:
a developer marks a service method with a `#[McpTool]` attribute and OpenRegister
discovers and registers it — no per-app provider class, no hand-written
descriptor/dispatch, one discovery point.

There is **zero attribute-based tool scanning anywhere in the fleet today** — this
is net-new. It is modelled on the php-mcp/server pattern (schema inferred from
type hints + docblocks) and folds attributed tools into the *same* catalog, the
*same* audit trail, and the *same* RBAC path the derived provider established, so
governance (EU AI Act art.12/14, deadline 2026-08-02) is uniform across
schema-derived and annotation-declared tools.

Critically, ADR-041 (gate-27: no phantom cross-app RPC) requires attributed
methods to execute **in-process in the owning app's own service** — OpenRegister
discovers and catalogs them, but invocation calls the app's own method in its own
process; there is no cross-app RPC. This keeps the blessed inbound surface
(`ToolRegistryFacade`) the single door while the actual behaviour stays inside
the app that owns it.

## What Changes

- **New PHP attribute** `#[McpTool]` (net-new, e.g.
  `OCA\OpenRegister\Mcp\Attribute\McpTool`), php-mcp/server-style:
  - `name` (optional; defaults to the method name, namespaced `{appId}.{name}`),
  - `description` (optional; falls back to the method's docblock summary),
  - the tool `inputSchema` is **inferred** from the method's parameter type hints
    + docblock `@param` annotations; the `outputSchema` from the return type +
    `@return` where present.
  - Placed on **public service methods** in an installed app.
- **New reflection scanner** in OpenRegister's provider discovery
  (`Application`-level, alongside `collectPerAppMcpProviders()`): for each
  installed app, discover classes/methods carrying `#[McpTool]`, build a tool
  descriptor per attributed method (id `{appId}.{toolName}`), and register them in
  the same catalog — served on BOTH surfaces (`McpToolsService` JSON-RPC +
  `ToolRegistry`/facade) exactly like the derived provider's tools.
- **In-process invocation (ADR-041).** Invoking `{appId}.{toolName}` MUST resolve
  the owning app's service from that app's own DI container and call the
  attributed method **in-process, in the owning app** — no HTTP, no cross-app
  RPC, no OR-side re-implementation. OR is the registry/catalog + the blessed
  inbound door; the app owns and runs the behaviour.
- **Same audit + RBAC rules** as the derived provider: every attributed-tool
  invocation writes one immutable audit record (acting identity, toolId, params
  digest, result summary) via the audit-trail abstraction; the method executes in
  the caller's ambient NC session and the owning app's method is responsible for
  its own authorization/IDOR — OR MUST NOT impersonate or elevate.
- **Namespace + precedence:** attributed-tool ids are `{appId}.{toolName}`,
  disjoint from derived `{appId}.{schema}.{verb}` ids by construction; a
  collision between an attributed tool and a hand-written provider tool follows
  the same hand-written-wins precedence, and a collision between an attributed
  tool id and a derived id is a validation-time error the developer must resolve
  (documented rule).

## Impact

- **Affected specs:** `ai-mcp` (attribute definition + schema inference,
  reflection scanner + registration, in-process/no-cross-app-RPC invocation,
  audit + RBAC parity, id namespacing/precedence), `mcp-discovery` (attributed
  tools served on the JSON-RPC surface).
- **Affected code (implementation):** new
  `lib/Mcp/Attribute/McpTool.php`; a new scanner
  (`lib/Mcp/AttributeToolScanner.php` or equivalent) wired into
  `Application`'s MCP discovery; reuses the derived provider's audit + invocation
  patterns; a thin `IMcpToolProvider`-shaped adapter so attributed tools flow
  through the existing catalog + bridge unchanged.
- **Depends on:** `or-mcp-derived-tool-provider` (shared audit + RBAC + dual-surface
  registration path).
- **Downstream:** lets pipelinq keep `createLead`/`logContactmoment`/`pipelineForecast`
  as `#[McpTool]` service annotations while deleting its CRUD-equivalent
  hand-written tools (pipelinq `mcp-provider-declarative-migration`); same for
  decidesk/procest non-CRUD tools.
- **Risk:** medium — reflection over installed apps' code, and in-process
  invocation of app methods by id. Mitigated by: only public methods carrying the
  explicit attribute are eligible; in-process execution keeps ADR-041's no-RPC
  boundary; the app's own method owns authorization; every invocation is audited.
