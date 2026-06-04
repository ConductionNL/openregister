# Design: Chat AI (2026-05-24 retrofit)

Retrofit change. Tasks describe retroactive annotation + a Notes update; no implementation work.

## Approach
A Bucket 2a coverage scan flagged 19 chat-related methods as unspecced. Triage against existing class-level `@spec` annotations and the in-flight `ai-chat-companion-orchestrator` / `ai-chat-companion-streaming` changes reduces this to one real gap (`ToolManagementHandler::convertFunctionsToFunctionInfo`), which this change retroactively links to a new `REQ-006`.

## Files affected
- `openspec/specs/chat-ai/spec.md` — adds REQ-006, extends Notes
- `lib/Service/Chat/ToolManagementHandler.php` — adds `@spec` tag on `convertFunctionsToFunctionInfo`

## What this change deliberately does NOT cover
- **In-flight-covered methods** (`ChatHealthController::health`, all `StreamYieldChannel` emit/on* methods, all `ChatStreamController` SSE helpers): their containing classes already carry class-level `@spec` annotations pointing to the in-flight `ai-chat-companion-orchestrator` / `ai-chat-companion-streaming` change directories. When those changes archive, their REQs merge into `chat-ai/spec.md` and these methods inherit canonical coverage. Retroactively annotating them now would create duplicate `@spec` paths.
- **Stubs already documented in spec Notes** (`ChatService::testChat`): the existing spec already explicitly states this method is a facade stub awaiting the original implementation.
- **Dead code** (`ChatController::page`): not registered in `appinfo/routes.php`; unreachable. Flagged in Notes; cleanup belongs in a separate change, not a spec retrofit.
- **Vue UI helpers** (`ChatSideBar::isActive`, `ChatIndex::showAgentSelector`): pure presentational logic — CSS class binding and dialog open-toggle — with no server-observable behaviour worth specifying.
