---
kind: code
depends_on: [or-mcp-schema-dialect]
---

## Why

`or-mcp-schema-dialect` (the head of this ADR-063 chain) defines the
`x-openregister-mcp` declaration and validates it, but emits **nothing** — an
opted-in schema stores its block in `configuration` and no MCP tool appears. This
change is the consumer: it turns those declarations into live tools, delivering
the core of **ADR-063 (MCP as Platform Abstraction, hydra, this wave)** — apps
declare CRUD exposure on their schemas and OpenRegister derives the tools, so
leaf apps stop hand-writing `IMcpToolProvider` CRUD code (pipelinq's 11
hard-coded `TOOL_DESCRIPTORS`, decidesk's ~14, procest's ~3, most of which are
plain OR CRUD).

Two facts make this a precise, low-risk insertion rather than a rebuild:

1. The provider ABI already exists and is kept: `lib/Mcp/IMcpToolProvider.php`
   (`getAppId()`, `getTools()`, `invokeTool()`; id MUST start `{appId}.`; runs
   in caller's session; provider owns IDOR). A derived provider is just another
   `IMcpToolProvider` — no ABI change.
2. There are **two** serving surfaces that any derivation MUST feed, currently
   unreconciled (ADR-063 names this duality):
   - JSON-RPC MCP server — `McpToolsService` (`tools/list` + `tools/call`,
     first-wins on tool-name collision), fed by the built-in provider list in
     `Application::registerMcpToolProviders()`.
   - Chat / LLPhant path — `ToolRegistry` (lazy `ToolRegistrationEvent`, id regex
     `^[a-z0-9_]+\.[a-zA-Z0-9_]+$`) + `McpProviderBridge` (dotted id + `_`-alias),
     read through the blessed public facade `ToolRegistryFacade::listTools()` /
     `invokeTool()` (gate-27/ADR-041). Hermiq consumes only via the facade.

A single new built-in provider, registered into `McpToolsService` **and** bridged
into `ToolRegistry` (the existing `McpProviderBridge` already lifts any
`IMcpToolProvider` into the chat path), feeds both surfaces from one derivation.

Because the EU AI Act art.12/14 logging-and-oversight obligations bite
2026-08-02, every derived-tool invocation MUST be auditable: agent/user
identity, toolId, params digest, result summary. Doing this once in the derived
provider (and, next change, the attribute scanner) is exactly why centralising
tool code in OR is the wedge — leaf apps can't each be trusted to log correctly.

## What Changes

- **New built-in provider** `lib/Mcp/BuiltIn/SchemaDerivedToolProvider.php`
  implementing `IMcpToolProvider`:
  - `getAppId()` returns `openregister` (the derived tools are OR-owned), **but**
    each emitted tool id is namespaced to the *owning app* as
    `{appId}.{schema}.{verb}` — see the id decision in design.md (this requires a
    documented, deliberate relaxation of the "id prefix == getAppId()" check for
    the derived provider, OR registering one derived provider per owning appId).
  - `getTools()` enumerates every schema whose validated `x-openregister-mcp`
    block has `enabled:true`, and for each declared verb emits a descriptor:
    `{ id: "{appId}.{schema}.{verb}", name, description, inputSchema }`. The
    schema itself is reused as `inputSchema` (and, for `search`/`get`, as the
    element shape of `outputSchema`/`structuredContent`, MCP 2025-06-18).
  - `invokeTool()` dispatches by parsed `{appId}.{schema}.{verb}` to the matching
    `ObjectService` operation.
- **Both surfaces fed from one provider:**
  - Registered in `Application::registerMcpToolProviders()` in the built-in
    provider list (JSON-RPC surface, via `McpToolsService`).
  - Bridged into `ToolRegistry` via the existing `ToolRegistrationListener` /
    `McpProviderBridge` path so the same tools appear on the chat/facade surface
    (`ToolRegistryFacade::listTools()`).
- **Explicit precedence: hand-written > derived.** On a tool-id / tool-name
  collision, a hand-written per-app `IMcpToolProvider` tool MUST win over the
  derived tool. The derived provider MUST be registered so it is consulted
  *after* per-app providers on both surfaces (JSON-RPC is first-wins; the derived
  provider MUST be ordered last and MUST suppress derived tools whose id a
  hand-written provider already claimed). This lets apps migrate schema-by-schema
  without breakage.
- **Search verb** implements: property `filters` (from the dialect), pagination,
  field projection, and truncation defaults (a bounded default page size + result
  truncation to keep token cost sane — Specter research 2026-07-12).
- **Writes via `ObjectService` with RBAC intact.** `create`/`update`/`delete`
  MUST go through `ObjectService` in the caller's ambient session — no system
  account, no impersonation, no IDOR bypass. The `x-openregister-mcp` `scope`
  and MCP hints are advisory; the authoritative gate is OR RBAC at the
  `ObjectService` call.
- **Invocation audit (AI Act art.12/14).** EVERY `invokeTool()` call — derived or
  otherwise routed through this provider — MUST write one audit record capturing:
  acting identity (agent non-human id when present, else NC user), `toolId`, a
  digest of the parameters (not raw PII), and a result summary
  (count/ids/isError). Storage decision in design.md (prefer the existing
  immutable audit-trail abstraction, ADR-022 / `AuditTrail` +
  `AuditHashService`).

## Impact

- **Affected specs:** `ai-mcp` (derived provider, dual-surface feed, precedence,
  search semantics, RBAC-through-ObjectService, invocation audit), `mcp-discovery`
  (the JSON-RPC surface now serves derived tools).
- **Affected code (implementation):** new `lib/Mcp/BuiltIn/SchemaDerivedToolProvider.php`;
  `lib/AppInfo/Application.php` (`registerMcpToolProviders()` + the
  `ToolRegistrationListener` wiring); reads schemas via `SchemaMapper`; writes via
  `ObjectService`; audit via `AuditTrailMapper`/`AuditHashService`.
- **Downstream:** unblocks `or-mcp-tool-attribute` (shares this provider's audit +
  RBAC path); lets pipelinq/decidesk/procest delete derived-CRUD-equivalent
  hand-written tools.
- **Depends on:** `or-mcp-schema-dialect` (the dialect + validation this change
  reads).
- **Risk:** medium — new runtime tools reachable by agents. Mitigated by
  default-OFF (only `enabled:true` schemas emit), RBAC-through-`ObjectService`,
  hand-written precedence during migration, and per-invocation audit.
