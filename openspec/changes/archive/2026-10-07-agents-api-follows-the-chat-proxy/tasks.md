## 1. Behaviour already on development (cited, no work)

- [x] 1.1 `AgentsController` is a chat-family controller: `lib/Middleware/ChatCompatMiddleware.php`, `CHAT_FAMILY_CONTROLLERS`.
- [x] 1.2 Only `page()` is excluded from the proxy: `ChatCompatMiddleware::PAGE_SHELL_METHODS`, checked in `resolveHermiqPath()`.
- [x] 1.3 Unset `chat.proxyTo` means proxy on, any other value than `hermiq` means off: `lib/Service/Chat/ChatProxyHandler.php`, `isProxyConfigured()`.
- [x] 1.4 hermiq presence check before any call: `ChatProxyHandler::isHermiqInstalled()`.
- [x] 1.5 The limits screen's calls (`GET /api/agents`, `GET /api/agents/tools`, `PATCH /api/agents/{id}`) are in `src/views/agents/AgentsIndex.vue` and `src/modals/agent/EditAgentLimits.vue`, and hermiq mirrors all three in its `appinfo/routes.php`.

## 2. Spec

- [x] 2.1 MODIFIED REQ-007 in `specs/chat-ai/spec.md` with every existing scenario and the two agents scenarios.
- [x] 2.2 `openspec validate agents-api-follows-the-chat-proxy --strict`.
- [x] 2.3 Archive the change into `openspec/specs/chat-ai/spec.md`.
