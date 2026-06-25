---
status: done
retrofit: true
---

# Chat AI

## Purpose

@e2e exclude REST API + LLPhant adapter backend — covered by PHPUnit

Provides a conversational AI interface for OpenRegister users. Users interact with AI agents through persistent conversations that carry a history of messages. Each message exchange retrieves relevant context from registered objects and files (RAG) before querying the configured LLM. Agents are configurable AI personalities that can be scoped to an organisation and optionally restricted to their owner. This capability covers the full lifecycle: agent management, conversation management, message exchange, history retrieval, user feedback, and usage analytics.
## Requirements

### REQ-001: The system MUST process user messages through an LLM pipeline with RAG context

When a user sends a message, the system MUST: (1) resolve the target conversation — either loading an existing one by UUID or creating a new one when only an `agentUuid` is supplied; (2) verify that the requesting user owns the conversation (403 if not); (3) store the user message in the conversation's message history before querying the LLM; (4) retrieve relevant context from registered objects and Nextcloud files using the active agent's RAG configuration and any caller-supplied `ragSettings` overrides; (5) send the full message history plus retrieved context to the LLM via `ResponseGenerationHandler`; (6) store the LLM response together with source citations in the message history; and (7) return `{message, sources, timings, conversation}` to the caller. On the first exchange in a conversation, the system MUST generate a title from the user's message and deduplicate it within the (user, agent) scope.

#### Scenario: New conversation created on first message
- **GIVEN** a user sends a message with `agentUuid` set and no `conversation` UUID
- **WHEN** `ChatController::sendMessage` processes the request
- **THEN** a new conversation MUST be created, owned by the requesting user, with a generated title derived from the message
- **AND** the response MUST include the new conversation's UUID

#### Scenario: Message rejected when user does not own the conversation
- **GIVEN** user A sends a message referencing a conversation owned by user B
- **WHEN** `sendMessage` resolves the conversation
- **THEN** the system MUST return HTTP 403 without storing any message or querying the LLM

#### Scenario: RAG sources included in response
- **GIVEN** a user sends a message in an existing conversation whose agent has `searchObjects: true`
- **WHEN** the pipeline processes the message
- **THEN** `sources` in the response MUST contain the object identifiers that the LLM received as context
- **AND** those same sources MUST be persisted alongside the assistant message in the database

### REQ-002: The system MUST manage the full lifecycle of AI conversations

A conversation is a named, user-owned session that groups messages between a user and a specific agent within an organisation. The system MUST support creating, reading, updating, soft-deleting, restoring, and permanently deleting conversations, each with ownership-based access control enforced in the mapper layer.

**Create**: A conversation is created with `userId`, `organisation`, optional `agentId` or `agentUuid`, and optional `title`. If no title is supplied and an agent is identified, the system MUST generate a unique title in the (user, agent) scope.

**List**: `GET` conversations returns only conversations owned by the requesting user, filtered by organisation. Callers MAY request archived conversations with `_deleted=true`; without this flag, only active (non-soft-deleted) conversations are returned.

**Show**: Returns a single conversation by UUID including a `messageCount`. Access is denied (403) if the requesting user does not own the conversation or belong to the conversation's organisation.

**Update**: Only `title` and `metadata` MAY be updated. `userId`, `agentId`, `organisation`, and `created` are immutable and silently preserved even if the caller supplies them.

**Soft delete** (`clearHistory` / first `destroy`): Sets `deletedAt` on the conversation. The conversation remains in the database and is recoverable. Messages are NOT deleted at this stage.

**Restore**: Clears `deletedAt`, making the conversation active again with its message history intact.

**Permanent delete** (second `destroy` / `destroyPermanent`): Deletes all messages (and, for the two-stage `destroy` path, feedback) before deleting the conversation. This is irreversible.

#### Scenario: Two-stage deletion
- **GIVEN** an active conversation (no `deletedAt`)
- **WHEN** a user calls `DELETE /api/conversations/{uuid}` (first call)
- **THEN** `deletedAt` MUST be set; the response MUST include `"archived": true`
- **AND** messages MUST remain in the database

- **GIVEN** the same conversation is now soft-deleted
- **WHEN** the user calls `DELETE /api/conversations/{uuid}` again (second call)
- **THEN** all messages and feedback for the conversation MUST be deleted before the conversation record is removed
- **AND** the response MUST state permanent deletion

