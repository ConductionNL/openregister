# Tasks: store-installed-in

## 1. The engine

- [x] 1.1 `StoreInstallLedger` records an install per app in IAppConfig, ids only, bounded. Verify: `StoreInstallLedgerTest`.
- [x] 1.2 `GenericStoreController::install()` records a whole install with its target; `search()` answers with `installed`. Verify: `GenericStoreControllerTest`, `GenericStoreControllerAccessGoldenTest`.

## 2. Consumers

- [ ] 2.1 nextcloud-vue `CnStorePage` reads `installed` and shows "Installed in" per card with the target labels (larpinq admin-configuration-store 1.3).
