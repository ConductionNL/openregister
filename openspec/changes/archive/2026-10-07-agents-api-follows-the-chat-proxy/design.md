## Context

What is proxied today, read from `development`:

- `lib/Middleware/ChatCompatMiddleware.php`: `CHAT_FAMILY_CONTROLLERS` holds
  `ChatController`, `ChatStreamController`, `ChatHealthController`,
  `ConversationController` and `AgentsController`. `PAGE_SHELL_METHODS` holds
  only `page`. `beforeController()` proxies every other method of those
  controllers when `ChatProxyHandler::isProxyConfigured()` and
  `isHermiqInstalled()` are both true.
- `lib/Service/Chat/ChatProxyHandler.php`: `isProxyConfigured()` reads
  `chat.proxyTo` with default `hermiq`, so unset means on.
  `isHermiqInstalled()` calls `IAppManager::isInstalled('hermiq')`.
  `rewritePathForHermiq()` swaps `/apps/openregister/` for `/apps/hermiq/`.
  `forwardJsonRequest()` relays status, body and `Content-Type`, and returns
  null (local fallback) only on a transport failure. A 4xx or 5xx from hermiq
  is relayed, not replaced by local serving.
- The agent limits screen (`src/views/agents/AgentsIndex.vue`,
  `src/modals/agent/EditAgentLimits.vue`) calls `GET /api/agents`,
  `GET /api/agents/tools` and `PATCH /api/agents/{id}`. hermiq's
  `appinfo/routes.php` on its `development` mirrors all three
  (`agents#index`, `agents#tools`, `agents#patch`).

## Decisions

- **hermiq owns agents when present.** Decision 92: the forward of the agents
  API is intended. The requirement says so, so nobody narrows the proxy to the
  chat endpoints only.
- **No per-endpoint carve-out.** The limits screen's reads and its PATCH follow
  the same switch. Splitting them would show one engine's agents and write to
  the other's.
- **Spec-only, archived at once.** The code already does this. Tasks cite the
  code lines instead of asking for work.

## Risks

- Whether hermiq stores and enforces the tool-limit fields that
  `EditAgentLimits.vue` sends is hermiq's contract, not this spec's. If hermiq
  ignores a field, the screen shows hermiq's stored value after save. Not
  verified live in this change.