#### Scenario: Listing respects deleted filter
- **GIVEN** a user has 3 active and 2 archived conversations
- **WHEN** `index` is called without `_deleted`
- **THEN** exactly 3 conversations MUST be returned
- **WHEN** `index` is called with `_deleted=true`
- **THEN** exactly 2 archived conversations MUST be returned

### REQ-003: The system MUST provide paginated retrieval of conversation message history

Users MUST be able to retrieve the ordered message history for a conversation they own. Both `ChatController::getHistory` (addressed by conversation integer ID) and `ConversationController::messages` (addressed by conversation UUID) MUST enforce ownership before returning messages. Both endpoints MUST support `limit` and `offset` pagination. The response MUST include the total message count.

#### Scenario: History access denied for non-owner
- **GIVEN** user A requests message history for a conversation owned by user B
- **WHEN** either `getHistory` or `messages` is called
- **THEN** HTTP 403 MUST be returned without revealing any messages

#### Scenario: Paginated history
- **GIVEN** a conversation has 200 messages
- **WHEN** `messages` is called with `limit=50&offset=100`
- **THEN** exactly 50 messages MUST be returned, starting from position 101
- **AND** `total` in the response MUST equal 200

### REQ-004: The system MUST allow authorised users to configure AI agents

An agent is a named AI entity with a prompt persona, model configuration, tool access, and visibility settings. The system MUST provide REST CRUD operations for agents. Each agent MUST be owned by a specific user and MAY be scoped to an organisation. Agents default to private (`isPrivate: true`) and to enabling RAG on both objects and files (`searchObjects: true`, `searchFiles: true`).

**Create**: The creating user is automatically set as `owner`. The active organisation is automatically set. The caller cannot override `owner` or `organisation` (security: prevents privilege escalation).

**Read**: `index` filters by organisation and applies mapper-level RBAC so users only see agents they own or that are accessible within their organisation. `show` performs an additional per-agent access check.

**Update / Patch**: Both map to the same update logic. `organisation` and `owner` are preserved from the stored entity regardless of the caller's payload (security: prevents privilege escalation).

**Delete**: Requires that the requesting user has modification rights for the agent (owner or admin). Unauthenticated requests MUST return 403 before the RBAC check.

**Tool discovery**: `GET /api/agents/tools` returns the catalogue of all tools registered in the OpenRegister `ToolRegistry` from all apps. This list is used by the frontend agent editor to configure which tools an agent may invoke during message processing.

**Statistics**: `GET /api/agents/stats` returns aggregate counts of total, active, and inactive agents visible to the requesting user.

#### Scenario: Agent creation sets owner automatically
- **GIVEN** user A authenticates and creates a new agent with arbitrary `owner` and `organisation` in the request body
- **WHEN** `AgentsController::create` processes the request
- **THEN** the stored agent MUST have `owner = user A's ID` and `organisation = active organisation UUID`
- **AND** the caller-supplied `owner` and `organisation` values MUST be ignored

#### Scenario: Update preserves immutable fields
- **GIVEN** an agent owned by user A exists
- **WHEN** user A sends an update payload containing `owner: user B` and `organisation: other-org`
- **THEN** the updated agent MUST still have `owner = user A` and `organisation = original org`

### REQ-005: The system MUST collect user feedback on AI responses and expose chat analytics

**Feedback**: Users MUST be able to submit positive or negative feedback on individual AI messages. A feedback record MUST be tied to a specific (conversation, message, user) triple. If feedback already exists for that triple, the system MUST update the existing record rather than create a duplicate. The feedback `type` MUST be one of `positive` or `negative`; any other value MUST be rejected with HTTP 400. An optional `comment` field is accepted.

**Analytics**: `ChatController::getChatStats` returns system-wide aggregate counts of agents, conversations, and messages without user filtering. These counts are intended for administrative dashboards. No access control beyond authentication is applied.

#### Scenario: Duplicate feedback is updated, not duplicated
- **GIVEN** user A has already submitted negative feedback on message 42
- **WHEN** user A submits positive feedback on the same message
- **THEN** the existing feedback record MUST be updated to `type: positive`
- **AND** no new feedback record MUST be created

#### Scenario: Invalid feedback type is rejected
- **GIVEN** user A submits feedback with `type: "neutral"`
- **WHEN** `sendFeedback` validates the request
- **THEN** HTTP 400 MUST be returned without persisting any feedback

