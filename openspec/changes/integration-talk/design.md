# Design: Integration — Talk

> Umbrella decisions apply.

## Approach

Single `TalkProvider` internally injects both `ChatService` and `ConversationService` (wrapping existing controllers). Tab uses a sub-tab or expand/collapse pattern: conversations list at top, active conversation chat below.

## Architecture Decisions

### AD-1: One provider routes both controllers (per Q3)

**Decision**: `TalkProvider` has a single id `talk`. Internally it delegates `list()` to `ConversationController` logic and chat operations to `ChatController` logic. From the registry's perspective it's one integration with one tab and one widget.

**Why**: User explicitly chose "one provider routes both." Cleaner UX — the distinction between "conversation" and "chat" is Talk-internal and not worth exposing as two separate integration tabs.

**Trade-off**: Provider class is slightly larger than single-responsibility providers. Acceptable given the user's express preference.

### AD-2: Tab is chat-first with rooms-aware structure

**Decision**: Tab default view is the most recent conversation + message compose box. Secondary affordances: conversation list (select a different one), start new conversation, jump to Talk app for full features.

**Why**: The 90% case is "continue the ongoing object discussion." Dropping the user into the active conversation matches that. Power users who need to juggle rooms click through to Talk.

**Trade-off**: Object with no conversation yet shows an empty state + "Start conversation" CTA — one extra click. Acceptable.

### AD-3: Unread count is the dashboard-surface primary signal

**Decision**: `CnTalkCard` on `user-dashboard` / `app-dashboard` renders "N unread messages across M conversations" as the headline; detail on click.

**Why**: Dashboards are glance surfaces. Unread count is the single number that answers "does this need my attention?"

**Trade-off**: Users wanting to read specific messages click through. Acceptable — dashboards aren't chat clients.

## Files Affected

### New files — Backend

| File | Purpose |
|---|---|
| `lib/Service/Integration/Providers/TalkProvider.php` | Delegates to Chat + Conversation services |
| Unit test |

### Modified — Backend

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | DI-tag |

### New files — Frontend

| File | Purpose |
|---|---|
| `CnTalkTab/CnTalkTab.vue` | Chat-first + rooms-aware |
| `CnTalkCard/CnTalkCard.vue` | 4 surfaces with unread-count headline |
| `src/integrations/builtin/talk.js` | Registration |
| Barrels + tests |

## Risks

| Risk | Mitigation |
|---|---|
| Tab becomes a mini Talk client and duplicates features | Hard scope: read-only conversation listing + simple compose. Anything else → link to Talk app |
| Real-time updates (new messages) require WebSocket/polling | Polling every 30s on tab open; Talk's own WebSocket integration out of scope (future) |
| Guest users in conversations | Talk handles this; provider just surfaces what Talk returns |
| Conversation count scales badly | Link table index on object_uuid; pagination at N=20 default |
