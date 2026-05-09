# Tasks: Integration — Talk

## Backend

- [ ] Create `lib/Service/Integration/Providers/TalkProvider.php` — id='talk', label='Chat', icon='ChatOutline', group='comms', requiredApp='spreed', storage='link-table'; injects Chat + Conversation services
- [ ] DI-tag in `Application.php`
- [ ] Unit test covering both service-delegation paths

## Frontend — Tab

- [ ] `CnTalkTab.vue` — active conversation view with compose box; collapsible conversation list; "Start conversation" CTA; "Open in Talk" link-out
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnTalkCard.vue`:
  - `user-dashboard`: "N unread across M conversations" headline
  - `app-dashboard`: scoped to app objects
  - `detail-page`: most recent conversation inline, unread badge
  - `single-entity`: chip with conversation name + unread indicator
- [ ] 30s polling on tab open for new messages
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/talk.js` — register with `referenceType: 'talk'`
- [ ] Wire + barrels

## Quality

- [ ] Parity gate passes
- [ ] nl + en translations
- [ ] PHPCS/PHPMD/PHPStan/Psalm strict pass
- [ ] ESLint clean

## Acceptance verification

- [ ] E2E: install Spreed, start a conversation on an object, send messages, verify in Talk app
- [ ] Unread badge appears/clears correctly across surfaces
- [ ] Hide test: disable Spreed → integration hidden
- [ ] Reference-property test
