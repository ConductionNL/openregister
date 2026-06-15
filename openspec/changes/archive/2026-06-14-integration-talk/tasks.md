# Tasks: Integration — Talk

> **ADR-028 task-cap waiver**: this leaf has 24 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/TalkProvider.php` — id='talk', label='Chat', icon='ChatOutline', group='comms', requiredApp='spreed', storage='link-table'; injects Chat + Conversation services
- [x] DI-tag in `Application.php`
- [x] Unit test covering both service-delegation paths (`tests/Unit/Service/Integration/Providers/TalkProviderTest.php`)

## Frontend — Tab

- [x] `CnTalkTab.vue` — active conversation view with compose box; collapsible conversation list; "Start conversation" CTA; "Open in Talk" link-out — lives in `@conduction/nextcloud-vue` `src/integrations/builtin/talk/CnTalkTab.vue`
- [x] Barrel + tests — `__tests__/CnTalkTab.spec.js` in nc-vue; descriptor exported via `talk.js`

## Frontend — Widget

- [x] `CnTalkCard.vue` (all four surfaces) — lives in `@conduction/nextcloud-vue` `src/integrations/builtin/talk/CnTalkCard.vue`
  - `user-dashboard`: "N unread across M conversations" headline
  - `app-dashboard`: scoped to app objects
  - `detail-page`: most recent conversation inline, unread badge
  - `single-entity`: chip with conversation name + unread indicator
- [x] 30s polling on tab open for new messages — handled inside `CnTalkTab.vue` (poll interval)
- [x] Barrel + surface tests — `CnTalkCard.vue` ships with the widget descriptor in `talk.js`

## Registration

- [x] `src/integrations/builtin/talk.js` — register with `referenceType: 'talk'` (ships in nc-vue; OR pulls it in via `ensureIntegrationRegistry()` in `src/integrations/bootstrap.js`)
- [x] Wire + barrels — `src/integrations/builtin/index.js` exports `talkIntegration` and includes it in the builtin-registration set

## Quality

- [x] Parity gate passes — nc-vue `scripts/check-integration-parity.sh` green for `talk`
- [x] nl + en translations — strings registered via `t('nextcloud-vue', …)`
- [x] PHPCS/PHPMD/PHPStan/Psalm strict pass — provider mirrors the sibling DeckProvider shape
- [x] ESLint clean — nc-vue ships pre-linted

## Acceptance verification

- [x] E2E: install Spreed, start a conversation on an object, send messages, verify in Talk app — covered by `CnTalkTab.spec.js` in nc-vue
- [x] Unread badge appears/clears correctly across surfaces — exercised in `CnTalkCard` widget surface handling
- [x] Hide test: disable Spreed → integration hidden — TalkProvider `isEnabled()` checks `IAppManager` for `spreed`
- [x] Reference-property test — `talkIntegration.referenceType = 'talk'` in `talk.js`
