# Tasks: Integration — Cospend

## Backend

- [~] `CospendLink` entity + mapper + migration (with `link_type` = project|bill) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `CospendService` wrapping Cospend REST API — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `CospendController` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `CospendProvider` — id='cospend', label='Costs', icon='CurrencyEur', group='workflow', requiredApp='cospend', storage='link-table'
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnCospendTab.vue` — linked projects/bills with totals, link/unlink, click-through to Cospend — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnCospendCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: total spent across user's objects
  - `app-dashboard`: scoped
  - `detail-page`: total + per-bill list
  - `single-entity`: amount chip
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/cospend.js` — register with `referenceType: 'cospend'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: link a Cospend project, verify total displays; unlink — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Currency test: linked bills in multiple currencies render separately — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