#### Scenario: Feedback access control via conversation ownership
- **GIVEN** user A submits feedback on a message in a conversation owned by user B
- **WHEN** `sendFeedback` checks conversation ownership
- **THEN** HTTP 403 MUST be returned without persisting any feedback

### REQ-006: The system MUST convert tool definitions to LLPhant FunctionInfo objects for LLM function calling

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

### Requirement: The system MUST expose an admin surface for reading and updating LLM provider settings

`LlmSettingsController` MUST provide endpoints to read the current LLM (Large Language Model) configuration and to update it, both as a full update and as a PATCH alias. The settings cover the embedding and chat model configuration for the OpenAI, Ollama, and Fireworks providers. On update, when a provider's `embeddingModel`, `model`, or `chatModel` is supplied as a model *object* (`{id, ...}`) by the frontend dropdown, the controller MUST extract the `id` before persisting so that only the model identifier string is stored. Read MUST delegate to `SettingsService::getLLMSettingsOnly()`; update MUST delegate to `SettingsService::updateLLMSettingsOnly()`. `patchLLMSettings` MUST be a behavioural alias of `updateLLMSettings` (registered separately so the PATCH verb routes correctly).

#### Scenario: Read current LLM settings
- **GIVEN** an admin requests the LLM settings
- **WHEN** `getLLMSettings` is called
- **THEN** the response MUST contain the LLM-only settings returned by `SettingsService::getLLMSettingsOnly()`
- **AND** on any thrown exception the response MUST be HTTP 500 with `{error}`

#### Scenario: Model object is reduced to its id on update
- **GIVEN** the frontend submits `fireworksConfig.embeddingModel = {id: "nomic-embed-text", name: "..."}`
- **WHEN** `updateLLMSettings` processes the payload
- **THEN** the persisted value of `fireworksConfig.embeddingModel` MUST be the string `"nomic-embed-text"`
- **AND** the same id-extraction MUST apply to `openaiConfig.model`, `openaiConfig.chatModel`, `ollamaConfig.model`, and `ollamaConfig.chatModel`

#### Scenario: PATCH is an alias of update
- **GIVEN** a PATCH request to the LLM settings endpoint
- **WHEN** `patchLLMSettings` is invoked
- **THEN** it MUST produce the same result as `updateLLMSettings` for the same payload

### Requirement: The system MUST allow testing LLM providers against supplied-but-unsaved configuration

`LlmSettingsController` MUST let an admin verify a provider before saving it. `testEmbedding` MUST accept `provider`, `config`, and optional `testText`, and delegate to `VectorizationService::testEmbedding()`. `testChat` MUST accept `provider`, `config`, and optional `testMessage`, and delegate to the `ChatService::testChat()`. Both MUST reject a missing `provider` or a non-array/empty `config` with HTTP 400, and MUST map the service result's `success` flag to HTTP 200 (true) or HTTP 400 (false). `getOllamaModels` MUST query the configured Ollama instance's `/api/tags` endpoint and return a name-sorted list of `{id, name, description, size, modified}` models, returning `{success: false, models: []}` on connection failure or non-200 upstream status rather than throwing.

#### Scenario: Test embedding requires provider and config
- **GIVEN** a `testEmbedding` request with an empty `provider`
- **WHEN** the controller validates input
- **THEN** the response MUST be HTTP 400 with `{success: false, error: "Missing provider"}`
- **AND** a request with a non-array or empty `config` MUST return HTTP 400 with `error: "Invalid config"`

#### Scenario: Test result status maps to HTTP code
- **GIVEN** a valid `testChat` request whose `ChatService::testChat()` returns `{success: false, ...}`
- **WHEN** the controller builds the response
- **THEN** the HTTP status MUST be 400
- **AND** when the service returns `{success: true, ...}` the status MUST be 200

#### Scenario: Ollama model discovery degrades gracefully
- **GIVEN** the configured Ollama URL is unreachable
- **WHEN** `getOllamaModels` runs
- **THEN** the response MUST be `{success: false, error: "Failed to connect to Ollama: ...", models: []}`
- **AND** no exception MUST propagate to the framework

### Requirement: The system MUST support embedding-store maintenance from the admin surface

