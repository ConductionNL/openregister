## MODIFIED Requirements

> This file records delta requirements added to the existing `chat-ai` capability
> by the `ai-chat-companion-orchestrator` change. The base capability is defined in
> [`openspec/specs/chat-ai/spec.md`](../../../../specs/chat-ai/spec.md).
> Canonical contracts originate from:
> - [hydra/openspec/specs/ai-chat-companion/spec.md](https://github.com/ConductionNL/hydra/tree/development/openspec/changes/archive/2026-05-11-ai-chat-companion/specs/ai-chat-companion/spec.md) — cross-app contracts
> - [hydra/openspec/architecture/adr-034-ai-chat-companion.md](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-034-ai-chat-companion.md) — ADR with full rationale

## ADDED Requirements

### Requirement: IMcpToolProvider PHP interface

OpenRegister SHALL publish a PHP interface `OCA\OpenRegister\Mcp\IMcpToolProvider` at `lib/Mcp/IMcpToolProvider.php` with the following exact signature. Consuming Conduction apps that wish to expose MCP tools to the AI companion implement it and register implementations via Nextcloud's standard service container or `info.xml`. OR's `McpToolsService` MUST enumerate every registered implementation in-process per turn without issuing extra HTTP requests. Tool ids returned by `getTools()` MUST be namespaced as `{appId}.{toolName}`; `McpToolsService` MUST reject any tool descriptor whose id prefix does not match the provider's `getAppId()` return value.

```php
namespace OCA\OpenRegister\Mcp;

interface IMcpToolProvider
{
    /**
     * The Nextcloud app id that owns this provider (e.g. "opencatalogi").
     * Used to validate the namespace prefix on each returned tool id.
     */
    public function getAppId(): string;

    /**
     * Tool descriptors enumerable by McpToolsService.
     *
     * @return list<array{
     *   id: string,            // MUST start with "{getAppId()}."
     *   name: string,
     *   description: string,
     *   inputSchema: array     // JSON Schema object
     * }>
     */
    public function getTools(): array;

    /**
     * Invoke a tool by id. Implementations MUST check Nextcloud auth and
     * per-object IDOR boundaries before returning data — the runtime
     * passes through the current user's session unchanged.
     *
     * @param string               $toolId    Namespaced tool id, e.g. "opencatalogi.searchCatalogues"
     * @param array<string, mixed> $arguments JSON-decoded tool arguments
     * @return array<string, mixed>           JSON-encodable result
     */
    public function invokeTool(string $toolId, array $arguments): array;
}
```

#### Scenario: A provider implementation is enumerated

- **WHEN** an app `opencatalogi` registers a class `OpenCatalogiToolProvider` implementing `IMcpToolProvider`, and `getTools()` returns one descriptor with id `opencatalogi.searchCatalogues`
- **THEN** `McpToolsService` includes that descriptor in the list returned to the LLM tool-loop on the next conversation turn, with no additional HTTP request issued

#### Scenario: Tool id namespace is enforced

- **WHEN** a provider whose `getAppId()` returns `opencatalogi` returns a tool descriptor with id `docudesk.searchDocs` from `getTools()`
- **THEN** `McpToolsService` MUST reject that descriptor, log a warning at `warning` level naming the offending provider class, and MUST NOT pass the descriptor to the LLM

#### Scenario: Built-in providers migrate onto the IMcpToolProvider contract

- **WHEN** the existing static OR tools (`registers`, `schemas`, `objects`) in `McpToolsService` are reviewed after this change lands
- **THEN** they MUST be exposed by built-in providers implementing `IMcpToolProvider` located at `lib/Mcp/BuiltIn/RegistersToolProvider.php`, `lib/Mcp/BuiltIn/SchemasToolProvider.php`, and `lib/Mcp/BuiltIn/ObjectsToolProvider.php`
- **AND** each built-in provider's `getAppId()` MUST return `"openregister"` and its tool ids MUST be `openregister.registers`, `openregister.schemas`, and `openregister.objects` respectively

### Requirement: McpToolsService provider-discovery refactor

`McpToolsService` SHALL be refactored to enumerate all registered `IMcpToolProvider` implementations rather than serving a static internal tool list. Built-in providers MUST be registered first in the enumeration order. The service MUST aggregate tools from all providers into a single list for the LLM tool-loop. For every descriptor returned by a provider's `getTools()`, the service MUST verify that the id starts with `{provider->getAppId()}.`; any non-conforming descriptor MUST be silently dropped with a `warning`-level log entry before the aggregated list is returned.

#### Scenario: Registering a provider makes its tools available

- **WHEN** the service container has one built-in `openregister` provider and one external `opencatalogi` provider registered
- **THEN** calling `McpToolsService::listTools()` MUST return a combined list that includes tools from both providers in enumeration order (built-ins first)

#### Scenario: Built-in tools have the expected ids after migration

- **WHEN** `McpToolsService::listTools()` is called after the built-in providers are migrated onto `IMcpToolProvider`
- **THEN** the result MUST include descriptors with ids `openregister.registers`, `openregister.schemas`, and `openregister.objects`

#### Scenario: Namespace mismatch is rejected with a logged warning

- **WHEN** a provider whose `getAppId()` returns `appA` returns a descriptor with id `appB.doSomething`
- **THEN** `McpToolsService` MUST drop that descriptor and write a log entry at `warning` level containing the provider class name and the offending tool id
- **AND** the descriptor MUST NOT appear in `listTools()` output

### Requirement: SSE streaming endpoint POST /api/chat/stream

OpenRegister SHALL expose a new authenticated endpoint `POST /index.php/apps/openregister/api/chat/stream` that accepts the same JSON request body shape as the existing non-streaming `POST /api/chat/send` plus an optional `context` field (the `CnAiContext` snapshot). The endpoint MUST respond with `Content-Type: text/event-stream` (Server-Sent Events). Before emitting any events the controller MUST clear PHP output buffers with `while (ob_get_level() > 0) { ob_end_clean(); }`, set the required HTTP headers (`Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`), and call `flush()` after each emitted event. The controller MUST call `exit;` after the final or error event to bypass Nextcloud's Response handler. The endpoint MUST emit events of exactly the following types and shapes:

| Event type | Data payload (JSON) | When emitted |
|---|---|---|
| `token` | `{ "delta": "<string>" }` | Each LLPhant streaming token chunk |
| `tool_call` | `{ "toolId": "<string>", "arguments": <object> }` | LLM requests a tool invocation |
| `tool_result` | `{ "toolId": "<string>", "result": <object>, "isError": <bool> }` | After the tool returns |
| `heartbeat` | `{ "ts": "<ISO-8601 string>" }` | Every 15 seconds when no other event has been emitted in that window |
| `final` | `{ "messageId": "<string>", "conversationUuid": "<string>", "fullText": "<string>", "context": <CnAiContext snapshot or null> }` | Single terminal event on success |
| `error` | `{ "code": "<string>", "message": "<string>" }` | Single terminal event on failure |

Either exactly one `final` OR exactly one `error` event MUST close every HTTP 200 response. Auth failures MUST return HTTP 401 before any SSE stream is started. Non-streaming LLPhant providers (Fireworks parity is unverified — see Fireworks spike task) MUST degrade gracefully: zero `token` events plus one `final` event carrying the full text. The endpoint reuses `ResponseGenerationHandler` (LLPhant pipeline) and `ContextRetrievalHandler` (RAG).

#### Scenario: OpenAI streaming response emits token events then final

- **WHEN** an authenticated client posts a chat message configured with the OpenAI provider and the LLM returns 14 token chunks
- **THEN** the response MUST be `Content-Type: text/event-stream` with exactly 14 `token` events followed by exactly one `final` event
- **AND** the `final` event's `fullText` MUST equal the concatenation of all `delta` values from the token events

#### Scenario: Tool call mid-stream emits tool_call and tool_result events

- **WHEN** the LLM mid-response requests invocation of `opencatalogi.searchCatalogues` with `{"q": "broker"}`
- **THEN** the stream MUST emit a `tool_call` event with `{"toolId": "opencatalogi.searchCatalogues", "arguments": {"q": "broker"}}`
- **AND** after the tool returns, the stream MUST emit a `tool_result` event before resuming `token` events

#### Scenario: Heartbeat emitted during a slow tool loop

- **WHEN** an MCP tool call takes 45 seconds and no other event fires during that time
- **THEN** the stream MUST emit at least two `heartbeat` events (one at approximately 15s and one at approximately 30s after the last event)
- **AND** the client connection MUST remain open until the eventual `tool_result` event arrives

#### Scenario: Non-streaming provider degrades gracefully

- **WHEN** the configured LLPhant provider does not support incremental streaming and returns the full response in one call
- **THEN** the stream MUST emit zero `token` events and exactly one `final` event whose `fullText` contains the entire response

#### Scenario: Auth failure produces HTTP 401 before SSE

- **WHEN** an unauthenticated client (no session, no Basic Auth) posts to `/api/chat/stream`
- **THEN** the response MUST be HTTP 401 with no `text/event-stream` body — the SSE envelope is only used for authenticated HTTP 200 responses

#### Scenario: Final event closes the stream

- **WHEN** the LLM pipeline completes successfully
- **THEN** the stream MUST emit exactly one `final` event and then terminate
- **AND** no further events MUST be emitted after the `final` event

### Requirement: Health probe endpoint GET /api/chat/health

OpenRegister SHALL expose a lightweight endpoint `GET /index.php/apps/openregister/api/chat/health` that allows the `@conduction/nextcloud-vue` widget to probe at mount time whether the AI chat backend is configured and reachable. The endpoint MUST be annotated with `#[PublicPage]` (or `@PublicPage`) so the widget can probe without Nextcloud session authentication. When at least one LLM provider is configured, the endpoint MUST return HTTP 200 with body `{"status": "ok", "capabilities": ["chat", "stream"]}`. When no LLM provider is configured, the endpoint MUST return HTTP 503 with body `{"status": "no_provider"}`.

#### Scenario: Configured instance returns 200 with capabilities

- **WHEN** an unauthenticated client sends `GET /api/chat/health` and at least one LLM provider is configured in OpenRegister settings
- **THEN** the response MUST be HTTP 200 with `Content-Type: application/json`
- **AND** the body MUST be `{"status": "ok", "capabilities": ["chat", "stream"]}`

#### Scenario: Unconfigured instance returns 503

- **WHEN** an unauthenticated client sends `GET /api/chat/health` and no LLM provider is configured
- **THEN** the response MUST be HTTP 503 with body `{"status": "no_provider"}`

#### Scenario: Widget probes once at mount

- **WHEN** the `CnAiCompanion` widget mounts in a host app and receives HTTP 200 from the health probe
- **THEN** the floating action button renders
- **AND** the widget MUST NOT re-probe on every user interaction; the probe result MAY be cached for the page-load lifetime

### Requirement: Message.context JSON column

OpenRegister's `Message` entity SHALL carry a JSON metadata column named `context` stored in the `oc_openregister_messages` table. The column records the `CnAiContext` snapshot active at the moment the user message was sent, plus a `capturedAt` ISO-8601 timestamp. A schema migration MUST add the column with a database-level default of `'{}'` (empty JSON object). Both `POST /api/chat/send` AND `POST /api/chat/stream` MUST persist the `context` field from the request body on every user-authored `Message` row they create. If the request omits `context`, the persisted value MUST be `{}`. If the request supplies a `context` value that is not valid JSON or not an object, the endpoint MUST return HTTP 400.

The persisted shape MUST conform to:

```json
{
  "appId": "<string>",
  "pageKind": "<string>",
  "objectUuid": "<string|null>",
  "registerSlug": "<string|null>",
  "schemaSlug": "<string|null>",
  "route": { "path": "<string>", "name": "<string|null>", "params": <object> },
  "capturedAt": "<ISO-8601 string>"
}
```

#### Scenario: Context is persisted on a streaming send

- **WHEN** the widget posts to `/api/chat/stream` with `context: {"appId": "opencatalogi", "pageKind": "detail", "objectUuid": "00000000-0000-0000-0000-000000000000", "registerSlug": "catalogus", "schemaSlug": "organisation"}`
- **THEN** the `Message` row created for the user-authored entry MUST have `context.objectUuid = "00000000-0000-0000-0000-000000000000"`, `context.registerSlug = "catalogus"`, `context.schemaSlug = "organisation"`, `context.appId = "opencatalogi"`, and `context.capturedAt` within 60 seconds of server time

#### Scenario: Context is persisted on a non-streaming send

- **WHEN** the widget posts to `/api/chat/send` with the same `context` payload
- **THEN** the `Message` row for the user-authored entry MUST have the same `context` fields populated

#### Scenario: Missing context defaults to empty object

- **WHEN** a client posts to either `/api/chat/send` or `/api/chat/stream` without a `context` field in the request body
- **THEN** the persisted `Message.context` MUST be `{}` (empty JSON object) and no error MUST be returned

#### Scenario: Invalid context JSON returns 400

- **WHEN** a client posts to `/api/chat/send` or `/api/chat/stream` with `context` set to the string `"not-an-object"`
- **THEN** the endpoint MUST return HTTP 400 without persisting any message or querying the LLM

### Requirement: MCP tool authorization flowthrough

Every `IMcpToolProvider::invokeTool()` call MUST run with the current Nextcloud session user's permissions and credentials. `McpToolsService` MUST NOT impersonate, elevate, or substitute a system or service account when delegating invocations to any provider. Implementations that return or mutate objects MUST perform a per-object authorization check before responding — this mirrors [adr-005-security.md](https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-005-security.md) Rule 3 (IDOR / OWASP A01:2021). The chat stream controller passes the session cookie unchanged via Nextcloud's standard controller middleware; no additional session forwarding is required in `McpToolsService`.

#### Scenario: User with no read permission gets filtered results from a tool

- **WHEN** user A (who has no read permission on object X) asks the AI a question that triggers `opencatalogi.searchCatalogues` matching object X
- **THEN** the tool MUST exclude object X from its returned list
- **AND** the LLM MUST receive a result that does not reference object X

#### Scenario: User with no write permission receives isError from a write tool

- **WHEN** user A (who has no write permission on register R) asks the AI to delete an entry and the LLM invokes a delete tool targeting register R
- **THEN** `IMcpToolProvider::invokeTool()` MUST return `{"isError": true, "error": "forbidden"}` (or equivalent)
- **AND** `McpToolsService` MUST relay this as a `tool_result` event with `"isError": true`
- **AND** the deletion MUST NOT take effect

#### Scenario: McpToolsService passes session through unchanged

- **WHEN** `McpToolsService` invokes any `IMcpToolProvider::invokeTool()` implementation
- **THEN** it MUST NOT modify the Nextcloud user context, substitute credentials, or call `\OC::$server->getUserSession()->setUser()` or equivalent impersonation methods
