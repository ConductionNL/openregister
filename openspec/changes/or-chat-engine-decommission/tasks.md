# Tasks: or-chat-engine-decommission

## 1. Proxy-by-default (PHP)

- [x] 1.1 `lib/Service/Chat/ChatProxyHandler.php`: `isProxyEnabled()` treats unset `chat.proxyTo` as `hermiq` (default flips from `''`); any other explicit value (e.g. `off`) disables the proxy leg. Update the class docblock + `ChatCompatMiddleware` docblock ("OFF by default" → "ON by default, opt-out"). Update `tests/Unit/Service/Chat/ChatProxyHandlerTest.php` + `tests/Unit/Middleware/ChatCompatMiddlewareTest.php` default-config expectations; add an explicit opt-out case

## 2. getChatStats multi-tenant leak (PHP)

- [x] 2.1 `lib/Controller/ChatController.php::getChatStats()`: when `$organisationUuid === null`, return the zero-count response immediately — never execute unscoped counts. Add/adjust a unit test asserting zeros and no unscoped query

## 3. Remove OR chat/agents SPA (frontend)

- [x] 3.1 `src/manifest.json`: drop the `chat` + `agents` pages and their navigation entries; `src/registry.js`: drop `ChatIndex` + `AgentsIndex` loaders
- [x] 3.2 Delete `src/views/chat/`, `src/views/agents/`, `src/sidebars/chat/` (unwire `SideBars.vue`), `src/modals/agent/` (unwire `Modals.vue`)
- [x] 3.3 Remove `src/store/modules/conversation.ts` + `src/store/modules/agent.js` and their `store.js` exports; strip conversation/agent bootstrapping from `src/services/AppInitializationService.js`; delete `src/entities/{message,conversation,agent}/` and their `entities/index.js` exports — after grepping each for remaining consumers and unwiring any found
- [x] 3.4 Remove the `ui#chat` route from `appinfo/routes.php` and `UiController::chat()` (gate: route-reachability both directions)

## 4. Validate

- [x] 4.1 Rebuild dist (`npm ci && npm run build`), commit `js/` per repo convention
- [x] 4.2 `composer check:strict` + PHPUnit green; hydra gates (route-reachability, orphaned-write, spec-coverage) pass; `npm run lint` green
