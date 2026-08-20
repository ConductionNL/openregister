---
kind: code
depends_on: []
---

# Proposal: or-chat-engine-decommission

## Why

The agent engine's canonical home is Hermiq (hydra ADR-034 Amendment 2026-07-05). All predecessors have shipped: hermiq's engine port is merged (gated on `hermiq`.`engine.enabled`), the nc-vue widget default flipped to `hermiq` (`chat-appid-default-flip`), and OR's compat window exists (#305: deprecation headers + opt-in proxy). OpenRegister however still *presents* itself as the chat product — it ships its own AI Chat + Agents SPA pages, and its engine still answers `/api/chat/*` first because the #305 proxy is opt-in (`chat.proxyTo` defaults to empty). This change decommissions OR's chat product surface: Hermiq becomes the default answerer, OR's own chat/agents UI is removed, and the known `getChatStats` multi-tenant leak is fixed in the remaining fallback code.

**Deliberate non-goal — full engine deletion (plan §7.4 step 7) is a follow-up, not this change**, because three hard dependencies still exist at HEAD:
1. hermiq's `MigrateAgentData` repair step reads the legacy `openregister_agents/conversations/messages/feedback` tables — they must survive until the migration window closes;
2. `Service/Chat/StreamYieldChannel` is load-bearing for OR's MCP streaming (`Tool/StreamingToolInstanceWrapper`), a surface under active parallel development;
3. `Settings/LlmSettingsController::testChat` and OR's vectorization/RAG stack share the LLM plumbing.
The follow-up deletion change is filed as an issue on merge of this change.

## What Changes

- **Proxy-by-default**: `ChatProxyHandler` treats `hermiq` as the default `chat.proxyTo` value (previously empty/off). OR's chat/conversation/agents API routes are now answered by Hermiq via the #305 middleware unless an operator explicitly opts out (`occ config:app:set openregister chat.proxyTo --value=off`). The #305 fail-open behaviour is preserved: if hermiq is uninstalled/unreachable, the request falls through to OR's local engine unchanged.
- **OR chat/agents SPA removed**: the `chat` and `agents` manifest pages + navigation entries, `ChatIndex.vue`, `AgentsIndex.vue`, `ChatSideBar.vue`, `EditAgent.vue`/`DeleteAgent.vue` modals, the `conversation`/`agent` store modules, `message`/`conversation`/`agent` entities, their registry/Modals/SideBars/store/AppInitializationService wiring, and the `ui#chat` route + `UiController::chat()`. Users chat through the fleet-wide AI companion widget (backed by hermiq); agents are managed in hermiq's Agent UI.
- **`getChatStats` multi-tenant leak fixed**: when no active organisation resolves, counts return zeros instead of silently falling back to instance-wide totals (the fallback engine keeps running during the compat window, so the leak is fixed in place rather than deleted).
- Frontend dist rebuilt.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `chat-ai`: OR's chat REST surface becomes a proxied compat window by default (Hermiq answers); OR's own chat/agents UI requirements are removed; usage-stats requirement gains an explicit no-organisation-resolves = zeros rule.

## Impact

- **PHP**: `lib/Service/Chat/ChatProxyHandler.php` (default flip), `lib/Controller/ChatController.php` (stats scoping), `lib/Controller/UiController.php` + `appinfo/routes.php` (`ui#chat` removal).
- **Frontend**: removal of `src/views/chat/`, `src/views/agents/`, `src/sidebars/chat/`, `src/modals/agent/`, `src/store/modules/{conversation.ts,agent.js}`, `src/entities/{message,conversation,agent}/` + wiring in `manifest.json`, `registry.js`, `Modals.vue`, `SideBars.vue`, `store.js`, `AppInitializationService.js`.
- **API consumers**: no route is removed; behaviour for API callers is #305's proxy semantics, now on by default.
- **Operators**: deployments without hermiq keep working via fall-through; deployments with hermiq get one answerer (hermiq) for chat data from both entry points.
- **Rollback**: `occ config:app:set openregister chat.proxyTo --value=off` restores local-engine answering; the UI removal only rolls back by revert.
