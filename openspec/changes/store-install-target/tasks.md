# Tasks: store-install-target

## 1. The engine

- [x] 1.1 `StoreInstallTarget` reads `store.installTarget`; a malformed block declares none. Verify: `StoreInstallTargetTest`.
- [x] 1.2 `BundleObjectStamp` stamps both object lists of a bundle by `@self.schema`. Verify: `StoreInstallTargetTest`.
- [x] 1.3 `FederatedConfigService::install()` applies the stamp after the signature check. Verify: `FederatedConfigServiceTest::testAStampIsAppliedAfterTheSignatureCheck`.
- [x] 1.4 `GenericStoreController::install()` reads `target`, refuses an undeclared or malformed one with 400 after the posture check, and hands the stamp to the catalogue or the target to the installer. Verify: `GenericStoreControllerTest`.
- [x] 1.5 `store.installTarget` in `tests/schemas/app-manifest-v2.schema.json`.

## 2. Consumers

- [ ] 2.1 larpinq declares `installTarget: { property: "setting", schemas: [...] }` and passes its worlds as `config.targets` (larpinq admin-configuration-store 1.3).
