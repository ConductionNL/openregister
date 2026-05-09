# Integration: Talk (Chat)

## Problem

`ChatController` (881 LOC) + `ConversationController` (958 LOC) expose Talk chat/rooms linked to OR objects. No UI surfaces this in a unified way. Object-scoped conversations are invisible to case handlers.

## Context

- **Backend shipped:** [ChatController](openregister/lib/Controller/ChatController.php), [ConversationController](openregister/lib/Controller/ConversationController.php) — separate chat and conversation/room concerns
- **Required NC app:** `spreed` (Talk's internal app id)
- **Storage:** `link-table`
- **Key decision from Q3:** ONE provider `talk` routes both controllers internally (not two separate providers)
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

Single `TalkProvider` with id `talk` that delegates to both Chat and Conversation controllers behind the scenes. The tab is chat-focused by default but exposes room management. The widget has two sub-modes visible via surface selection.

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
- [ ] Parity gate passes; nl+en done
