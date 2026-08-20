# Tasks: Integration — Shares

## Backend

- [x] `SharesProvider` — id='shares', label='Shares', icon='Share', group='core', requiredApp=null, storage='query-time'
- [x] Pivot from `MarkerLookupTrait`-on-`share.note` to `OCP\Share\IManager::getSharesBy()` per linked file (the proposal's acceptance criteria assume IManager; `share.note` is rarely populated in NC, so the legacy path returned empty in practice)
- [x] Lazy resolution: `IManager` / `FolderManagementHandler` / `IUserSession` pulled via `\OCP\Server::get()` so ctor signature `(IDBConnection, IAppManager, IL10N)` stays compatible with `Application.php` (Application.php untouched)
- [x] `health()` reports `ok` when IManager resolves, `degraded` otherwise — never throws
- [x] `delete()` delegates to `IManager::deleteShare()`; falls back to `NotImplementedException` when IManager is unreachable
- [x] PHPUnit test (`SharesProviderTest`) — metadata + happy-path list + folder-missing + IManager-unreachable + health-ok + health-degraded + delete-delegate + delete-fallback (8 tests, 32 assertions)
- [ ] DI-tag, routes — not in scope for this wave; consumed via the existing `/integrations/{id}` sub-resource

**Deferred (out of scope for this wave):**
- [ ] `ShareService` + `SharesController` REST surface — current path uses the umbrella sub-resource endpoint already in place; deferred to `openregister#1323` follow-up if a dedicated controller becomes necessary
- [ ] Federated-share negotiation edge cases — best-effort surfacing only

## Frontend — Tab

- [x] `CnSharesTab.vue` (in `nextcloud-vue`) — recipient list grouped by share type (user / group / link / federated), permissions label, expiry, password-protected indicator, revoke action via DELETE, "Manage in Files" deep-link
- [x] Jest test — empty / grouped / permissions / lock / revoke / 503 / error paths (7 tests)

## Frontend — Widget

- [x] `CnSharesCard.vue` (in `nextcloud-vue`) — 4-surface widget:
  - `user-dashboard` / `app-dashboard`: count headline split by type ("2 users · 1 groups · 1 links") + most-recent recipient
  - `detail-page`: grouped compact list (5 per group) with permissions
  - `single-entity`: chip with share-type icon + recipient label + lock indicator
- [x] Jest test — dashboard headline / empty / detail-page grouping / single-entity chip / single-entity empty / 503 / error paths (7 tests)

## Registration

- [x] `src/integrations/builtin/shares.js` (in `nextcloud-vue`) — exports `sharesIntegration` descriptor with `referenceType: 'shares'`, `order: 10`, `group: 'core'`, `requiredApp: null`. Consuming apps register it before the leaf factory drains (AD-13 first-wins keeps the bespoke pair over the generic leaf descriptor in `leaves.js`).

## Quality

- [x] Parity gate (`scripts/check-integration-parity.js`) — pass
- [x] ESLint — clean
- [x] SPDX in docblock (ADR-014)
- [x] nl + en — generic copy through `t()`; per-app translation files updated by the next l10n sweep
- [x] `openspec validate integration-shares --strict` — pass

## Acceptance verification

- [x] Backend pivot tested via PHPUnit mocks (folder walk + IManager dispatch + dedup)
- [ ] E2E manual: share an object's file via Files UI, verify tab shows share + recipient + lock; revoke; verify removed (manual smoke test pending — needs running OR install with shared files)
- [x] Hide test: schema without `shares` in linkedTypes → no tab (umbrella sub-resource behaviour, unchanged)
- [ ] Reference-property test — needs OC integration smoke (`referenceType: 'shares'` chip render)

## Notes

- Backend pivot was the genuine wave-4 transition: the audit caveat about `share.note` was correct, the original proposal's IManager assumption was the better contract. The provider now matches the proposal's acceptance criteria.
- nc-vue commit `7f2f348de6fb` carries the bespoke Vue components + descriptor + jest tests + a `jest.config.js` `testMatch` extension to pick up `src/**/__tests__/**/*.spec.js` (the wave-4 strict layout puts tests next to the components rather than under `tests/components/`).
- Application.php untouched per the wave brief; the new optional `ContainerInterface` ctor arg has a default, so the existing registration continues to wire `(db, appManager, l10n)` only.
