# Retrofit — chat-ai (2026-05-24 follow-up)

Second-pass retrofit on the `chat-ai` capability. The 2026-04-30 retrofit covered the user-facing pipeline (REQ-001..005). The Bucket 2a scan on 2026-05-24 re-surfaced 19 chat-related methods that the heuristic flagged as "unspecced". Triaging them against observed behaviour, the in-flight `ai-chat-companion-orchestrator` and `ai-chat-companion-streaming` changes, plus the existing chat-ai spec Notes:

- **15 methods are already covered** by class-level `@spec` annotations pointing to the two in-flight changes (orchestrator's `health-probe-endpoint`, `sse-streaming-endpoint`, streaming's three SSE-event REQs). Those REQs land naturally when the in-flight changes archive.
- **1 method is documented in the existing spec Notes** as an unimplemented stub (`ChatService::testChat`).
- **1 method is dead code** — `ChatController::page` is not registered in `appinfo/routes.php` and is unreachable. Flagged in Notes; should be removed in a separate cleanup, not retrofit-specced.
- **2 methods are pure Vue UI helpers** (`ChatSideBar::isActive`, `ChatIndex::showAgentSelector`) — presentational logic without server-observable behaviour. Out of scope for backend spec retrofit.
- **1 method is a genuine unspecced backend behaviour** — `ToolManagementHandler::convertFunctionsToFunctionInfo` converts internal tool-array descriptors into the `FunctionInfo` objects LLPhant expects for `setTools()`. This is part of the agent tool-calling pipeline (`REQ-001` describes the LLM call; this method is the adapter that makes tool-equipped agents work).

This change retrofits the one real gap and documents the rest in the spec Notes section.

## Affected code units
- lib/Service/Chat/ToolManagementHandler.php::convertFunctionsToFunctionInfo

## Approach
- For the one in-scope method: describe inputs, output shape, parameter-type handling, and the cross-tool lookup performed to bind each `FunctionInfo` to its source `ToolInterface`.
- Extend `chat-ai/spec.md` with `REQ-006`.
- Update the spec Notes to record the FP triage above (`page` dead code, in-flight orchestrator/streaming coverage, Vue UI scope), so a third-pass scan does not re-surface them.

Source: `/tmp/or-scan/rspec-cluster-chat-ai.json` (Bucket 2a, generated 2026-05-24). See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
