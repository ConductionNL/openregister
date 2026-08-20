## Context

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

`ai-mcp` is minted as a NEW capability that sits between the existing `mcp-discovery` JSON-RPC server and the in-flight `ai-chat-companion-orchestrator` chat orchestrator. It specifies the LLPhant-side tool-bridging machinery: an event-driven `ToolRegistry` that lets every installed Nextcloud app contribute LLPhant `ToolInterface` instances, and an `McpProviderBridge` adapter that exposes `IMcpToolProvider` implementations through LLPhant's function contract with safe-alias round-trip and JSON-Schema nullable collapse.

The ghost change `retrofit-2026-05-24-ai-mcp` carries the spec delta + 5 REQs + annotations for 14 methods across 3 files.

**Files covered:**
- `lib/Service/ToolRegistry.php` (5 methods — DI-wired registry, lazy load, validate-and-store, id-keyed selection, metadata-only listing)
- `lib/Event/ToolRegistrationEvent.php` (2 methods — constructor + delegation surface for listeners)
- `lib/Tool/McpProviderBridge.php` (12 methods — provider wrapping, narrowing, descriptor production, schema sanitisation, dotted ↔ underscore round-trip, throwable envelope, magic `__call` entry)

## Goals / Non-Goals

**Goals:**
- Spec the in-process cross-app tool registration surface so consuming apps (decidesk, opencatalogi, openbuild, …) have a stable contract.
- Spec the LLPhant adapter so the dotted-id ↔ safe-alias round-trip and JSON-Schema nullable collapse are testable (these are the two pieces that historically caused silent tool-loop crashes during the AI Chat Companion bring-up).
- Annotate 14 methods with `@spec` tags pointing at the 5 new REQs.

**Non-Goals:**
- Does NOT spec the `IMcpToolProvider` contract itself — that is the in-flight `ai-chat-companion-orchestrator` change's territory under the `chat-ai` capability.
- Does NOT spec the MCP JSON-RPC wire protocol, sessions, or tool/resource methods — those live in `mcp-discovery`.
- Does NOT backfill `@spec` annotations on methods whose REQs already exist in `mcp-discovery` or `chat-ai` (deferred to `future-pass:next` in tasks.md).

## Decisions

**Decision: `--cluster ai-mcp` (new capability) rather than `--extend mcp-discovery` or `--extend chat-ai`**

The batch JSON's `mode` field reads `--extend ai-mcp`, but `openspec/specs/ai-mcp/spec.md` does not exist. The task instructions resolve the ambiguity: "create it if it doesn't exist (status: implemented, retrofit: true)". Extending `mcp-discovery` would conflate the JSON-RPC wire layer with the LLPhant adapter layer. Extending `chat-ai` would conflate the chat orchestrator with the registry/adapter that the orchestrator consumes. `ai-mcp` as a thin middle-layer capability gives each subsystem a single home.

**Decision: 5 REQs partitioning by observable behavior, not by file**

The 14 in-scope methods cluster cleanly:
- REQ-001 covers the lazy-load event dispatch (ToolRegistry::loadTools + ToolRegistrationEvent constructor + registerTool delegation).
- REQ-002 covers id-format and metadata validation (ToolRegistry::registerTool).
- REQ-003 covers id-keyed selection with warn-and-skip semantics (ToolRegistry::getTool, getTools, getAllTools).
- REQ-004 covers the `IMcpToolProvider` → `ToolInterface` adaptation including narrowing and schema sanitisation (McpProviderBridge constructor + setOnlyMcpId + getName + getDescription + getFunctions + sanitiseSchema + collapseType + setAgent).
- REQ-005 covers the dotted ↔ underscore round-trip and the invocation forwarding with structured-error envelope (McpProviderBridge::executeFunction + __call + safeFunctionName + resolveMcpId).

Splitting further (e.g., one REQ per private method) would inflate without adding testable surface. Collapsing further (e.g., merging REQ-001 + REQ-002) would hide the validation surface that consuming apps need to test against.

