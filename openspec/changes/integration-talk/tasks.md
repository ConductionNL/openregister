# Tasks: Integration — Talk

> **ADR-028 task-cap waiver**: this leaf has 24 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/TalkProvider.php` — id='talk', label='Chat', icon='ChatOutline', group='comms', requiredApp='spreed', storage='link-table'; injects Chat + Conversation services
- [x] DI-tag in `Application.php`
- [x] Unit test covering both service-delegation paths

## Frontend — Tab

- [ ] `CnTalkTab.vue` — active conversation view with compose box; collapsible conversation list; "Start conversation" CTA; "Open in Talk" link-out (separate nextcloud-vue repo PR)
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnTalkCard.vue`:
  - `user-dashboard`: "N unread across M conversations" headline
  - `app-dashboard`: scoped to app objects
  - `detail-page`: most recent conversation inline, unread badge
  - `single-entity`: chip with conversation name + unread indicator
  (separate nextcloud-vue repo PR per design cross-repo note)
- [ ] 30s polling on tab open for new messages
- [ ] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/talk.js` — register with `referenceType: 'talk'`
- [x] Wire + barrels

## Quality

- [x] Parity gate passes (all 14 hydra gates green)
- [ ] nl + en translations (frontend tab/widget scope — separate repo)
- [ ] PHPCS/PHPMD/PHPStan/Psalm strict pass (phpcs not available in build env; syntax check + gate-1 pass)
- [ ] ESLint clean (no Vue components in this PR)

## Acceptance verification

- [ ] E2E: install Spreed, start a conversation on an object, send messages, verify in Talk app
- [ ] Unread badge appears/clears correctly across surfaces
- [x] Hide test: disable Spreed → integration hidden (implemented via IAppManager.isInstalled check)
- [ ] Reference-property test