`LlmSettingsController` MUST expose embedding-store maintenance operations. `checkEmbeddingModelMismatch` MUST delegate to `VectorizationService::checkEmbeddingModelMismatch()` and report whether stored vectors exist and whether the active embedding model differs from the one that generated them (so the operator can decide whether regeneration is required). `clearAllEmbeddings` MUST delegate to `VectorizationService::clearAllEmbeddings()` and return the deletion result, mapping a `success: false` service result to HTTP 500.

#### Scenario: Detect embedding model mismatch
- **GIVEN** stored embeddings were generated by a now-changed embedding model
- **WHEN** `checkEmbeddingModelMismatch` is called
- **THEN** the response MUST reflect `has_vectors` and `mismatch` as reported by `VectorizationService`
- **AND** on exception the response MUST be HTTP 500 with `{has_vectors: false, mismatch: false, error}`

#### Scenario: Clear all embeddings
- **GIVEN** an admin clears the embedding store
- **WHEN** `clearAllEmbeddings` is called and the service returns `{success: true, deleted: N}`
- **THEN** the response MUST be HTTP 200 with the deletion result
- **AND** when the service returns `{success: false}` the response MUST be HTTP 500

### Requirement: Semantic and hybrid vector search endpoints
The system MUST expose endpoints for semantic (vector-embedding) search and hybrid
(keyword + vector) search over registered objects. `SolrController::semanticSearch`
embeds the query and retrieves nearest-neighbour matches; `SolrController::hybridSearch`
combines Solr keyword scoring with vector similarity under caller-supplied weights.
`SettingsController::semanticSearch` and `SettingsController::hybridSearch` are facade
copies of these endpoints that delegate to `VectorizationService`. An empty or
whitespace-only query MUST return HTTP 400, and both endpoints MUST attach a `timestamp`
to the response.

#### Scenario: Semantic search rejects empty query
- **GIVEN** a caller invokes `semanticSearch` with a blank query
- **WHEN** the controller validates input
- **THEN** it MUST return HTTP 400 with a "Query parameter is required" message

#### Scenario: Hybrid search combines keyword and vector results
- **GIVEN** a caller invokes `hybridSearch` with `weights: {solr: 0.5, vector: 0.5}`
- **WHEN** `VectorizationService::hybridSearch` runs
- **THEN** the response MUST merge keyword and vector results and include `query` and `timestamp`

### Requirement: Vectorization and embedding operations endpoints
The system MUST expose admin/operations endpoints for managing object vectorization and
inspecting embedding state. `SolrController` provides `getVectorStats` and
`getVectorizationStats` (coverage/health metrics), `testVectorEmbedding` (probes the
configured embedding provider), `vectorizeObject` (embeds a single object, optional
provider override), and `bulkVectorizeObjects` (batch embedding).

#### Scenario: Test embedding provider connectivity
- **WHEN** `testVectorEmbedding` runs
- **THEN** it MUST probe the configured embedding provider and report whether embeddings can be generated

#### Scenario: Vectorize a single object
- **GIVEN** an object id and an optional provider override
- **WHEN** `vectorizeObject` runs
- **THEN** it MUST generate and store the object's embedding and report the outcome

#### Scenario: Report vectorization coverage
- **WHEN** `getVectorizationStats` runs
- **THEN** it MUST return coverage statistics describing how many objects currently carry embeddings

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

### Requirement: Token-by-token streaming SSE event

The system SHALL emit one `event: token` SSE frame per LLM token
delta received from a streaming-capable LLPhant provider, written
to the wire as it arrives — NOT buffered until the full response
completes. Each frame's `data:` payload is a JSON object with at
minimum a `delta` field carrying the new token string.

The token event MUST NOT carry the running concatenation of all
tokens so far — only the new delta. Concatenation is the consumer's
responsibility; the widget's `useAiChatStream.js` already appends
to `streamState.currentText`.

The system SHALL detect provider streaming capability at call time
via `method_exists($chatInstance, 'generateStreamOfText')`. When
detection fails — either the method is absent OR the method throws
`LLPhant\Exception\MissingFeatureException` at runtime — the system
MUST fall back to the existing blocking `generateText()` surface and
emit zero `token` frames. This is the "non-streaming-provider
degradation" clause already established by the orchestrator change.

#### Scenario: Streaming-capable provider emits one token frame per delta
- **GIVEN** an authenticated user POSTs `/api/chat/stream` with `{"message":"count 1 to 5"}` against an Ollama agent whose `OllamaChat` exposes `generateStreamOfText`
- **WHEN** the LLM streams the deltas `["1", " 2", " 3", " 4", " 5"]`
- **THEN** the SSE response MUST contain exactly five `event: token` frames in order
- **AND** each frame's data payload MUST be `{"delta":"<the-delta>"}`
- **AND** a single `event: final` MUST follow with `fullText` equal to the concatenation

