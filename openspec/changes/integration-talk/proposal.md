# Integration: Talk (Chat)

## Problem

`ChatController` (881 LOC) + `ConversationController` (958 LOC) expose Talk chat/rooms linked to OR objects. No UI surfaces this in a unified way. Object-scoped conversations are invisible to case handlers. Today this leaf is **partial** per the 2026-05-24 registry audit — the backend uses `OCA\Talk\Manager::getRoomsForUser` and returns real conversations, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnTalkTab` / `CnTalkCard` (conversation list). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like PipelinQ and Procest have no working integration UI path and reinvent chat-attachment locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: Uses `OCA\Talk\Manager::getRoomsForUser` — returns real linked conversations
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (conversation list)
- **Target NC class(es)**: `OCA\Talk\Manager` (already imported in `ChatController` / `ConversationController`)
- **Storage strategy**: `link-table`
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Key decision from Q3:** ONE provider `talk` routes both controllers internally (not two separate providers)

## Proposed Solution

Single `TalkProvider` with id `talk` that delegates to both Chat and Conversation controllers behind the scenes. The tab is chat-focused by default but exposes room management. The widget has two sub-modes visible via surface selection. Provider imports `OCA\Talk\Manager` (which both controllers already use) and falls back to `IntegrationHealth::missingApp('spreed')` when NC Talk is not installed.

## Scope

**In scope:** Unified `TalkProvider`, `CnTalkTab` (chat-first with rooms-aware subtabs), `CnTalkCard` (compact chat-count on dashboards, conversation detail on detail-page, chip on single-entity), registration, tests, nl+en, spec delta.

**Out of scope:** Modifying Chat/Conversation controllers; Talk audio/video UI (Talk app owns this); per-message edit/reactions (out of scope, goes to Talk).

## Acceptance criteria

- [ ] Talk tab appears when Spreed installed + schema has `talk` in linkedTypes
- [ ] Tab shows linked conversations with unread counts
- [ ] User can start a new conversation scoped to the object
- [ ] User can send messages in the tab (basic compose)
- [ ] Widget shows unread count on dashboards
- [ ] Detail-page widget shows the most recent conversation inline
- [ ] Reference-property `referenceType: 'talk'` renders conversation chip
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Talk\Manager` import for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('spreed')` when NC Talk absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnTalkTab` + `CnTalkCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
