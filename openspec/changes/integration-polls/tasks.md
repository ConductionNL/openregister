# Tasks: Integration — Polls

## Backend

- [ ] `PollLink` entity + mapper + migration
- [ ] `PollService` wrapping Polls REST API
- [ ] `PollsController`
- [x] `PollsProvider` — id='polls', label='Polls', icon='Poll', group='workflow', requiredApp='polls', storage='link-table'
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnPollsTab.vue` — linked polls with status/tally/user-vote; link-existing + create-new
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnPollsCard.vue`:
  - `user-dashboard`: user's open polls across linked objects
  - `app-dashboard`: scoped
  - `detail-page`: tally + vote status
  - `single-entity`: chip with poll title + status
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/polls.js` — register with `referenceType: 'polls'`
- [ ] Wire + barrels

## Quality

- [ ] Parity gate passes; nl+en translations; PHPCS/PHPMD/PHPStan/Psalm strict; ESLint clean

## Acceptance verification

- [ ] E2E: install Polls, create poll from object, vote, verify in Polls app
- [ ] Hide test; reference-property test
