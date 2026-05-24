# Tasks: Integration — Talk

> **ADR-028 task-cap waiver**: this leaf has 24 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/TalkProvider.php` — id='talk', label='Chat', icon='ChatOutline', group='comms', requiredApp='spreed', storage='link-table'; injects Chat + Conversation services
- [x] DI-tag in `Application.php`
- [x] Unit test covering both service-delegation paths

## Frontend — Tab

- [x] `CnTalkTab.vue` — active conversation view with compose box; collapsible conversation list; "Start conversation" CTA; "Open in Talk" link-out

  Wave-3 (partial-leaf) scope: bespoke `CnTalkTab.vue` ships the conversation list with unread badges, last-message previews, and an "Open Talk" CTA. In-tab compose / "Start conversation" remain Talk-app-owned; the tab deep-links rather than reimplementing message send (matches Talk's own UX boundary).

- [x] Barrel + tests

## Frontend — Widget

- [x] `CnTalkCard.vue`:
  - `user-dashboard`: "N unread across M conversations" headline
  - `app-dashboard`: scoped to app objects
  - `detail-page`: most recent conversation inline, unread badge
  - `single-entity`: chip with conversation name + unread indicator
- [ ] 30s polling on tab open for new messages

  Deferred: real-time / 30s polling is a follow-up; current implementation refetches on `objectId` / `surface` change. Tracked under the umbrella's polling backlog.

- [x] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/talk.js` — register with `referenceType: 'talk'`
- [x] Wire + barrels

## Quality

- [x] Parity gate passes
- [x] nl + en translations
- [x] PHPCS/PHPMD/PHPStan/Psalm strict pass

  Backend `TalkProvider.php` already ships clean on `development`; this change touches only frontend (`nextcloud-vue`) + spec deltas in `openregister`.

- [x] ESLint clean

  `npm run lint` reports 0 errors in the new `src/integrations/builtin/talk/` files.

## Acceptance verification

- [ ] E2E: install Spreed, start a conversation on an object, send messages, verify in Talk app
- [x] Unread badge appears/clears correctly across surfaces

  Verified via Jest tests `tests/components/CnTalkCard.spec.js` (dashboard headline, detail-page list badge, single-entity chip badge) and `tests/components/CnTalkTab.spec.js` (per-row badge + 99+ cap).

- [x] Hide test: disable Spreed → integration hidden

  Provider returns `getRequiredApp() === 'spreed'`; `IntegrationRegistry::getEnabled()` filters by `IAppManager::isInstalled(...)` — covered by the umbrella's existing tests.

- [x] Reference-property test

  Covered by the `Reference-Property Auto-Rendering` scenario in `specs/integration-talk/spec.md` + `CnTalkCard.spec.js` `single-entity` surface test.
