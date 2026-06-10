# Tasks: Integration — Talk

> **ADR-028 task-cap waiver**: this leaf has 24 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/TalkProvider.php` — id='talk', label='Chat', icon='ChatOutline', group='comms', requiredApp='spreed', storage='link-table'; injects Chat + Conversation services
- [~] DI-tag in `Application.php` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test covering both service-delegation paths — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnTalkTab.vue` — active conversation view with compose box; collapsible conversation list; "Start conversation" CTA; "Open in Talk" link-out — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnTalkCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: "N unread across M conversations" headline
  - `app-dashboard`: scoped to app objects
  - `detail-page`: most recent conversation inline, unread badge
  - `single-entity`: chip with conversation name + unread indicator
- [~] 30s polling on tab open for new messages — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/talk.js` — register with `referenceType: 'talk'` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire + barrels — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate passes — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] nl + en translations — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] PHPCS/PHPMD/PHPStan/Psalm strict pass — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] ESLint clean — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: install Spreed, start a conversation on an object, send messages, verify in Talk app — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unread badge appears/clears correctly across surfaces — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test: disable Spreed → integration hidden — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
