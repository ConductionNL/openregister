# Tasks: Integration — Cospend

## Backend

- [ ] `CospendLink` entity + mapper + migration (with `link_type` = project|bill)
- [ ] `CospendService` wrapping Cospend REST API
- [ ] `CospendController`
- [x] `CospendProvider` — id='cospend', label='Costs', icon='CurrencyEur', group='workflow', requiredApp='cospend', storage='link-table'
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnCospendTab.vue` — linked projects/bills with totals, link/unlink, click-through to Cospend
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnCospendCard.vue`:
  - `user-dashboard`: total spent across user's objects
  - `app-dashboard`: scoped
  - `detail-page`: total + per-bill list
  - `single-entity`: amount chip
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/cospend.js` — register with `referenceType: 'cospend'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: link a Cospend project, verify total displays; unlink
- [ ] Currency test: linked bills in multiple currencies render separately
- [ ] Hide test; reference-property test