**Decision: drop 63 of the 77 batch methods**

The batch was inherited from coverage scan triage and includes many "(triaged DROP from X)" methods that were checked against other capabilities and rejected. Re-inspection shows the genuine homes:

- **15 methods (IMcpToolProvider + BuiltIn/*ToolProvider + McpToolsService provider plumbing)** → already speced in the in-flight `ai-chat-companion-orchestrator` change under `chat-ai`. Re-speccing would conflict.
- **15 methods (McpProtocolService, McpResourcesService, McpToolsService listTools/callTool, McpServerController privates)** → already speced under `mcp-discovery`. These are annotation gaps (missing `@spec` tags), not reverse-spec opportunities. Deferred to `future-pass:next`.
- **7 trivial constructors** → no observable behavior; DI wiring only.
- **`McpDiscoveryService::getCapabilityIds`, `getBaseUrl`** → trivial getters under existing `mcp-discovery` REQs.
- **`StreamingToolInstanceWrapper::normaliseArguments`** → already speced under `ai-chat-companion-streaming`.

This leaves 14 methods that genuinely belong to the new `ai-mcp` capability.

**Decision: re-annotate `ToolRegistrationEvent::registerTool` (already tagged)**

The method currently carries `@spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27`. We ADD a second `@spec` line pointing at `ai-mcp#REQ-001` rather than replacing the existing tag. Multiple `@spec` tags on one method are allowed (and common — see McpDiscoveryService); the b2b retrofit tag still asserts that the method was annotated under the original cross-ref pass.

## Risks / Trade-offs

- **Bridge surface is wide**: McpProviderBridge has 12 methods spanning adapter logic, magic dispatch, schema sanitisation, and error mapping. REQ-004 + REQ-005 together cover all 12 but the boundary between them is "is this about exposing tools or invoking them?". The boundary is clean for static analysis (getX vs executeX vs __call) but a reviewer rereading the spec months later may find a method on the wrong REQ. → Documented in proposal.md "Affected code units" mapping.
- **`setAgent` is observed-but-currently-unused**: the method stores an `Agent` reference but no current code path reads `$this->agent`. REQ-004 Notes surfaces this honestly; we did NOT silently spec it as "agent context drives invocation" because the code doesn't do that yet.
- **`isError` envelope shape diverges from MCP wire format**: McpProviderBridge returns `['isError' => true, 'error' => '<code>', 'message' => '<text>']` whereas `mcp-discovery`'s MCP wire spec uses `['content' => [{'type' => 'text', 'text' => '...'}], 'isError' => true]`. The divergence is internal (LLPhant tool result path consumes the bridge envelope, not an MCP client) but flagged in REQ-005 Notes for future reconciliation.
- **Lazy-load timing is brittle**: `ToolRegistry::loadTools` runs once per registry instance, not once per chat turn. A long-running PHP-FPM worker that survives across chat turns will not pick up tools registered after the first turn. → Acceptable because Nextcloud disables long-running workers by default; flagged in REQ-001 Notes for future tightening if streaming workloads change this assumption.
- **Re-annotation creates double `@spec` entries**: `ToolRegistrationEvent::registerTool` will have two tags after this change. Static analysers that grep for `@spec` may double-count. → Documented; non-blocking.

## Migration Plan

No migration required — annotations + spec delta only. The ghost change is archived immediately (per repo convention for retrofits) and `openspec/specs/ai-mcp/spec.md` is created at archive time.

The annotation commit SHA will be appended to `.git-blame-ignore-revs`.

## Out of Scope (deferred to future-pass:next)

See `tasks.md` "future-pass:next" section for the full list. Highlights:
- Annotate `mcp-discovery`-covered methods (McpProtocolService, McpResourcesService, McpToolsService listTools/callTool, McpServerController privates) with `@spec` references to existing `mcp-discovery` REQs.
- Reconcile the `isError` envelope shape divergence between `McpProviderBridge` and `McpToolsService`.
- Spec the `setAgent` per-agent permission scoping once it has a consumer.
