# Tasks: Integration — Shares

## Backend

- [ ] `ShareService` — walk object's linked files, query `IManager::getSharesBy()`, merge + deduplicate
- [ ] `SharesController` — list + revoke endpoints
- [ ] `SharesProvider` — id='shares', label='Shares', icon='Share', group='core', requiredApp=null, storage='query-time'; create/update throw NotImplemented; delete delegates to `IManager::deleteShare()`
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnSharesTab.vue` — aggregated share list, group-by (user/group/link/federated), revoke action
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnSharesCard.vue`:
  - `user-dashboard`: "Objects I've shared" count
  - `app-dashboard`: scoped
  - `detail-page`: share list by type
  - `single-entity`: share-type chip
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/shares.js` — register with `referenceType: 'shares'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: share an object's file via Files UI, verify tab shows share; revoke; verify removed
- [ ] Hide test: schema without `shares` in linkedTypes → no tab
- [ ] Reference-property test
