---
retrofit: true
---

# Chat AI

## Purpose

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

## Notes

- `ChatController::getChatStats` queries all rows globally (no user/org filter). This may expose aggregate counts across organisations in a multi-tenant deployment. Worth reviewing against ADR-022 (OpenRegister RBAC on data).
- `ChatService::testChat` is a stub returning a static success message. The real implementation was preserved in `ChatService_ORIGINAL_2156.php` backup. This method is not covered by these REQs until the stub is replaced.
- `ConversationController::destroyPermanent` does not delete feedback (only messages + conversation), while the two-stage `destroy` path does delete feedback on the second call. This asymmetry may be unintentional.
