# Design — or-mcp-derived-tool-provider

## Context

`or-mcp-schema-dialect` stores a validated `x-openregister-mcp` block in each
opted-in schema's `configuration`. This change reads those blocks and emits live
MCP tools through the **existing** `IMcpToolProvider` ABI
(`lib/Mcp/IMcpToolProvider.php`), feeding both serving surfaces. Consumption
precedent: `x-openregister-notifications` → `AnnotationNotifier`. This is the
same pattern for MCP: a declarative dialect, an OR-side derivation, zero leaf-app
code.

Key existing seams (verified at HEAD 2026-07-12):
- `Application::registerMcpToolProviders()` builds the built-in provider list
  (`Registers`/`Schemas`/`Objects`/`Integrations` `ToolProvider`) then appends
  per-app providers via `collectPerAppMcpProviders()`; `McpToolsService` is
  first-wins on tool-name collision.
- `McpProviderBridge` already lifts any `IMcpToolProvider` into an LLPhant
  `ToolInterface` for `ToolRegistry`; `ToolRegistryFacade` is the blessed
  read/invoke surface Hermiq uses.
- `ObjectService` is the RBAC-enforcing write path (ADR-022: apps consume OR
  abstractions; no direct mapper writes).
- `AuditTrail` + `AuditTrailMapper` + `AuditHashService` provide the immutable,
  hash-chained audit trail already used for object CRUD.

## Tool derivation

For each schema with `configuration['x-openregister-mcp']['enabled'] === true`,
and for each verb present in `tools` (or all five verbs when `tools` is absent),
emit one descriptor:

| verb   | id                         | inputSchema                                   | semantics |
|--------|----------------------------|-----------------------------------------------|-----------|
| search | `{appId}.{schema}.search`  | `{ filters: <declared filter props>, page, pageSize, fields }` | list + filter + paginate + project |
| get    | `{appId}.{schema}.get`     | `{ id }`                                       | single object by id/uuid |
| create | `{appId}.{schema}.create`  | the schema (as object body)                   | `ObjectService` create |
| update | `{appId}.{schema}.update`  | `{ id, ...schema }`                            | `ObjectService` update |
| delete | `{appId}.{schema}.delete`  | `{ id }`                                       | `ObjectService` delete |

- `inputSchema`/`outputSchema` reuse the schema JSON (MCP 2025-06-18
  `structuredContent`). `search`/`get`/`create`/`update` return objects shaped by
  the schema; `delete` returns a status summary.
- `description` comes from the verb config, else a generated default
  (`"Search {schema} objects in {appId}."` etc.).
- MCP hints (`readOnlyHint`/`destructiveHint`/`idempotentHint`) from the dialect
  are attached to the descriptor as **untrusted UX metadata**. They inform the
  LLM; they never gate execution.

### `{appId}` in the id vs `getAppId()`

The ABI requires each tool id to start with the provider's `getAppId()`. Derived
tools must be namespaced to the *owning* app (`pipelinq.lead.search`), not to
`openregister`, so Hermiq whitelists and cross-app precedence work per-app.

**Decision:** register **one derived provider instance per owning appId** that
has at least one opted-in schema. `SchemaDerivedToolProvider` is constructed with
an `appId` + the set of that app's opted-in schemas; `getAppId()` returns that
`appId`; its ids are `{appId}.{schema}.{verb}` and pass the existing prefix
check unchanged. `Application::registerMcpToolProviders()` enumerates apps with
opted-in schemas and appends one derived provider each. This preserves the ABI's
namespace invariant with no relaxation, and makes per-app precedence natural (see
below). The alternative — one global derived provider with a relaxed prefix check
— is rejected because it weakens an ABI invariant other providers rely on.

## Feeding both surfaces from one derivation

- **JSON-RPC (`McpToolsService`):** the per-app derived providers are appended to
  the built-in provider list in `registerMcpToolProviders()`. `McpToolsService`
  already enumerates all providers for `tools/list` and routes `tools/call` by
  id.
- **Chat/facade (`ToolRegistry`):** the same derived providers are lifted via the
  existing `McpProviderBridge` in `ToolRegistrationListener`, so they appear in
  `ToolRegistryFacade::listTools()` and route back through `invokeTool()`. No new
  bridge code — the bridge is provider-agnostic.

One provider set, both surfaces, one invocation path. This is the reconciliation
ADR-063 asks for: derived tools are defined once and both surfaces observe the
same catalog + precedence.

## Precedence: hand-written > derived

On id collision (a hand-written per-app provider already exposes
`pipelinq.lead.search`), the hand-written tool MUST win so apps migrate
schema-by-schema without breakage.

- **JSON-RPC:** `McpToolsService` is first-wins on tool name. Ordering the
  derived providers **after** the per-app hand-written providers in the list
  makes hand-written win automatically. The derived provider MUST additionally
  **self-suppress**: before emitting a derived tool, it checks whether a
  hand-written provider for the same appId already exposes that id, and omits the
  derived duplicate (so the derived tool is absent, not merely shadowed — cleaner
  `tools/list`).
- **Chat/facade:** same ordering guarantee applies; `ToolRegistry::registerTool`
  throws on duplicate id, so the derived bridge MUST register *after* per-app
  tools and skip ids already registered.

Precedence is documented and testable, not incidental to list order alone.

## Search semantics

`search` MUST implement, from the dialect + sensible defaults:
- **filters:** only the properties listed in `x-openregister-mcp.tools.search.filters`
  are accepted as query constraints; unknown/undeclared filter keys are ignored
  or rejected (rejected, with a clear tool error, is preferred).
