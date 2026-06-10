# Tasks: Integration — Polls

## Backend

- [~] `PollLink` entity + mapper + migration — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `PollService` wrapping Polls REST API — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `PollsController` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `PollsProvider` — id='polls', label='Polls', icon='Poll', group='workflow', requiredApp='polls', storage='link-table'
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnPollsTab.vue` — linked polls with status/tally/user-vote; link-existing + create-new — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnPollsCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: user's open polls across linked objects
  - `app-dashboard`: scoped
  - `detail-page`: tally + vote status
  - `single-entity`: chip with poll title + status
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/polls.js` — register with `referenceType: 'polls'` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire + barrels — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate passes; nl+en translations; PHPCS/PHPMD/PHPStan/Psalm strict; ESLint clean — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: install Polls, create poll from object, vote, verify in Polls app — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
