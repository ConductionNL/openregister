---
kind: config
depends_on: []
---

## Why

Per **ADR-063 (MCP as Platform Abstraction, being authored in hydra this same
wave)**, apps MUST NOT ship their own MCP tool code. The fleet today does the
opposite: decidesk (~14 tools), pipelinq (11, hard-coded `TOOL_DESCRIPTORS` +
if/else dispatch), procest (~3) each hand-write an `IMcpToolProvider` whose
tools are, in the majority, plain OpenRegister CRUD over the app's own schemas.
That is duplicated code, duplicated audit surface, and — with the EU AI Act
art.12/14 logging-and-oversight deadline of 2026-08-02 approaching — duplicated
governance risk. ADR-031 already lists "MCP exposure/discovery" as a declarative
payoff in its benefits table; the `x-openregister-*` dialect family
(`lifecycle`, `calculations`, `aggregations`, `notifications`, `relations`,
`archival`, `quality`, `dedup`, `survivorship`, `merge`, `handoff`) is the
established mechanism for schema-declared behaviour, folded into schema
`configuration` on import and validated at save time. There is no MCP member of
that family yet.

This change adds it: a new top-level `x-openregister-mcp` dialect key per schema
in `lib/Settings/{app}_register.json`, its JSON-shape validator wired into the
same `SchemaMapper::cleanObject()` validation pass as every sibling dialect, its
entry in `Schema::ANNOTATION_VOCABULARY` (so it is no longer dropped as an
unknown key), and its fold-into-`configuration` on import. This change defines
and validates the *declaration*; it emits **no tools** — that is
`or-mcp-derived-tool-provider`'s job.

### The three-change arc (ADR-032 chain)

This is the **head** of a three-change chain that together deliver ADR-063's
OpenRegister half. Narrated here once so each downstream change can reference it:

1. **`or-mcp-schema-dialect`** (this change, `kind: config`) — defines the
   `x-openregister-mcp` dialect: its exact JSON shape (`enabled` + per-verb
   `search`/`get`/`create`/`update`/`delete` tool configs with
   `description`/`filters`/`scope` + MCP annotation hints
   `readOnlyHint`/`destructiveHint`/`idempotentHint`), its save-time validator,
   its vocabulary registration, and its import fold. Default **OFF** (opt-in per
   schema). Emits nothing.

2. **`or-mcp-derived-tool-provider`** (`depends_on: [or-mcp-schema-dialect]`,
   `kind: code`) — a `SchemaDerivedToolProvider` built-in that reads every
   schema's validated `x-openregister-mcp` block and emits
   `{appId}.{schema}.{verb}` tools through the **existing** `IMcpToolProvider`
   ABI, feeding **both** serving surfaces (`McpToolsService` JSON-RPC and the
   `ToolRegistry`/`ToolRegistryFacade` chat path). Hand-written provider tools
   win over derived tools on id collision (explicit precedence), so apps migrate
   schema-by-schema without breakage. Every invocation writes an audit record
   (agent/user identity, toolId, params digest, result summary) per AI Act
   art.12/14; writes flow through `ObjectService` with RBAC intact.

3. **`or-mcp-tool-attribute`** (`depends_on: [or-mcp-derived-tool-provider]`,
   `kind: code`) — a net-new `#[McpTool]` PHP attribute (php-mcp/server style;
   schema inferred from type hints + docblocks) plus a reflection scanner in
   OR's provider discovery that finds attributed public service methods in
   installed apps and registers them in the **same** catalog, namespaced
   `{appId}.{toolName}`, under the same audit + RBAC rules. Attributed methods
   execute in-process in the owning app's own service (ADR-041 — no cross-app
   RPC). This covers the non-CRUD behaviour the CRUD template cannot express
   (pipelinq's `createLead`/`logContactmoment`/`pipelineForecast`).

## What Changes

- **New dialect key** `x-openregister-mcp` added to `Schema::ANNOTATION_VOCABULARY`
  so it is folded into schema `configuration` on import (via the existing
  `x-openregister-*` fold in `Schema.php`) instead of being silently dropped and
  logged as an unknown key.
- **New validator** `McpAnnotationValidator` (under `lib/Service/Mcp/`, mirroring
  the shape of `CalculationAnnotationValidator` / `NotificationAnnotationValidator`)
  that checks the `x-openregister-mcp` block's shape: `enabled` boolean; optional
  `tools` object whose keys are drawn from the fixed verb set
  {`search`,`get`,`create`,`update`,`delete`}; per-verb `description` (string),
  `filters` (list of schema property names, `search` only), `scope`
  (`read`|`create`|`update`|`delete`), and boolean MCP hints
  `readOnlyHint`/`destructiveHint`/`idempotentHint`. Unknown verb keys and
  unknown property references in `filters` are rejected with a clear per-schema
  save error, consistent with the sibling validators.
- **New validation dispatch** `SchemaMapper::validateMcpAnnotation()` added to the
  `cleanObject()` validation chain (alongside `validateNotificationsAnnotation()`
  et al.).
- **Documentation** of the dialect: shape reference, opt-in/default-OFF policy,
  the untrusted-hint / authoritative-RBAC distinction, and the coarse-CRUD (not
  per-endpoint) rationale, added to the `ai-mcp` capability spec and the dialect
  docs.
- **No tool emission, no runtime behaviour change** beyond validation + storage.
  A schema carrying a valid `x-openregister-mcp` block saves and round-trips; no
  MCP catalog entry appears until `or-mcp-derived-tool-provider` ships.

## Impact

- **Affected specs:** `ai-mcp` (new requirements: dialect shape, validation,
  vocabulary registration, import fold, default-OFF policy).
- **Affected code (implementation, later change):** `lib/Db/Schema.php`
  (`ANNOTATION_VOCABULARY`), `lib/Db/SchemaMapper.php` (`cleanObject()` +
  `validateMcpAnnotation()`), new `lib/Service/Mcp/McpAnnotationValidator.php`.
- **Downstream:** unblocks `or-mcp-derived-tool-provider`; every leaf app
  (pipelinq, decidesk, procest) can begin declaring `x-openregister-mcp` on its
  schemas ahead of the provider landing.
- **Risk:** low — additive, opt-in, default-OFF; a malformed block fails the
  schema save loudly (same failure mode as every sibling dialect) rather than
  silently mis-exposing data.