- **pagination:** `page` (default 1) + `pageSize` (default bounded, e.g. 20; hard
  max, e.g. 100) mapped to `ObjectService`'s existing paginated query.
- **field projection:** optional `fields` list restricts returned properties.
- **truncation defaults:** results are capped at the bounded `pageSize`, and long
  string values MAY be truncated with an ellipsis marker, to keep token cost sane
  (naive full-object dumps burn 30k+ tokens — Specter research 2026-07-12). The
  response includes total-count / has-more so the agent can page deliberately.

## Writes via ObjectService with RBAC intact

`create`/`update`/`delete` MUST call `ObjectService` in the caller's ambient NC
session (the ABI already documents "no impersonation, no elevation"). No direct
mapper writes, no system/service account substitution. The RBAC decision is
`ObjectService`'s existing permission enforcement — the dialect `scope` and MCP
hints do NOT grant access. IDOR boundaries remain enforced by `ObjectService`
exactly as for a UI/API caller. A delete on an object the acting identity may not
delete MUST fail with the same authorization error the REST path returns, wrapped
in the tool's `isError` envelope.

## Invocation audit (EU AI Act art.12/14)

EVERY invocation through this provider MUST write one audit record with:
- **acting identity** — the agent's registered non-human identity when the call
  originates from an agent (Hermiq passes agent context), else the NC user id +
  username;
- **`toolId`** — the full `{appId}.{schema}.{verb}`;
- **params digest** — a hash/summary of the arguments, NOT raw argument values
  (avoid persisting PII in the audit row);
- **result summary** — object count / affected ids / `isError` + error class;
- **timestamp** and (when the tool touched an object) the register/schema/object
  linkage.

### Storage decision

**Prefer the existing immutable audit-trail abstraction (ADR-022):** the
`AuditTrail` entity + `AuditTrailMapper`, chained via `AuditHashService`
(SHA-256 hash chain gives the art.12 tamper-evidence "record-keeping"
guarantee). Two sub-cases:
- For `create`/`update`/`delete`, `ObjectService` **already** writes an
  `AuditTrail` row for the object mutation; the tool-invocation record augments
  (does not duplicate) it with the `toolId` + acting-agent identity, so the
  object-level and tool-level trails cross-reference.
- For `search`/`get` (reads, which produce no object `AuditTrail` row today) and
  for the acting-*agent* identity dimension, the existing `AuditTrail` shape is
  object-CRUD-centric (register/schema/object ids, `action`, `changed`), which
  does not cleanly hold a read-invocation with an agent principal and a params
  digest.

**Recommendation:** extend the audit-trail abstraction with an MCP
tool-invocation action type (a new `action` value plus `toolId` / `agent` /
`paramsDigest` / `resultSummary` fields on `AuditTrail`, or a thin dedicated
`ToolInvocationLog` mapper that reuses `AuditHashService` for the same hash
chain). This is flagged as a **DEFERRED_QUESTION** — see below — because it is a
schema/storage decision that Hermiq's `agent-tool-governance-and-disclosure`
change (which *consumes* this log for the art.14 oversight surface) has a stake
in. Whichever shape is chosen, the invariant is: one immutable, hash-chained
record per invocation, readable by Hermiq's oversight surface.

## Seed Data

This change ships **no production seed data** — it derives tools from whatever
schemas leaf apps opt in (delivered separately, e.g. pipelinq's migration). For
OR test/dev coverage, reuse the **fixture** schema from `or-mcp-schema-dialect`
(one `enabled:true` schema, all five verbs) so the provider can be exercised end
to end: `getTools()` returns five descriptors, each verb round-trips through
`ObjectService`, and each invocation writes exactly one audit record. A second
fixture — a hand-written provider claiming one of the derived ids — is needed to
test precedence (hand-written wins, derived duplicate suppressed). These are test
inputs, not shipped registers; OR's own production registers stay default-OFF.

## Declarative vs imperative

The **declaration** (which schemas/verbs) is declarative and lives in
`x-openregister-mcp` (previous change). This change is the **imperative
consumer**: it reads the declaration and implements emission, dispatch,
pagination/projection/truncation, RBAC-through-`ObjectService`, and audit. The
boundary matters for security: a schema author declares *intent* (expose these
verbs) but cannot inject *behaviour* — the imperative code owns how writes
happen, how RBAC is enforced, and how invocations are logged. The coarse CRUD
template is deliberately the only thing derivable from declaration; anything
beyond it is an imperative `#[McpTool]` service method (next change), which is
handwritten code reviewed like any other, not schema data.

## Non-Goals

- No per-agent whitelist enforcement UX / progressive disclosure (Hermiq change).
- No `#[McpTool]` attribute or reflection scanner (next change,
  `or-mcp-tool-attribute`, which reuses this change's audit + RBAC path).
- No OAuth 2.1 / streamable-HTTP transport (ADR-063 direction only).
- No change to the `IMcpToolProvider` ABI or to `McpToolsService`'s first-wins
  semantics.

## DEFERRED_QUESTIONS

- **Audit storage shape:** extend `AuditTrail` with tool-invocation fields + a
  new `action` type, vs a dedicated `ToolInvocationLog` mapper reusing
  `AuditHashService`. Coordinate with Hermiq's
  `agent-tool-governance-and-disclosure` (the art.14 oversight consumer). Design
  recommends the reuse-`AuditHashService` path; final field shape deferred.
- **Undeclared search filter:** reject with a tool error vs silently ignore.
  Design prefers reject; confirm against Hermiq's tolerance for strict tool
  errors.
- **Bounded `pageSize` defaults** (default 20 / max 100 proposed) — confirm
  against real object-size distributions and token budgets.