#### Scenario: Non-streaming provider degrades to one final frame
- **GIVEN** a Fireworks agent whose `FireworksChat` has no `generateStreamOfText` method
- **WHEN** the same request runs
- **THEN** zero `event: token` frames MUST appear
- **AND** exactly one `event: final` frame MUST be emitted with the full response in `fullText`
- **AND** the SSE response MUST otherwise match the contract (`event: heartbeat` immediately after headers, `Content-Type: text/event-stream`, etc.)

#### Scenario: Streaming method throws MissingFeatureException at runtime
- **GIVEN** a provider whose `generateStreamOfText` exists but throws `LLPhant\Exception\MissingFeatureException` on first call (e.g. a future Fireworks integration that ships the method but hasn't enabled it)
- **WHEN** the request runs
- **THEN** the system MUST catch the exception inside the response handler
- **AND** transparently fall back to `generateText()`
- **AND** emit zero `token` frames followed by one `final` frame
- **AND** log the fallback decision at `info` level for ops visibility

### Requirement: Tool-call and tool-result SSE events

The system SHALL emit one `event: tool_call` SSE frame each time the
LLM invokes an MCP tool during a streaming response, and one
`event: tool_result` frame after `McpToolsService::callTool` returns
for that invocation. The frames MUST surface as they happen, not
batched into the `final` frame.

The `tool_call` frame's data payload is a JSON object with
`toolId` (the full namespaced id, e.g. `decidesk.createMeeting`)
and `arguments` (the assembled JSON object the LLM passed).

The `tool_result` frame's data payload is a JSON object with
`toolId` (matching the prior `tool_call`), `result` (the inner
JSON the tool returned), and `isError` (boolean — true when the
tool returned an error envelope).

Partial tool-call argument streams (LLPhant emits the function
name and JSON argument chunks separately) MUST be buffered per
LLPhant frame id and emitted as ONE `tool_call` SSE frame on the
LLM's `finish_reason=tool_calls` signal for that id. Per-argument
streaming is NOT a contract guarantee.

When no streaming surface is available (non-streaming-provider
degradation), the system MUST NOT emit `tool_call` or `tool_result`
frames; the tool invocations still happen but their outcomes
surface only in the `final` frame's `fullText`. The widget's
`CnAiMessageList` accepts that mode.

#### Scenario: Streaming tool call surfaces both frames
- **GIVEN** an Ollama agent + the LLM decides to call `decidesk.createMeeting` with arguments `{"title":"sync","scheduledDate":"2026-07-02T14:00:00+02:00"}`
- **WHEN** the LLM emits the tool-call frame mid-stream
- **THEN** the SSE response MUST contain one `event: tool_call` frame with `{"toolId":"decidesk.createMeeting","arguments":{"title":"sync","scheduledDate":"2026-07-02T14:00:00+02:00"}}`
- **AND** after `McpToolsService::callTool` returns, exactly one `event: tool_result` frame MUST follow with `{"toolId":"decidesk.createMeeting","result":{...},"isError":false}`
- **AND** subsequent `token` frames may interleave as the LLM resumes the response

#### Scenario: Partial tool-call argument stream is buffered to one frame
- **GIVEN** an LLM that streams the tool call as 3 partial frames: `{"name":"decidesk.createMeeting"}`, `{"arguments_delta":"{\"title\":\""}`, `{"arguments_delta":"sync\"}","finish_reason":"tool_calls"}`
- **WHEN** the streaming response is processed
- **THEN** the SSE response MUST contain exactly one `event: tool_call` frame for that invocation (not three)
- **AND** the frame's `arguments` payload MUST be the assembled `{"title":"sync"}`

#### Scenario: Tool error envelope sets isError true
- **GIVEN** an MCP tool that returns `{"isError":true,"error":"forbidden","message":"You are not signed in."}`
- **WHEN** that tool is invoked during a streaming response
- **THEN** the `tool_result` SSE frame's payload MUST have `isError:true` AND `result` MUST contain the full inner envelope verbatim

### Requirement: Periodic heartbeat during the in-flight call

The system SHALL emit `event: heartbeat` frames at most 15 seconds
apart for the duration of any `/api/chat/stream` response, measured
from the most recently emitted event of any type (including the
initial post-headers heartbeat and the eventual `final` or `error`).

The heartbeat interleaves with normal frames: it MUST appear
immediately before the next outgoing frame (`token`, `tool_call`,
`tool_result`, or `final`) when the wall-clock interval since the
last event exceeds 15.0 seconds. It MUST reset the wall-clock and
not fire again until another 15s elapses.

In the degenerate case where the LLM call produces no yield points
at all (the synchronous-fallback path on a non-streaming provider),
the system MAY NOT emit periodic heartbeats — the initial post-
headers heartbeat is the only one, and the proxy timeout enforces
the connection-lifetime ceiling. The widget treats this as a
contract-conformant degraded mode.

The 15-second interval is fixed per the design D3; no operator-
configurable override is required by this change.

#### Scenario: 35-second LLM call emits at least two interleaved heartbeats
- **GIVEN** an Ollama agent and a prompt that causes the LLM to take 35s before emitting any token
- **WHEN** the system streams the response
- **THEN** between the initial post-headers heartbeat and the first `token` frame, at least two additional `heartbeat` frames MUST be emitted (one around t=15s, one around t=30s)
- **AND** each subsequent heartbeat MUST be emitted strictly before the next non-heartbeat frame

#### Scenario: Sub-15s tokens never trigger an interleaved heartbeat
- **GIVEN** a steady token stream with each delta arriving within 5s of the previous one
- **WHEN** 10 such tokens stream
- **THEN** zero additional heartbeat frames MUST appear between them (the initial post-headers heartbeat is the only one)
- **AND** the SSE response ends with `final` with no trailing heartbeat

#### Scenario: Synchronous-fallback path emits only the initial heartbeat
- **GIVEN** a non-streaming provider on a request that takes 20s
- **WHEN** the system processes the request
- **THEN** the SSE response MUST contain exactly one `heartbeat` frame (the initial one immediately after headers)
- **AND** one `final` frame
- **AND** zero additional heartbeats during the synchronous wait — the degradation is contract-conformant

## Notes

- `ChatController::getChatStats` queries all rows globally (no user/org filter). This may expose aggregate counts across organisations in a multi-tenant deployment. Worth reviewing against ADR-022 (OpenRegister RBAC on data).
- `ChatService::testChat` is a stub returning a static success message. The real implementation was preserved in `ChatService_ORIGINAL_2156.php` backup. This method is not covered by these REQs until the stub is replaced.
- `ConversationController::destroyPermanent` does not delete feedback (only messages + conversation), while the two-stage `destroy` path does delete feedback on the second call. This asymmetry may be unintentional.
- REQ-006: the optional `format` field on a function-parameter definition is passed through verbatim to the `Parameter` constructor (e.g. OpenAI's `format: 'date-time'`); default `null`. `enum` is passed through verbatim; default `[]`. `required` defaults to `[]` when absent. The method is annotated `@SuppressWarnings(PHPMD.CyclomaticComplexity)` / `(NPathComplexity)` because the parameter-type fan-out is irreducible without restructuring LLPhant's `Parameter` API.
- `ChatController::page` returns a `TemplateResponse` for the chat SPA but is NOT registered in `appinfo/routes.php` and is unreachable. Surfaced by a 2026-05-24 Bucket 2a scan; should be deleted in a cleanup change rather than retrofit-specced.
- The following methods were surfaced by the 2026-05-24 Bucket 2a scan but are covered by class-level `@spec` annotations pointing to the in-flight `ai-chat-companion-orchestrator` (`health-probe-endpoint`, `sse-streaming-endpoint`) and `ai-chat-companion-streaming` (token, tool-call, heartbeat events) changes: `ChatHealthController::health`; all `ChatStreamController` helpers (`clearOutputBuffers`, `emitSseHeaders`, `emitAndExit`, `safeShutdown`, `emitSseEvent`, `now`, `forwardWithHeartbeat`, `pickFallbackAgentForUser`); all `StreamYieldChannel::on*` and `emit*` methods. Their REQs land in this spec when those changes archive.
- Vue UI helpers (`ChatSideBar::isActive`, `ChatIndex::showAgentSelector`) are out of scope for backend spec retrofit — they are presentational logic (CSS class binding, dialog open-toggle) with no server-observable behaviour.
